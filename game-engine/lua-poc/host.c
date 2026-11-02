/*
 * Lua drones: a proof of concept (#380). Not wired into the engine.
 *
 * What a drone needs from a language, and how Lua does it here:
 * - a sandbox: each drone has its own lua_State with only the base (less
 *   load, loadfile, dofile, require), string, table and math libraries, and
 *   a memory cap (its own allocator counts every byte);
 * - metering: a count hook every HOOK_EVERY Lua VM instructions charges
 *   them, and ends the drone's slice (it yields from the hook) once it has
 *   used SLICE_INSTRUCTIONS, as the engine's 100,000-instruction slices do;
 * - pausing on an action: a game word that takes time (make, buy_here,
 *   sell_here, go, wait) yields from C; the host carries the action out and
 *   resumes the script with its result, so a script reads top to bottom;
 * - surviving a restart: Lua can't save a paused coroutine, so the drone's
 *   plain-data globals (numbers, strings, booleans and tables of them) are
 *   saved, and a restored drone reloads its script, gets them back and runs
 *   launched() again (as the engine restarts a FORTH drone from its
 *   release_vm after a crash).
 *
 * The world is a mock, enough for the Maker: metals for sale at the Moon,
 * bids for rods at the Moon and Earth, alloys and rods made from metals.
 *
 * Usage: host <script.lua> [drones] [game days]
 */
#define _POSIX_C_SOURCE 200809L
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#include "lua.h"
#include "lauxlib.h"
#include "lualib.h"
#include "lstate.h"   /* the count hook's counter, to charge a slice's last instructions exactly */

#define SLICE_INSTRUCTIONS 100000
#define HOOK_EVERY 1000
#define MEMORY_CAP (256 * 1024)
#define FUEL_PER_INSTRUCTIONS 100000   /* a fuel a slice's worth of thinking */

enum { MOON = 10, EARTH = 3 };
enum { FUEL = 2061, METALS_A1 = 2011, ALLOYS_A1 = 2021, RODS = 3011, NRES = 4 };
static const int RES[NRES] = { FUEL, METALS_A1, ALLOYS_A1, RODS };

typedef struct {
    int id;
    lua_State *L;          /* the drone's own state */
    lua_State *co;         /* the coroutine running launched() */
    size_t mem;            /* bytes allocated now */
    size_t mem_peak;
    long long instr;       /* Lua VM instructions run, all told */
    long long slice_used;  /* in this slice */
    long long credits;
    int hold[NRES];
    int place;
    long long wake_t;      /* game seconds: runnable again from */
    int pending;           /* the action it yielded on (0: preempted) */
    lua_Integer a1, a2;    /* its arguments */
    int dead;
    char error[200];
    long long sold_moon, sold_earth, made;
} Drone;

static long long now_t = 0;    /* game seconds */

/* ------------------------------------------------------------------ */
/* Memory: each drone's allocator counts its bytes and refuses past the cap */
static void *drone_alloc(void *ud, void *ptr, size_t osize, size_t nsize)
{
    Drone *d = ud;
    size_t old = ptr ? osize : 0;
    if(nsize == 0) {
        d->mem -= old;
        free(ptr);
        return NULL;
    }
    if(d->mem - old + nsize > MEMORY_CAP)
        return NULL;   /* Lua raises "not enough memory" in the script */
    void *p = realloc(ptr, nsize);
    if(p) {
        d->mem = d->mem - old + nsize;
        if(d->mem > d->mem_peak)
            d->mem_peak = d->mem;
    }
    return p;
}

static Drone *drone_of(lua_State *L)
{
    void *ud;
    lua_getallocf(L, &ud);
    return ud;
}

/* ------------------------------------------------------------------ */
/* Metering: charge instructions; end the slice when it is used up */
static void count_hook(lua_State *L, lua_Debug *ar)
{
    (void)ar;
    Drone *d = drone_of(L);
    d->instr += HOOK_EVERY;
    d->slice_used += HOOK_EVERY;
    if(d->slice_used >= SLICE_INSTRUCTIONS)
        lua_yield(L, 0);   /* preempted: the drone's turn is over */
}

/* ------------------------------------------------------------------ */
/* The mock world */
static int slot(int res)
{
    for(int i = 0; i < NRES; i++)
        if(RES[i] == res)
            return i;
    return -1;
}
static int moon_metal_price(void) { return 22; }
static int rod_bid(int place)     { return place == MOON ? 160 : 150; }
static int earth_board_rods(void) { return 140; }   /* what rods sold for at Earth yesterday */

/* Queries answer at once (a few instructions' worth) */
static int l_units(lua_State *L)
{
    Drone *d = drone_of(L);
    int s = slot((int)luaL_checkinteger(L, 1));
    lua_pushinteger(L, s < 0 ? 0 : d->hold[s]);
    return 1;
}
static int l_credits(lua_State *L) { lua_pushinteger(L, drone_of(L)->credits); return 1; }
static int l_here(lua_State *L)    { lua_pushinteger(L, drone_of(L)->place); return 1; }
static int l_sell_price(lua_State *L)
{
    Drone *d = drone_of(L);
    lua_Integer res = luaL_checkinteger(L, 1);
    lua_pushinteger(L, res == RODS ? rod_bid(d->place) : 0);
    return 1;
}
static int l_buy_price(lua_State *L)
{
    Drone *d = drone_of(L);
    lua_Integer res = luaL_checkinteger(L, 1);
    lua_pushinteger(L, res == METALS_A1 && d->place == MOON ? moon_metal_price() :
                       res == FUEL ? 5 : 0);
    return 1;
}
static int l_board_price(lua_State *L)
{
    lua_Integer res = luaL_checkinteger(L, 1), place = luaL_checkinteger(L, 2);
    lua_pushinteger(L, res == RODS && place == EARTH ? earth_board_rods() : 0);
    return 1;
}

/* Actions yield: the host carries them out and resumes with the result */
enum { A_MAKE = 1, A_BUY, A_SELL, A_GO, A_WAIT };
static int action(lua_State *L, int kind, int nargs)
{
    Drone *d = drone_of(L);
    d->pending = kind;
    d->a1 = nargs > 0 ? luaL_checkinteger(L, 1) : 0;
    d->a2 = nargs > 1 ? luaL_checkinteger(L, 2) : 0;
    return lua_yield(L, 0);
}
static int l_make(lua_State *L)      { return action(L, A_MAKE, 1); }
static int l_buy_here(lua_State *L)  { return action(L, A_BUY, 2); }
static int l_sell_here(lua_State *L) { return action(L, A_SELL, 2); }
static int l_go(lua_State *L)        { return action(L, A_GO, 1); }
static int l_wait(lua_State *L)      { return action(L, A_WAIT, 1); }

/* What an action does, how long it takes; leaves its result in *ok */
static long long carry_out(Drone *d, int *ok)
{
    int f = slot(FUEL);
    *ok = 1;
    if(d->pending != A_WAIT) {
        if(d->hold[f] < 1) { *ok = 0; return 60; }
        d->hold[f]--;   /* every action costs a fuel */
    }
    switch(d->pending) {
    case A_MAKE:
        if(d->a1 == ALLOYS_A1 && d->hold[slot(METALS_A1)] >= 2) {
            d->hold[slot(METALS_A1)] -= 2; d->hold[slot(ALLOYS_A1)]++; return 2 * 3600;
        }
        if(d->a1 == RODS && d->hold[slot(ALLOYS_A1)] >= 1) {
            d->hold[slot(ALLOYS_A1)]--; d->hold[slot(RODS)]++; d->made++; return 3 * 3600;
        }
        *ok = 0; return 60;
    case A_BUY: {
        int s = slot((int)d->a2);
        int price = d->a2 == FUEL ? 5 : d->a2 == METALS_A1 && d->place == MOON ? moon_metal_price() : 0;
        long long n = d->a1;
        if(s < 0 || price == 0) { *ok = 0; return 60; }
        if(n * price > d->credits) n = d->credits / price;
        d->hold[s] += (int)n; d->credits -= n * price;
        *ok = n > 0; return 600;
    }
    case A_SELL: {
        int s = slot((int)d->a2);
        long long n = d->a1;
        if(s < 0 || d->a2 != RODS) { *ok = 0; return 60; }
        if(n > d->hold[s]) n = d->hold[s];
        d->hold[s] -= (int)n; d->credits += n * rod_bid(d->place) * 95 / 100;
        if(d->place == MOON) d->sold_moon += n; else d->sold_earth += n;
        *ok = n > 0; return 600;
    }
    case A_GO:
        d->place = (int)d->a1; return 3 * 86400;
    case A_WAIT:
        return d->a1 * 60;
    }
    *ok = 0;
    return 60;
}

/* ------------------------------------------------------------------ */
/* A drone: its state, sandbox, game words and script */
static int l_print(lua_State *L) { (void)L; return 0; }   /* reports go to the drone page; here, nowhere */

static void open_sandbox(lua_State *L)
{
    luaL_requiref(L, LUA_GNAME, luaopen_base, 1);
    luaL_requiref(L, LUA_STRLIBNAME, luaopen_string, 1);
    luaL_requiref(L, LUA_TABLIBNAME, luaopen_table, 1);
    luaL_requiref(L, LUA_MATHLIBNAME, luaopen_math, 1);
    lua_pop(L, 4);
    const char *gone[] = { "load", "loadfile", "dofile", "require", "collectgarbage", NULL };
    for(int i = 0; gone[i]; i++) {
        lua_pushnil(L);
        lua_setglobal(L, gone[i]);
    }
    const luaL_Reg words[] = {
        { "units", l_units }, { "credits", l_credits }, { "here", l_here },
        { "sell_price", l_sell_price }, { "buy_price", l_buy_price }, { "board_price", l_board_price },
        { "make", l_make }, { "buy_here", l_buy_here }, { "sell_here", l_sell_here },
        { "go", l_go }, { "wait", l_wait }, { "print", l_print }, { NULL, NULL } };
    for(int i = 0; words[i].name; i++) {
        lua_pushcfunction(L, words[i].func);
        lua_setglobal(L, words[i].name);
    }
    const struct { const char *n; int v; } names[] = {
        { "fuel", FUEL }, { "metals_a1", METALS_A1 }, { "alloys_a1", ALLOYS_A1 }, { "rods", RODS },
        { "moon", MOON }, { "earth", EARTH }, { NULL, 0 } };
    for(int i = 0; names[i].n; i++) {
        lua_pushinteger(L, names[i].v);
        lua_setglobal(L, names[i].n);
    }
}

/* The names a drone starts with, so a snapshot saves only its own */
static int is_builtin(const char *name)
{
    static const char *b[] = { "units", "credits", "here", "sell_price", "buy_price", "board_price",
        "make", "buy_here", "sell_here", "go", "wait", "print", "fuel", "metals_a1", "alloys_a1",
        "rods", "moon", "earth", NULL };
    for(int i = 0; b[i]; i++)
        if(!strcmp(b[i], name))
            return 1;
    return 0;
}

static const char *script_text;
static size_t script_len;

/* Loads the script (its top level runs: settings and functions), then
   starts launched() in a coroutine. Loading is metered like running. */
static int drone_start(Drone *d)
{
    d->L = lua_newstate(drone_alloc, d);
    if(!d->L)
        return -1;
    open_sandbox(d->L);
    if(luaL_loadbuffer(d->L, script_text, script_len, "=script") != LUA_OK ||
       lua_pcall(d->L, 0, 0, 0) != LUA_OK) {
        snprintf(d->error, sizeof d->error, "%s", lua_tostring(d->L, -1));
        return -1;
    }
    d->co = lua_newthread(d->L);
    lua_setfield(d->L, LUA_REGISTRYINDEX, "drone_thread");   /* keep it alive */
    lua_sethook(d->co, count_hook, LUA_MASKCOUNT, HOOK_EVERY);
    lua_getglobal(d->co, "launched");
    if(!lua_isfunction(d->co, -1)) {
        snprintf(d->error, sizeof d->error, "no launched function");
        return -1;
    }
    return 0;
}

/* One slice: resume until it yields (an action or preemption) or ends */
static int slice(Drone *d, int with_result, int ok)
{
    int nres = 0, nargs = 0;
    d->slice_used = 0;
    if(with_result) {
        lua_pushboolean(d->co, ok);
        nargs = 1;
    }
    lua_sethook(d->co, count_hook, LUA_MASKCOUNT, HOOK_EVERY);   /* restarts the count */
    int r = lua_resume(d->co, d->L, nargs, &nres);
    /* The instructions since the hook last fired */
    long long tail = d->co->basehookcount - d->co->hookcount;
    if(tail > 0 && tail < HOOK_EVERY) { d->instr += tail; d->slice_used += tail; }
    if(r != LUA_OK && r != LUA_YIELD) {   /* the error object is on top */
        snprintf(d->error, sizeof d->error, "%s", lua_tostring(d->co, -1));
        d->dead = 1;
        return -1;
    }
    lua_pop(d->co, nres);
    if(r == LUA_YIELD)
        return 0;
    d->dead = 1;   /* ended, or failed */
    return -1;
}

/* ------------------------------------------------------------------ */
/* Saving a drone: its plain-data globals, as Lua source. Functions,
   threads and the game words are left out; cycles end the table. */
static void save_value(lua_State *L, int idx, luaL_Buffer *b, int depth)
{
    idx = lua_absindex(L, idx);
    switch(lua_type(L, idx)) {
    case LUA_TNUMBER:
        if(lua_isinteger(L, idx)) { lua_pushfstring(L, "%I", lua_tointeger(L, idx)); }
        else { char n[40]; snprintf(n, sizeof n, "%.17g", lua_tonumber(L, idx)); lua_pushstring(L, n); }
        luaL_addvalue(b);
        break;
    case LUA_TBOOLEAN:
        luaL_addstring(b, lua_toboolean(L, idx) ? "true" : "false");
        break;
    case LUA_TSTRING: {
        lua_getglobal(L, "string");
        lua_getfield(L, -1, "format");
        lua_pushstring(L, "%q");
        lua_pushvalue(L, idx);
        lua_call(L, 2, 1);
        lua_remove(L, -2);
        luaL_addvalue(b);
        break;
    }
    case LUA_TTABLE:
        if(depth > 8) { luaL_addstring(b, "nil"); break; }
        luaL_addstring(b, "{");
        lua_pushnil(L);
        while(lua_next(L, idx)) {
            int kt = lua_type(L, -2), vt = lua_type(L, -1);
            if((kt == LUA_TNUMBER || kt == LUA_TSTRING) &&
               (vt == LUA_TNUMBER || vt == LUA_TSTRING || vt == LUA_TBOOLEAN || vt == LUA_TTABLE)) {
                luaL_addstring(b, "[");
                save_value(L, -2, b, depth + 1);
                luaL_addstring(b, "]=");
                save_value(L, -1, b, depth + 1);
                luaL_addstring(b, ",");
            }
            lua_pop(L, 1);
        }
        luaL_addstring(b, "}");
        break;
    default:
        luaL_addstring(b, "nil");
    }
}

/* The snapshot, built inside a protected call: a drone at its memory cap
   fails to save (and keeps its last snapshot) instead of taking the host
   down */
static int save_globals(lua_State *L)
{
    luaL_Buffer b;
    luaL_buffinit(L, &b);
    luaL_addstring(&b, "return {");
    lua_pushglobaltable(L);
    lua_pushnil(L);
    while(lua_next(L, -2)) {
        int vt = lua_type(L, -1);
        if(lua_type(L, -2) == LUA_TSTRING && !is_builtin(lua_tostring(L, -2)) &&
           (vt == LUA_TNUMBER || vt == LUA_TSTRING || vt == LUA_TBOOLEAN || vt == LUA_TTABLE) &&
           strcmp(lua_tostring(L, -2), "_G") && strcmp(lua_tostring(L, -2), "string") &&
           strcmp(lua_tostring(L, -2), "table") && strcmp(lua_tostring(L, -2), "math")) {
            luaL_addstring(&b, "[");
            save_value(L, -2, &b, 0);
            luaL_addstring(&b, "]=");
            save_value(L, -1, &b, 0);
            luaL_addstring(&b, ",");
        }
        lua_pop(L, 1);
    }
    lua_pop(L, 1);
    luaL_addstring(&b, "}");
    luaL_pushresult(&b);
    return 1;
}

/* The snapshot as a malloc'd string: "return {name=value,...}"; NULL if it
   couldn't be made */
static char *drone_save(Drone *d, size_t *len)
{
    lua_State *L = d->L;
    lua_pushcfunction(L, save_globals);
    if(lua_pcall(L, 0, 1, 0) != LUA_OK) {
        lua_pop(L, 1);
        return NULL;
    }
    size_t n;
    const char *s = lua_tolstring(L, -1, &n);
    char *copy = malloc(n + 1);
    memcpy(copy, s, n + 1);
    lua_pop(L, 1);
    *len = n;
    return copy;
}

/* A restored drone: the script loaded afresh, its saved globals put
   back, and launched() started again */
static int drone_restore(Drone *d, const char *saved, size_t len)
{
    if(drone_start(d) < 0)
        return -1;
    if(luaL_loadbuffer(d->L, saved, len, "=snapshot") != LUA_OK || lua_pcall(d->L, 0, 1, 0) != LUA_OK)
        return -1;
    lua_pushglobaltable(d->L);            /* snapshot, _G */
    lua_pushnil(d->L);
    while(lua_next(d->L, -3)) {           /* key, value */
        lua_pushvalue(d->L, -2);          /* key, value, key */
        lua_insert(d->L, -2);             /* key, key, value */
        lua_settable(d->L, -4);           /* _G[key] = value */
    }
    lua_pop(d->L, 2);
    return 0;
}

/* ------------------------------------------------------------------ */
static double secs(void)
{
    struct timespec t;
    clock_gettime(CLOCK_MONOTONIC, &t);
    return t.tv_sec + t.tv_nsec / 1e9;
}

static char *read_file(const char *path, size_t *len)
{
    FILE *f = fopen(path, "rb");
    if(!f) return NULL;
    fseek(f, 0, SEEK_END);
    long n = ftell(f);
    fseek(f, 0, SEEK_SET);
    char *s = malloc(n + 1);
    if(fread(s, 1, n, f) != (size_t)n) { fclose(f); free(s); return NULL; }
    s[n] = 0;
    fclose(f);
    *len = n;
    return s;
}

int main(int argc, char **argv)
{
    if(argc < 2) {
        fprintf(stderr, "usage: host <script.lua> [drones] [game days]\n");
        return 2;
    }
    script_text = read_file(argv[1], &script_len);
    if(!script_text) { perror(argv[1]); return 2; }
    int n = argc > 2 ? atoi(argv[2]) : 100;
    int days = argc > 3 ? atoi(argv[3]) : 10;
    Drone *ds = calloc(n, sizeof *ds);

    /* Load every drone (its script's top level), metered */
    double t0 = secs();
    for(int i = 0; i < n; i++) {
        Drone *d = &ds[i];
        d->id = i + 1; d->credits = 1000; d->hold[slot(FUEL)] = 100; d->place = EARTH;
        if(drone_start(d) < 0) { fprintf(stderr, "drone %d: %s\n", d->id, d->error); return 1; }
    }
    double load_s = secs() - t0;
    size_t mem0 = 0;
    for(int i = 0; i < n; i++) mem0 += ds[i].mem;

    /* Run: round robin over the drones that are due, a slice each; the game
       clock jumps to the next wake-up when nobody is due. An action takes
       effect when the drone asks for it; the drone is resumed with its
       result once the action's time is up. */
    long long slices = 0, preempted = 0, actions = 0;
    double run_t = 0, worst = 0;
    long long end_t = (long long)days * 86400;
    int *result = calloc(n, sizeof *result);   /* -1: nothing to hand back */
    for(int i = 0; i < n; i++) result[i] = -1;
    while(now_t < end_t) {
        int ran = 0;
        long long next = end_t;
        for(int i = 0; i < n; i++) {
            Drone *d = &ds[i];
            if(d->dead) continue;
            if(d->wake_t > now_t) { if(d->wake_t < next) next = d->wake_t; continue; }
            double s0 = secs();
            int r = slice(d, result[i] >= 0, result[i] > 0);
            double dt = secs() - s0;
            run_t += dt; if(dt > worst) worst = dt;
            slices++; ran++;
            result[i] = -1;
            /* thinking costs fuel too: a fuel a full slice */
            if(d->slice_used >= FUEL_PER_INSTRUCTIONS && d->hold[slot(FUEL)] > 0) d->hold[slot(FUEL)]--;
            if(r < 0) continue;
            if(d->pending) {
                int ok;
                d->wake_t = now_t + carry_out(d, &ok);
                result[i] = ok;
                d->pending = 0;
                actions++;
            } else {
                preempted++;   /* used its slice up: runnable again at once */
            }
        }
        if(!ran) now_t = next;
    }
    double total = secs() - t0;

    /* Save every drone, and restore one */
    double s0 = secs();
    size_t saved_bytes = 0, saved_max = 0, unsaved = 0;
    char *one = NULL; size_t one_len = 0;
    for(int i = 0; i < n; i++) {
        size_t len;
        char *s = drone_save(&ds[i], &len);
        if(!s) { unsaved++; continue; }
        saved_bytes += len; if(len > saved_max) saved_max = len;
        if(i == 0) { one = s; one_len = len; } else free(s);
    }
    double save_s = secs() - s0;
    Drone back = { .id = 1, .credits = ds[0].credits, .place = ds[0].place };
    memcpy(back.hold, ds[0].hold, sizeof back.hold);
    s0 = secs();
    int rr = one ? drone_restore(&back, one, one_len) : -1;
    double restore_s = secs() - s0;

    long long instr = 0, dead = 0, moon = 0, earth = 0, made = 0, cr = 0;
    size_t mem = 0, peak = 0;
    for(int i = 0; i < n; i++) {
        instr += ds[i].instr; dead += ds[i].dead; moon += ds[i].sold_moon; earth += ds[i].sold_earth;
        made += ds[i].made; cr += ds[i].credits; mem += ds[i].mem; if(ds[i].mem_peak > peak) peak = ds[i].mem_peak;
        if(ds[i].dead && i < 3) fprintf(stderr, "drone %d stopped: %s\n", ds[i].id, ds[i].error);
    }
    printf("drones %d, game days %d\n", n, days);
    printf("load: %.3f ms a drone, %zu bytes a drone after loading\n", load_s * 1000 / n, mem0 / n);
    printf("run: %lld slices (%lld on an action, %lld used up), %lld Lua instructions\n", slices, actions, preempted, instr);
    printf("time in drones: %.3f s, %.2f us a slice, worst %.0f us; %.1f M Lua instructions a second\n",
           run_t, run_t * 1e6 / (slices ? slices : 1), worst * 1e6, instr / (run_t > 0 ? run_t : 1) / 1e6);
    printf("memory: %zu bytes a drone now, %zu at most (cap %d)\n", mem / n, peak, MEMORY_CAP);
    printf("save: %.1f us a drone, %zu bytes a drone (largest %zu), %zu couldn't be saved; restore one: %.1f us (%s)\n",
           save_s * 1e6 / n, saved_bytes / n, saved_max, unsaved, restore_s * 1e6, rr == 0 ? "ok" : "not restored");
    printf("economy: %lld rods made, %lld sold at the Moon, %lld at Earth; %lld credits a drone; %lld stopped\n",
           made, moon, earth, cr / n, dead);
    printf("wall: %.2f s\n", total);
    return 0;
}
