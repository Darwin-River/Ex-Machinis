/*
 * lua-drive (#1007): a test drive of a drone's Lua script. It runs the
 * script read-only against a snapshot of the world as it is now, with the
 * drone's actions stubbed, and prints what the drone would do. No engine,
 * no database: only the script's own stubbed actions change the snapshot.
 *
 *   lua-drive <library.lua> <script.lua> <snapshot.lua>
 *
 * The library is the drones' real standard library (lua_library.lua), as
 * test_lua_equip.c loads it: its words (go, mine, buy_here, units, wait...)
 * are Lua on top of perform(protocol, ...) and query(id, ...), so stubbing
 * those two (and the few other built-ins of lua_drone.c) drives all of it.
 *
 * snapshot.lua returns a table the website writes:
 *   drone     = {id, company, location, credits, holdings = {[resource] = units}}
 *   now, epoch  the game time (seconds) and the world's day 0
 *   protocols = {[id] = {seconds, inputs = {[r] = n}, outputs = {[r] = n},
 *                travel, wait, multiplier, mined = {[r] = true},
 *                tools = {[r] = true}, params = {{k, n, out}}}}
 *               (params: an effect whose resource is the k-th parameter
 *               from the top of perform's arguments, as the engine reads
 *               a negative resource_effects.resource)
 *   masses    = {[resource] = unit mass}
 *   here      = {[query id] = true}: queries that read "here" (the drone's
 *               place); asked at another place, their key starts @place|
 *   answers   = {[key] = rows, or a negative code}: key is the query's id
 *               and its arguments joined by |, e.g. "212|5001"; rows as
 *               l_query returns them to Lua (each row its values in order,
 *               and by name, a d_ column without its d_)
 *
 * The drone's holdings, credits, place, cargo mass and the clock are an
 * overlay the stubs keep: units(), credits(), location(), hold(),
 * cargo_mass(), now() and today() read it, so they see what the drive's own
 * actions did. Order books (600, 601, 610, 611, 612) are lowered by what
 * the drive bought or sold from each order, and deposits here (640) by what
 * it mined.
 *
 * Standard output, one line each, fields separated by tabs:
 *   action <t> <protocol or word> <args> <result> <seconds> <credits> <changes>
 *       t: game seconds since the drive began; args separated by spaces;
 *       result: 0, or the negative code perform would return; credits: the
 *       company's credits change; changes: "resource:+n resource:-n ..."
 *   say <text>               what it printed
 *   status <text>            set_status
 *   need [@place] <id> <args...>   a query the snapshot doesn't answer: the
 *       website answers it (at that place, for @place) and runs it again
 *   error <message>          the script failed (with its line)
 *   end <why>                finished | actions | day | instructions
 *   memory <peak> <cap>      the most the drone's Lua held (library, script
 *       and data, in bytes) and its cap; once the library has loaded, just
 *       before the end or error line
 *
 * Limits: MAX_ACTIONS actions, a game day of drive time, MAX_INSTRUCTIONS
 * Lua instructions, and a drone's memory (#1077): the library, the script
 * and its data may hold LUA_MEMORY_CAP, as in the engine (lua_drone_alloc),
 * so a script that runs out of memory on a drone runs out here too, with
 * the same "not enough memory". What the drive itself holds (the snapshot
 * and its stubs) doesn't count. Until #1077 the drive allowed 32 MB, and
 * such a script passed it. math.random is seeded with a fixed
 * value, so a drive run again does the same. The sandbox is the drone's:
 * no io, os, load, require, debug or math.randomseed.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "lua.h"
#include "lauxlib.h"
#include "lualib.h"
#include "lua_limits.h"

#define MAX_INSTRUCTIONS 50000000L
#define MAX_MEMORY (32L * 1024 * 1024)   /* the drive's own ceiling, snapshot included */
#define HOOK_EVERY 1000
#define RANDOM_SEED 1007

static long instructions = 0;
static size_t in_use = 0;
/* What the drive's harness holds (the snapshot and the stubs): set once
   they are loaded, then the drone's cap applies to the rest (#1077) */
static size_t harness = 0;
static int capped = 0;
static size_t peak = 0;
static char halted[32] = "";   /* why the drive stopped: need, actions, day, instructions */

/* A Lua allocator with a ceiling */
static void *limited_alloc(void *ud, void *ptr, size_t osize, size_t nsize)
{
    (void)ud;
    if(ptr == NULL) osize = 0;
    if(nsize == 0) {
        free(ptr);
        in_use -= osize;
        return NULL;
    }
    if(nsize > osize && in_use + (nsize - osize) > (size_t)MAX_MEMORY)
        return NULL;
    if(capped && nsize > osize && in_use + (nsize - osize) > harness + (size_t)LUA_MEMORY_CAP)
        return NULL;   /* Lua raises "not enough memory", as on a drone */
    void *p = realloc(ptr, nsize);
    if(p) {
        in_use = in_use - osize + nsize;
        if(capped && in_use > harness && in_use - harness > peak) peak = in_use - harness;
    }
    return p;
}

/* Counts instructions; once the drive has stopped, a script that caught the
   stop in a pcall is stopped again */
static void count_hook(lua_State *L, lua_Debug *ar)
{
    (void)ar;
    instructions += HOOK_EVERY;
    if(halted[0])
        luaL_error(L, "the test drive stopped");
    if(instructions > MAX_INSTRUCTIONS) {
        snprintf(halted, sizeof(halted), "instructions");
        luaL_error(L, "the script used more than %d million instructions", (int)(MAX_INSTRUCTIONS / 1000000));
    }
}

/* halt(why): the drive stops (the Lua side then raises an error) */
static int halt(lua_State *L)
{
    snprintf(halted, sizeof(halted), "%s", luaL_checkstring(L, 1));
    return 0;
}

/* emit(...): one output line, tabs and newlines in fields turned to spaces */
static int emit(lua_State *L)
{
    int n = lua_gettop(L);
    for(int i = 1; i <= n; i++) {
        size_t len;
        const char *s = luaL_tolstring(L, i, &len);
        for(size_t k = 0; k < len; k++) {
            char c = s[k];
            putchar(c == '\t' || c == '\n' || c == '\r' ? ' ' : c);
        }
        lua_pop(L, 1);
        putchar(i < n ? '\t' : '\n');
    }
    if(n == 0) putchar('\n');
    return 0;
}

static char *slurp(const char *path, size_t *len)
{
    FILE *f = fopen(path, "rb");
    if(!f) return NULL;
    fseek(f, 0, SEEK_END);
    long size = ftell(f);
    fseek(f, 0, SEEK_SET);
    if(size < 0 || size > 16 * 1024 * 1024) { fclose(f); return NULL; }
    char *buf = malloc((size_t)size + 1);
    if(!buf) { fclose(f); return NULL; }
    size_t got = fread(buf, 1, (size_t)size, f);
    buf[got] = 0;
    fclose(f);
    if(len) *len = got;
    return buf;
}

/* The trusted side, in Lua: the stubs for the drone's built-ins, set as
   globals before the library loads. Its arguments: emit, halt and the
   snapshot table */
static const char *PRELUDE =
"local emit, halt, snap = ...\n"
"local MAX_ACTIONS, DAY, FUEL = 50, 86400, 2061\n"
"local PUSH, PULL, DELIVER = 10, 11, 37\n"
"local drone = snap.drone or {}\n"
"local me, company = drone.id or 0, drone.company or 0\n"
"local start, loc = drone.location or 0, drone.location or 0\n"
"local credits = drone.credits or 0\n"
"local hold = {}\n"
"for r, n in pairs(drone.holdings or {}) do hold[r] = n end\n"
"local answers, protocols, here, masses = snap.answers or {}, snap.protocols or {}, snap.here or {}, snap.masses or {}\n"
"local now, epoch = snap.now or 0, snap.epoch or 0\n"
"local clock, actions, stopped = 0, 0, false\n"
"local orders, traded, mined = {}, {}, {}\n"
"local STOP = setmetatable({}, {__tostring = function() return 'the test drive stopped' end})\n"
"local function stop(why) stopped = true; halt(why); error(STOP, 0) end\n"
"local function check() if stopped then error(STOP, 0) end end\n"
"local function copy(v)\n"
"  if type(v) ~= 'table' then return v end\n"
"  local t = {}\n"
"  for k, x in pairs(v) do t[k] = copy(x) end\n"
"  return t\n"
"end\n"
"local function arg_text(v)\n"
"  if math.type(v) == 'float' and math.tointeger(v) then v = math.tointeger(v) end\n"
"  return tostring(v)\n"
"end\n"
// A query's answer from the snapshot, or a need line and a stop
"local function lookup(id, args)\n"
"  local parts = {tostring(id)}\n"
"  for i = 1, #args do parts[#parts + 1] = arg_text(args[i]) end\n"
"  if here[id] and loc ~= start then table.insert(parts, 1, '@' .. loc) end\n"
"  local key = table.concat(parts, '|')\n"
"  local a = answers[key]\n"
"  if a == nil and #parts == 1 then a = answers[id] end   -- a query with no arguments may be keyed by its number\n"
"  if a == nil then emit('need', table.unpack(parts)); stop('need') end\n"
"  if type(a) == 'number' then return nil, a end\n"
"  return copy(a)\n"
"end\n"
"local function row(...) return {...} end\n"
"local function named(t, names) for i, n in ipairs(names) do t[n] = t[i] end return t end\n"
"local BOOKS = {[600] = 'buy', [601] = 'buy', [610] = 'sell', [611] = 'sell', [612] = 'sell'}\n"
// An order book: its handles noted (for push and pull), lowered by what the drive took
"local function book(id, rows)\n"
"  local side = BOOKS[id]\n"
"  for _, o in ipairs(rows) do\n"
"    local handle, price = o[4], o[1]\n"
"    if math.type(handle) == 'integer' and type(price) == 'number' then\n"
"      orders[handle] = {price = price, side = side}\n"
"      local took = traded[handle] or 0\n"
"      if took ~= 0 and type(o[3]) == 'number' then\n"
"        o[3] = side == 'sell' and o[3] - took or o[3] + took\n"
"        o.units = o[3]\n"
"      end\n"
"    end\n"
"  end\n"
"  return rows\n"
"end\n"
"local function mass()\n"
"  local m = 0\n"
"  for r, n in pairs(hold) do m = m + n * (masses[r] or 0) end\n"
"  return m\n"
"end\n"
"local function whole(v, what)\n"
"  local n = math.tointeger(v)\n"
"  if not n then error(what .. ': a number was expected', 3) end\n"
"  return n\n"
"end\n"
"function query(id, ...)\n"
"  check()\n"
"  id = whole(id, 'query')\n"
"  local args = {...}\n"
"  for i, v in ipairs(args) do\n"
"    if type(v) == 'string' then\n"
"      if v:find('[^%w %-_%.]') then error('query: a string may hold only letters, digits, spaces and - _ .', 2) end\n"
"    elseif not math.tointeger(v) then error('query: argument ' .. i .. ' must be a whole number or a string', 2) end\n"
"  end\n"
"  local a1 = math.tointeger(args[1])\n"
"  if id == 205 then return {named(row(me), {'agent_id'})} end\n"
"  if id == 212 and a1 then return {row(hold[a1] or 0)} end\n"
"  if id == 202 and a1 == me then return {named(row(loc), {'object_id'})} end\n"
"  if id == 201 and a1 == me then return {named(row(company), {'company_id'})} end\n"
"  if id == 101 and a1 == company then return {named(row(credits), {'credits'})} end\n"
"  if id == 204 and a1 == me then return {row(mass())} end\n"
"  if id == 207 then\n"
"    local s = now + clock - epoch\n"
"    return {named(row(s // 86400, (s % 86400) // 60), {'day', 'minute'})}\n"
"  end\n"
"  if id == 210 and a1 == me then\n"
"    local rows = {}\n"
"    for r, n in pairs(hold) do if n > 0 then rows[#rows + 1] = named(row(r, n), {'resource', 'units'}) end end\n"
"    table.sort(rows, function(a, b) if a[2] ~= b[2] then return a[2] > b[2] end return a[1] < b[1] end)\n"
"    return rows\n"
"  end\n"
"  local rows, code = lookup(id, args)\n"
"  if not rows then return nil, code end\n"
"  if BOOKS[id] then return book(id, rows) end\n"
"  if id == 640 then\n"
"    for _, d in ipairs(rows) do\n"
"      local took = mined[loc .. ':' .. tostring(d[1])] or 0\n"
"      if took > 0 and type(d[2]) == 'number' then d[2] = math.max(0, d[2] - took); d.units = d[2] end\n"
"    end\n"
"  end\n"
"  return rows\n"
"end\n"
// What a run of mineral r yields here now: query 411's yield, at most what
// the deposit here has left
"local function yield(r)\n"
"  local rows = lookup(411, {loc, r})\n"
"  local y = rows and rows[1] and (rows[1].yield or rows[1][3]) or 0\n"
"  local deposits = lookup(640, {})\n"
"  for _, d in ipairs(deposits or {}) do\n"
"    if d[1] == r and type(d[2]) == 'number' then\n"
"      y = math.min(y, math.max(0, d[2] - (mined[loc .. ':' .. r] or 0)))\n"
"    end\n"
"  end\n"
"  return math.max(0, math.floor(y))\n"
"end\n"
"local function record(t, what, args, result, seconds, cr, changes)\n"
"  actions = actions + 1\n"
"  local shown = {}\n"
"  for i, v in ipairs(args) do shown[i] = arg_text(v) end\n"
"  emit('action', t, what, table.concat(shown, ' '), result, seconds, cr, changes)\n"
"end\n"
"local function limits()\n"
"  if actions >= MAX_ACTIONS then stop('actions') end\n"
"  if clock >= DAY then stop('day') end\n"
"end\n"
"function perform(p, ...)\n"
"  check()\n"
"  limits()\n"
"  p = whole(p, 'perform')\n"
"  local args = {...}\n"
"  for i, v in ipairs(args) do\n"
"    if type(v) ~= 'string' and not math.tointeger(v) then error('perform: argument ' .. i .. ' must be a whole number', 2) end\n"
"    if type(v) ~= 'string' then args[i] = math.tointeger(v) end\n"
"  end\n"
"  local proto = protocols[p]\n"
"  if p == DELIVER then return -12 end   -- only deliver(id, n) performs it, as in the engine\n"
"  if not proto then record(clock, p, args, -11, 0, 0, ''); limits(); return -11 end\n"
"  local n, params = 1, args\n"
"  if proto.multiplier then\n"
"    n = args[1] or 0\n"
"    params = {table.unpack(args, 2)}\n"
"  end\n"
"  local function param(k) return params[#params - k + 1] end\n"
"  local delta, result, seconds, cr = {}, 0, proto.seconds or 0, 0\n"
"  local function add(r, q) if r then delta[r] = (delta[r] or 0) + q end end\n"
// A tool it lacks (and no output of this protocol brings): refused before anything is worked out
"  for r in pairs(proto.tools or {}) do\n"
"    if (hold[r] or 0) < 1 and not (proto.outputs or {})[r] then result = -2 end\n"
"  end\n"
"  for r, q in pairs(proto.inputs or {}) do add(r, -(r == FUEL and q or q * n)) end\n"
"  for r, q in pairs(proto.outputs or {}) do\n"
"    if (proto.mined or {})[r] then add(r, result == 0 and q * yield(r) or 0) else add(r, r == FUEL and q or q * n) end\n"
"  end\n"
"  for _, e in ipairs(proto.params or {}) do\n"
"    local r = math.tointeger(param(e.k))\n"
"    local q = e.n >= 0 and e.n or (math.tointeger(param(-e.n)) or 0)\n"
"    if r and r ~= FUEL then q = q * n end\n"
"    add(r, e.out and q or -q)\n"
"  end\n"
"  local to = nil\n"
"  if proto.wait then seconds = math.max(0, math.min(args[1] or 0, 10080)) * 60 end\n"
"  if proto.travel and result == 0 then\n"
"    to = math.tointeger(args[#args])\n"
"    local rows = to and lookup(705, {loc, to}) or nil\n"
"    if not rows or not rows[1] then result = -12\n"
"    else seconds = rows[1].seconds or rows[1][2] or seconds end\n"
"  end\n"
// Trades with an order the drive has seen: credits change hands at its price
"  local handle = (p == PUSH or p == PULL) and math.tointeger(param(1)) or nil\n"
"  local o = handle and orders[handle]\n"
"  if o and p == PULL and o.side == 'sell' then cr = -o.price * n\n"
"  elseif o and p == PUSH and o.side == 'buy' then cr = o.price * n end\n"
"  if result == 0 then\n"
"    for r, q in pairs(delta) do\n"
"      if q < 0 and (hold[r] or 0) + q < 0 then result = -2 end\n"
"    end\n"
"    if cr < 0 and credits + cr < 0 then result = -4 end\n"
"  end\n"
"  local t = clock\n"
"  local changes = {}\n"
"  if result == 0 then\n"
"    local keys = {}\n"
"    for r in pairs(delta) do keys[#keys + 1] = r end\n"
"    table.sort(keys)\n"
"    for _, r in ipairs(keys) do\n"
"      local q = delta[r]\n"
"      if q ~= 0 then\n"
"        hold[r] = (hold[r] or 0) + q\n"
"        changes[#changes + 1] = r .. ':' .. (q > 0 and '+' or '') .. q\n"
"        if q > 0 and (proto.mined or {})[r] then mined[loc .. ':' .. r] = (mined[loc .. ':' .. r] or 0) + q end\n"
"      end\n"
"    end\n"
"    credits = credits + cr\n"
"    if o then traded[handle] = (traded[handle] or 0) + n end\n"
"    if to then loc = to end\n"
"    clock = clock + seconds\n"
"  else\n"
"    seconds, cr = 0, 0\n"
"  end\n"
"  record(t, p, args, result, seconds, cr, table.concat(changes, ' '))\n"
"  limits()\n"
"  return result\n"
"end\n"
"function print(...)\n"
"  check()\n"
"  local t = {}\n"
"  for i = 1, select('#', ...) do t[i] = tostring((select(i, ...))) end\n"
"  emit('say', string.sub(table.concat(t, ' '), 1, 500))\n"
"end\n"
"function report() check() end\n"
"function set_status(s)\n"
"  check()\n"
"  if type(s) ~= 'string' and type(s) ~= 'number' then error('set_status: a string was expected', 2) end\n"
"  emit('status', string.sub(tostring(s), 1, 80))\n"
"end\n"
// The finance words: recorded as actions; they change nothing
"local function word(name)\n"
"  return function(...)\n"
"    check()\n"
"    limits()\n"
"    record(clock, name, {...}, 0, 0, 0, '')\n"
"    limits()\n"
"    return 0\n"
"  end\n"
"end\n"
"for _, name in ipairs({'set_dividend_share', 'pay_out', 'close_company', 'request_loan', 'cancel_request', 'repay',\n"
"                       'post_contract', 'take_contract', 'deliver', 'cancel_contract'}) do\n"
"  _G[name] = word(name)\n"
"end\n";

/* The memory line (#1077), once the drone's cap applies */
static void print_memory(void)
{
    if(capped)
        printf("memory\t%zu\t%d\n", peak, LUA_MEMORY_CAP);
}

/* Prints the error that ended the run, on one line */
static void print_error(lua_State *L, int status)
{
    const char *msg = lua_tostring(L, -1);
    printf("error\t");
    for(const char *c = msg ? msg : (status == LUA_ERRMEM ? "the script used too much memory" : "it failed"); *c; c++)
        putchar(*c == '\t' || *c == '\n' || *c == '\r' ? ' ' : *c);
    putchar('\n');
}

/* After a chunk ran: 0 to go on, or 1 when the drive is over (said why) */
static int finished(lua_State *L, int status)
{
    if(halted[0] || status != LUA_OK)
        print_memory();
    if(halted[0]) {
        if(strcmp(halted, "need") != 0)
            printf("end\t%s\n", halted);
        return 1;
    }
    if(status != LUA_OK) {
        print_error(L, status);
        return 1;
    }
    return 0;
}

int main(int argc, char **argv)
{
    if(argc < 4) {
        fprintf(stderr, "usage: lua-drive <library.lua> <script.lua> <snapshot.lua>\n");
        return 2;
    }
    size_t library_len = 0, script_len = 0, snapshot_len = 0;
    char *library = slurp(argv[1], &library_len);
    char *script = slurp(argv[2], &script_len);
    char *snapshot = slurp(argv[3], &snapshot_len);
    if(!library || !script || !snapshot) {
        printf("error\tthe library, the script or the snapshot could not be read\n");
        return 1;
    }

    lua_State *L = lua_newstate(limited_alloc, NULL);
    if(!L) return 1;
    size_t bare = 0;   /* a fresh state with the drone's libraries, as the engine's */
    /* The drone's libraries (lua_drone_sandbox) */
    luaL_requiref(L, LUA_GNAME, luaopen_base, 1); lua_pop(L, 1);
    luaL_requiref(L, LUA_STRLIBNAME, luaopen_string, 1); lua_pop(L, 1);
    luaL_requiref(L, LUA_TABLIBNAME, luaopen_table, 1); lua_pop(L, 1);
    luaL_requiref(L, LUA_MATHLIBNAME, luaopen_math, 1); lua_pop(L, 1);
    luaL_requiref(L, LUA_UTF8LIBNAME, luaopen_utf8, 1); lua_pop(L, 1);

    /* A fixed seed, so a drive run again does the same */
    lua_getglobal(L, "math");
    lua_getfield(L, -1, "randomseed");
    lua_pushinteger(L, RANDOM_SEED);
    lua_call(L, 1, 0);
    lua_pop(L, 1);
    lua_gc(L, LUA_GCCOLLECT);
    bare = in_use;

    /* The snapshot: data, run with no globals */
    if(luaL_loadbuffer(L, snapshot, snapshot_len, "=snapshot") != LUA_OK) {
        print_error(L, LUA_ERRSYNTAX);
        return 1;
    }
    lua_newtable(L);
    lua_setupvalue(L, -2, 1);
    if(lua_pcall(L, 0, 1, 0) != LUA_OK || !lua_istable(L, -1)) {
        printf("error\tthe snapshot is not a table\n");
        return 1;
    }
    int snap = lua_gettop(L);

    /* The stubs */
    if(luaL_loadbuffer(L, PRELUDE, strlen(PRELUDE), "=drive") != LUA_OK) {
        print_error(L, LUA_ERRSYNTAX);
        return 1;
    }
    lua_pushcfunction(L, emit);
    lua_pushcfunction(L, halt);
    lua_pushvalue(L, snap);
    if(lua_pcall(L, 3, 0, 0) != LUA_OK) {
        print_error(L, LUA_ERRRUN);
        return 1;
    }
    lua_settop(L, 0);

    /* The sandbox, as the engine's */
    const char *gone[] = { "load", "loadfile", "dofile", "require", "collectgarbage", NULL };
    for(int i = 0; gone[i]; i++) {
        lua_pushnil(L);
        lua_setglobal(L, gone[i]);
    }
    lua_getglobal(L, "math");
    lua_pushnil(L);
    lua_setfield(L, -2, "randomseed");
    lua_pop(L, 1);

    /* From here the drone's cap applies to all but the harness */
    lua_gc(L, LUA_GCCOLLECT);
    harness = in_use - bare;
    capped = 1;
    peak = in_use - harness;

    /* The library, then the script's top level, then launched(), as the
       engine starts a drone (lua_drone_build) */
    if(luaL_loadbuffer(L, library, library_len, "=library") != LUA_OK || lua_pcall(L, 0, 0, 0) != LUA_OK) {
        printf("error\tthe standard library didn't load: %s\n", lua_tostring(L, -1));
        return 1;
    }
    lua_sethook(L, count_hook, LUA_MASKCOUNT, HOOK_EVERY);
    int status = luaL_loadbuffer(L, script, script_len, "=script");
    if(status == LUA_OK)
        status = lua_pcall(L, 0, 0, 0);
    if(finished(L, status)) {
        lua_close(L);
        return status == LUA_OK || halted[0] ? 0 : 1;
    }
    lua_getglobal(L, "launched");
    if(!lua_isfunction(L, -1)) {
        print_memory();
        printf("error\tthe script defines no launched() function: a drone would do nothing\n");
        lua_close(L);
        return 1;
    }
    status = lua_pcall(L, 0, 0, 0);
    if(!finished(L, status)) {
        print_memory();
        printf("end\tfinished\n");
    }
    lua_close(L);
    free(library); free(script); free(snapshot);
    return status == LUA_OK || halted[0] ? 0 : 1;
}
