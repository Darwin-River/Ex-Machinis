/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Lua drones (#380). A drone whose agents.language is 'lua'
               runs a Lua program instead of a FORTH one.

  - Its released script arrives in pieces, as a FORTH script does; each piece
    is added to agents.lua_script. On <launched> the drone's Lua is built: the
    standard library (lua_library.c), then the script, then launched() is
    started in a coroutine.
  - The drone's Lua stays in the engine's memory between slices (a paused
    coroutine can't be saved). Its plain-data globals (numbers, strings,
    booleans and tables of them) are saved to agents.lua_state whenever it
    pauses for an action, and a clone gets them, as a FORTH clone gets its
    parent's memory. If the engine restarts, a drone's next resume rebuilds
    it from its script and saved variables and starts launched() again.
  - Sandbox: the base library (less load, loadfile, dofile, require and
    collectgarbage), string, table, math and utf8; no io, os or debug. A
    memory cap, from the drone's own allocator.
  - Metering: a count hook charges every Lua instruction as
    LUA_INSTRUCTION_WEIGHT of the FORTH VM's, to the same meter (so slices,
    fuel and the instructions shown are as for FORTH), and ends the slice
    when its budget is used: the coroutine yields, and the engine queues its
    resume as for FORTH.
  - perform(protocol, ...) runs the protocol through protocol_execute, its
    arguments on a stack of cells (vm_extension.c); an accepted action
    pauses the script until it is done, and perform returns its result (0,
    or a negative code), as FORTH's perform leaves it. query(id, ...) runs
    the query and returns its rows as a table.

******************************************************************************/

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <ctype.h>
#include <mysql.h>

#include "lua.h"
#include "lauxlib.h"
#include "lualib.h"
#include "lstate.h"   /* the count hook's counter, to charge a slice's last instructions */

#include "lua_drone.h"
#include "engine.h"
#include "db.h"
#include "vm.h"
#include "vm_meter.h"
#include "vm_extension.h"
#include "protocol.h"
#include "game_time.h"
#include "trace.h"

#define HOOK_EVERY 1000
#define BUCKETS 1024
#define MAX_PERFORM_CELLS 32
#define MAX_PERFORM_STRINGS 4
#define LUA_CRASH_ERROR (-1)    /* launched() raised an error */
#define LUA_CRASH_MEMORY (-2)   /* it ran out of memory */

char* vm_get_new_tag_value(VmExtension_t* v, const char *tag, Queries_t* queryInfo);
void engine_send_output(const char* msg, bool with_pending);

typedef struct LuaDrone
{
    int agent_id;
    lua_State *L;
    lua_State *co;        /* the coroutine running launched() */
    size_t mem;           /* bytes its Lua holds */
    int has_result;       /* perform's result is waiting to be handed back */
    int result;
    int finished;         /* launched() returned */
    int crash;            /* launched() failed: its code */
    struct LuaDrone *next;
} LuaDrone;

static LuaDrone* g_drones[BUCKETS];
static LuaDrone* g_current = NULL;   /* the drone running now */

/******************************* THE REGISTRY ********************************/

static LuaDrone* drone_find(int agent_id)
{
    for(LuaDrone* d = g_drones[(unsigned)agent_id % BUCKETS]; d; d = d->next)
        if(d->agent_id == agent_id)
            return d;
    return NULL;
}

static LuaDrone* drone_add(int agent_id)
{
    LuaDrone* d = calloc(1, sizeof(*d));
    if(!d)
        return NULL;
    d->agent_id = agent_id;
    unsigned b = (unsigned)agent_id % BUCKETS;
    d->next = g_drones[b];
    g_drones[b] = d;
    return d;
}

void lua_drone_drop(int agent_id)
{
    LuaDrone** p = &g_drones[(unsigned)agent_id % BUCKETS];
    while(*p && (*p)->agent_id != agent_id)
        p = &(*p)->next;
    if(!*p)
        return;
    LuaDrone* d = *p;
    *p = d->next;
    if(d->L)
        lua_close(d->L);
    if(g_current == d)
        g_current = NULL;
    free(d);
}

/******************************* THE DATABASE ********************************/

static ErrorCode_t lua_db_exec(const char* sql)
{
    DbConnection_t* c = engine_get_db_connection();
    if(db_reconnect(c) != ENGINE_OK)
        return ENGINE_DB_QUERY_ERROR;
    if(mysql_query(c->hndl, sql))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Lua query [%.200s] failed: %s", sql, mysql_error(c->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    return ENGINE_OK;
}

/* One text column of the drone's row, malloc'd (NULL when NULL) */
static char* lua_db_text(int agent_id, const char* column, size_t* len)
{
    char sql[256];
    DbConnection_t* c = engine_get_db_connection();
    snprintf(sql, sizeof(sql), "SELECT %s FROM agents WHERE agent_id = %d", column, agent_id);
    if(lua_db_exec(sql) != ENGINE_OK)
        return NULL;
    MYSQL_RES* r = mysql_store_result(c->hndl);
    MYSQL_ROW row = r ? mysql_fetch_row(r) : NULL;
    char* out = NULL;
    if(row && row[0])
    {
        unsigned long n = mysql_fetch_lengths(r)[0];
        out = malloc(n + 1);
        if(out)
        {
            memcpy(out, row[0], n);
            out[n] = 0;
            if(len) *len = n;
        }
    }
    if(r) mysql_free_result(r);
    return out;
}

/* Sets a text column (NULL clears it); append adds to what is there */
static ErrorCode_t lua_db_set_text(int agent_id, const char* column, const char* text, size_t len, int append)
{
    DbConnection_t* c = engine_get_db_connection();
    if(db_reconnect(c) != ENGINE_OK)
        return ENGINE_DB_QUERY_ERROR;
    if(!text)
    {
        char sql[256];
        snprintf(sql, sizeof(sql), "UPDATE agents SET %s = NULL WHERE agent_id = %d", column, agent_id);
        return lua_db_exec(sql);
    }
    size_t size = len * 2 + 256;
    char* sql = malloc(size);
    if(!sql)
        return ENGINE_INTERNAL_ERROR;
    int n = append ?
        snprintf(sql, size, "UPDATE agents SET %s = CONCAT(IFNULL(%s, ''), '", column, column) :
        snprintf(sql, size, "UPDATE agents SET %s = '", column);
    n += mysql_real_escape_string(c->hndl, sql + n, text, len);
    n += snprintf(sql + n, size - n, "%s WHERE agent_id = %d", append ? "')" : "'", agent_id);
    ErrorCode_t r = lua_db_exec(sql);
    free(sql);
    return r;
}

void lua_drone_forget_state(int agent_id)
{
    lua_db_set_text(agent_id, "lua_state", NULL, 0, 0);
}

void lua_drone_mark_released(int agent_id)
{
    char sql[160];
    snprintf(sql, sizeof(sql), "UPDATE agents SET release_vm = '' WHERE agent_id = %d AND release_vm IS NULL", agent_id);
    lua_db_exec(sql);
}

void lua_drone_clear_script(int agent_id)
{
    lua_db_set_text(agent_id, "lua_script", NULL, 0, 0);
    lua_db_set_text(agent_id, "lua_state", NULL, 0, 0);
}

/******************************* MEMORY AND METERING *************************/

static void* lua_drone_alloc(void* ud, void* ptr, size_t osize, size_t nsize)
{
    LuaDrone* d = ud;
    size_t old = ptr ? osize : 0;
    if(nsize == 0)
    {
        d->mem -= old;
        free(ptr);
        return NULL;
    }
    if(d->mem - old + nsize > LUA_MEMORY_CAP)
        return NULL;   /* Lua raises "not enough memory" in the script */
    void* p = realloc(ptr, nsize);
    if(p)
        d->mem = d->mem - old + nsize;
    return p;
}

/* Charges Lua instructions to the slice's meter */
static void lua_charge(long long lua_instructions)
{
    vm_meter_charge(lua_instructions * LUA_INSTRUCTION_WEIGHT);
}

/* While the script's top level runs (not in a coroutine: it can't pause),
   at most LUA_LOAD_INSTRUCTIONS: a top level that loops would hold the
   engine (#380 round 35: a test run's did, at 99% of a core) */
static long long g_load_used = 0;
static void lua_charge_hook(lua_State* L, lua_Debug* ar)
{
    (void)ar;
    lua_charge(HOOK_EVERY);
    g_load_used += HOOK_EVERY;
    if(g_load_used > LUA_LOAD_INSTRUCTIONS)
        luaL_error(L, "the script's top level ran more than %d instructions: "
                      "put work in functions that launched calls", LUA_LOAD_INSTRUCTIONS);
}

static void lua_count_hook(lua_State* L, lua_Debug* ar)
{
    (void)ar;
    lua_charge(HOOK_EVERY);
    if(vm_meter_exhausted())
    {
        vm_slice_yield();
        lua_yield(L, 0);   /* the slice is used up: carries on next turn */
    }
}

/******************************* THE GAME'S PRIMITIVES ***********************/

/* print(...): to the drone's output, as FORTH's . and type do */
static int l_print(lua_State* L)
{
    int n = lua_gettop(L);
    for(int i = 1; i <= n; i++)
    {
        if(i > 1) vm_append_output("\t");
        vm_append_output(luaL_tolstring(L, i, NULL));
        lua_pop(L, 1);
    }
    vm_append_output("\n");
    return 0;
}

/* report(): sends what it has printed so far home */
static int l_report(lua_State* L)
{
    (void)L;
    vm_report_output();
    return 0;
}

/* perform(protocol, ...): the arguments in the order FORTH pushes them
   (the last on top). A parameter the protocol takes as a double is one Lua
   number here: it is split into its two cells. Returns the result: 0 when the
   action worked, else a negative code. An accepted action pauses the script
   until it is done. */
static int l_perform(lua_State* L)
{
    int nargs = lua_gettop(L);
    int protocol_id = (int)luaL_checkinteger(L, 1);
    int cells[MAX_PERFORM_CELLS];
    const char* strings[MAX_PERFORM_STRINGS];
    int nstrings = 0, depth = 0;

    vm_meter_charge(engine_get_action_instructions());

    /* Which of its parameters are doubles: the protocol says (the first is
       the one on top) */
    ProtocolInfo_t info;
    memset(&info, 0, sizeof(info));
    info.protocol_id = protocol_id;
    int doubles = 0, params = 0;
    if(db_get_prococol_info(&info) == ENGINE_OK)
    {
        doubles = info.double_params;
        params = info.parameters_num;
    }

    for(int j = 2; j <= nargs; j++)
    {
        int from_top = nargs - j;   /* 0 for the last argument */
        if(lua_type(L, j) == LUA_TSTRING)
        {
            if(nstrings >= MAX_PERFORM_STRINGS || depth >= MAX_PERFORM_CELLS)
                return luaL_error(L, "perform: too many arguments");
            strings[nstrings] = lua_tostring(L, j);
            cells[depth++] = nstrings++;
            continue;
        }
        lua_Integer v = luaL_checkinteger(L, j);
        if(from_top < params && (doubles & (1 << from_top)))
        {
            if(depth + 2 > MAX_PERFORM_CELLS)
                return luaL_error(L, "perform: too many arguments");
            cells[depth++] = (int)(v & 0xFFFF);           /* low cell */
            cells[depth++] = (int)((v >> 16) & 0xFFFF);   /* high cell, on top */
        }
        else
        {
            if(depth >= MAX_PERFORM_CELLS)
                return luaL_error(L, "perform: too many arguments");
            cells[depth++] = (int)v;
        }
    }

    VmExtension_t ext;
    memset(&ext, 0, sizeof(ext));
    ext.cells = cells;
    ext.depth = depth;
    ext.max_cells = MAX_PERFORM_CELLS;
    ext.strings = strings;
    ext.nstrings = nstrings;

    int before = depth;
    (void)before;
    protocol_execute(protocol_id, &ext);

    if(vm_waiting_action())
    {
        /* Accepted: pause until the action is done; the resume hands its
           result back as perform's */
        return lua_yield(L, 0);
    }
    /* Refused at once: its code is on the cells */
    int code = PERFORM_REFUSED;
    if(ext.depth > 0)
        code = ext.cells[ext.depth - 1];
    lua_pushinteger(L, code);
    return 1;
}

/* Puts a value in place of every copy of tag in *sql */
static int lua_replace_tag(char** sql, const char* tag, const char* value)
{
    size_t tl = strlen(tag), vl = strlen(value), count = 0;
    for(char* p = strstr(*sql, tag); p; p = strstr(p + tl, tag))
        count++;
    if(!count)
        return 0;
    size_t size = strlen(*sql) + count * (vl > tl ? vl - tl : 0) + 1;
    char* out = malloc(size);
    if(!out)
        return -1;
    char* o = out;
    for(char* s = *sql; *s; )
    {
        if(!strncmp(s, tag, tl)) { memcpy(o, value, vl); o += vl; s += tl; }
        else *o++ = *s++;
    }
    *o = 0;
    free(*sql);
    *sql = out;
    return 0;
}

/* query(id, ...): the arguments in the order FORTH pushes them (the last on
   top: [value_1]). A [dvalue_k] is one Lua number. A [string_k] is a Lua
   string (letters, digits, spaces, - _ .). Returns the rows: each a table
   of its columns in order, also by name (a d_ column without the d_). No
   rows is an empty table; a query that fails returns nil and a code. */
static int l_query(lua_State* L)
{
    int nargs = lua_gettop(L) - 1;
    Queries_t q;
    memset(&q, 0, sizeof(q));
    q.id = (int)luaL_checkinteger(L, 1);

    vm_meter_charge(engine_get_query_instructions());

    if(db_get_query_info(&q) != ENGINE_OK)
    {
        lua_pushnil(L);
        lua_pushinteger(L, -3);   /* unknown query, as for FORTH */
        return 2;
    }
    char* sql = strdup(q.script);
    if(!sql)
        return luaL_error(L, "query: out of memory");

    /* The parameters, from the top (the last argument) down: a [dvalue_k]
       covers cells k and k+1 */
    int is_double[12] = {0};
    for(int k = 1; k <= 4; k++)
    {
        char tag[16];
        snprintf(tag, sizeof(tag), "[dvalue_%d]", k);
        if(strstr(sql, tag)) is_double[k] = 1;
    }
    /* cell k (1 = top) -> which argument, and is it a double's first cell */
    int arg_of_cell[12] = {0};
    int cell = 1, logical = 0;
    while(cell <= q.parametersNum && cell < 11)
    {
        logical++;
        arg_of_cell[cell] = logical;
        if(is_double[cell]) { arg_of_cell[cell + 1] = logical; cell += 2; }
        else cell++;
    }
    if(logical > nargs)
    {
        free(sql);
        lua_pushnil(L);
        lua_pushinteger(L, -1);   /* not enough parameters, as for FORTH */
        return 2;
    }

    char value[64];
    for(int k = 1; k <= 9; k++)
    {
        char tag[16];
        int a;
        snprintf(tag, sizeof(tag), "[value_%d]", k);
        if(strstr(sql, tag) && k < 12 && (a = arg_of_cell[k]))
        {
            int idx = 1 + nargs - a + 1;   /* the a-th from the top */
            snprintf(value, sizeof(value), "%lld", (long long)luaL_checkinteger(L, idx));
            lua_replace_tag(&sql, tag, value);
        }
        snprintf(tag, sizeof(tag), "[dvalue_%d]", k);
        if(k <= 4 && strstr(sql, tag) && (a = arg_of_cell[k]))
        {
            int idx = 1 + nargs - a + 1;
            snprintf(value, sizeof(value), "%lld", (long long)luaL_checkinteger(L, idx));
            lua_replace_tag(&sql, tag, value);
        }
    }
    for(int k = 1; k <= 4; k++)
    {
        char tag[16];
        int a;
        snprintf(tag, sizeof(tag), "[string_%d]", k);
        if(strstr(sql, tag) && (a = arg_of_cell[k]))
        {
            const char* s = luaL_checkstring(L, 1 + nargs - a + 1);
            for(const char* c = s; *c; c++)
                if(!(isalnum((unsigned char)*c) || *c == ' ' || *c == '-' || *c == '_' || *c == '.'))
                {
                    free(sql);
                    return luaL_error(L, "query: a string may hold only letters, digits, spaces and - _ .");
                }
            lua_replace_tag(&sql, tag, s);
        }
    }
    snprintf(value, sizeof(value), "%d", engine_get_current_drone_id());
    lua_replace_tag(&sql, QUERY_TAG_DRONE_ID, value);
    snprintf(value, sizeof(value), "%lld", game_time_now_seconds());
    lua_replace_tag(&sql, QUERY_TAG_GAME_NOW, value);
    if(strstr(sql, QUERY_TAG_TIME_DELAY))
    {
        char* delay = vm_get_new_tag_value(NULL, QUERY_TAG_TIME_DELAY, &q);
        lua_replace_tag(&sql, QUERY_TAG_TIME_DELAY, delay ? delay : "0");
        if(delay) engine_free(delay, MAX_QUERY_VALUE_BUF_LEN);
    }

    DbConnection_t* c = engine_get_db_connection();
    if(db_reconnect(c) != ENGINE_OK || mysql_query(c->hndl, sql))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Lua query [%d] failed: [%s]", q.id, sql);
        free(sql);
        lua_pushnil(L);
        lua_pushinteger(L, -2);   /* the query failed, as for FORTH */
        return 2;
    }
    free(sql);
    MYSQL_RES* r = mysql_store_result(c->hndl);
    lua_newtable(L);
    if(!r)
        return 1;
    int nf = mysql_num_fields(r);
    MYSQL_FIELD* fields = mysql_fetch_fields(r);
    MYSQL_ROW row;
    int n = 0;
    while((row = mysql_fetch_row(r)))
    {
        lua_createtable(L, nf, nf);
        for(int f = 0; f < nf; f++)
        {
            if(!row[f])
                lua_pushboolean(L, 0);
            else
            {
                char* end = NULL;
                long long v = strtoll(row[f], &end, 10);
                if(end && *end == 0 && end != row[f]) lua_pushinteger(L, v);
                else
                {
                    double dv = strtod(row[f], &end);
                    if(end && *end == 0 && end != row[f]) lua_pushnumber(L, dv);
                    else lua_pushstring(L, row[f]);
                }
            }
            lua_pushvalue(L, -1);
            lua_rawseti(L, -3, f + 1);
            const char* name = fields[f].name;
            if(!strncmp(name, "d_", 2)) name += 2;
            lua_setfield(L, -2, name);
        }
        lua_rawseti(L, -2, ++n);
    }
    mysql_free_result(r);
    return 1;
}

/******************************* BUILDING A DRONE ****************************/

static void lua_drone_sandbox(lua_State* L)
{
    luaL_requiref(L, LUA_GNAME, luaopen_base, 1);
    luaL_requiref(L, LUA_STRLIBNAME, luaopen_string, 1);
    luaL_requiref(L, LUA_TABLIBNAME, luaopen_table, 1);
    luaL_requiref(L, LUA_MATHLIBNAME, luaopen_math, 1);
    luaL_requiref(L, LUA_UTF8LIBNAME, luaopen_utf8, 1);
    lua_pop(L, 5);
    const char* gone[] = { "load", "loadfile", "dofile", "require", "collectgarbage", NULL };
    for(int i = 0; gone[i]; i++)
    {
        lua_pushnil(L);
        lua_setglobal(L, gone[i]);
    }
    /* math.random would differ between a drone and its rebuilt self */
    lua_getglobal(L, "math");
    lua_pushnil(L);
    lua_setfield(L, -2, "randomseed");
    lua_pop(L, 1);
    const luaL_Reg prims[] = {
        { "print", l_print }, { "report", l_report }, { "perform", l_perform }, { "query", l_query },
        { NULL, NULL } };
    for(int i = 0; prims[i].name; i++)
    {
        lua_pushcfunction(L, prims[i].func);
        lua_setglobal(L, prims[i].name);
    }
}

/* The library's own state, saved like the drone's (#380 round 38: a
   restarted drone was back in mode 0 and posted its orders again) */
static const char* LIBRARY_STATE[] = { "mode", "ticks_in_mode", "my_orders", "my_orders_of", NULL };

static int lua_is_library_state(lua_State* L, int index)
{
    if(lua_type(L, index) != LUA_TSTRING)
        return 0;
    const char* name = lua_tostring(L, index);
    for(int i = 0; LIBRARY_STATE[i]; i++)
        if(strcmp(name, LIBRARY_STATE[i]) == 0)
            return 1;
    return 0;
}

/* Names the library and the sandbox define, so a snapshot keeps only the
   drone's own: the set of globals before the script runs (but for the
   library's own state) */
static void lua_drone_mark_builtins(lua_State* L)
{
    lua_newtable(L);
    lua_pushglobaltable(L);
    lua_pushnil(L);
    while(lua_next(L, -2))
    {
        lua_pop(L, 1);
        if(lua_is_library_state(L, -1))
            continue;
        lua_pushvalue(L, -1);
        lua_pushboolean(L, 1);
        lua_rawset(L, -5);
    }
    lua_pop(L, 1);
    lua_setfield(L, LUA_REGISTRYINDEX, "builtins");
}

/* The snapshot is built in C memory, not a luaL_Buffer: a luaL_Buffer must
   keep the stack balanced between its calls, and walking tables with
   lua_next doesn't (#380 round 35: a drone with nested tables crashed the
   engine when it saved) */
typedef struct { char* s; size_t len, cap; int failed; } Text;

static void text_add(Text* t, const char* s, size_t n)
{
    if(t->failed) return;
    if(t->len + n + 1 > t->cap)
    {
        size_t cap = t->cap ? t->cap * 2 : 1024;
        while(cap < t->len + n + 1) cap *= 2;
        if(cap > LUA_MEMORY_CAP * 4) { t->failed = 1; return; }
        char* p = realloc(t->s, cap);
        if(!p) { t->failed = 1; return; }
        t->s = p;
        t->cap = cap;
    }
    memcpy(t->s + t->len, s, n);
    t->len += n;
    t->s[t->len] = 0;
}
static void text_str(Text* t, const char* s) { text_add(t, s, strlen(s)); }

/* A Lua string literal: printable ASCII as it is, the rest as \ddd */
static void text_quoted(Text* t, const char* s, size_t n)
{
    text_str(t, "\"");
    for(size_t i = 0; i < n; i++)
    {
        unsigned char c = (unsigned char)s[i];
        char e[8];
        if(c == '"' || c == '\\') { e[0] = '\\'; e[1] = (char)c; text_add(t, e, 2); }
        else if(c >= 32 && c < 127) text_add(t, (const char*)&c, 1);
        else { snprintf(e, sizeof(e), "\\%03d", c); text_str(t, e); }
    }
    text_str(t, "\"");
}

static void save_value(lua_State* L, int idx, Text* t, int depth)
{
    idx = lua_absindex(L, idx);
    switch(lua_type(L, idx))
    {
    case LUA_TNUMBER:
    {
        char n[48];
        if(lua_isinteger(L, idx)) snprintf(n, sizeof(n), "%lld", (long long)lua_tointeger(L, idx));
        else snprintf(n, sizeof(n), "%.17g", lua_tonumber(L, idx));
        text_str(t, n);
        break;
    }
    case LUA_TBOOLEAN:
        text_str(t, lua_toboolean(L, idx) ? "true" : "false");
        break;
    case LUA_TSTRING:
    {
        size_t n;
        const char* s = lua_tolstring(L, idx, &n);
        text_quoted(t, s, n);
        break;
    }
    case LUA_TTABLE:
        if(depth > 8 || !lua_checkstack(L, 4)) { text_str(t, "nil"); break; }
        text_str(t, "{");
        lua_pushnil(L);
        while(lua_next(L, idx))
        {
            int kt = lua_type(L, -2), vt = lua_type(L, -1);
            if((kt == LUA_TNUMBER || kt == LUA_TSTRING) &&
               (vt == LUA_TNUMBER || vt == LUA_TSTRING || vt == LUA_TBOOLEAN || vt == LUA_TTABLE))
            {
                text_str(t, "[");
                save_value(L, -2, t, depth + 1);
                text_str(t, "]=");
                save_value(L, -1, t, depth + 1);
                text_str(t, ",");
            }
            lua_pop(L, 1);
        }
        text_str(t, "}");
        break;
    default:
        text_str(t, "nil");
    }
}

/* The drone's plain-data globals as Lua source ("return {...}") into the
   Text passed as a light userdata */
static int save_globals(lua_State* L)
{
    Text* t = lua_touserdata(L, 1);
    text_str(t, "return {");
    lua_getfield(L, LUA_REGISTRYINDEX, "builtins");
    int builtins = lua_gettop(L);
    lua_pushglobaltable(L);
    int globals = lua_gettop(L);
    lua_pushnil(L);
    while(lua_next(L, globals))
    {
        int vt = lua_type(L, -1);
        int own = 1;
        lua_pushvalue(L, -2);
        lua_rawget(L, builtins);
        if(lua_toboolean(L, -1)) own = 0;
        lua_pop(L, 1);
        if(own && lua_type(L, -2) == LUA_TSTRING &&
           (vt == LUA_TNUMBER || vt == LUA_TSTRING || vt == LUA_TBOOLEAN || vt == LUA_TTABLE))
        {
            text_str(t, "[");
            save_value(L, -2, t, 0);
            text_str(t, "]=");
            save_value(L, -1, t, 0);
            text_str(t, ",");
        }
        lua_pop(L, 1);
    }
    lua_settop(L, 1);
    text_str(t, "}");
    return 0;
}

static void lua_drone_save_state(LuaDrone* d)
{
    Text t = { NULL, 0, 0, 0 };
    lua_sethook(d->L, NULL, 0, 0);
    lua_pushcfunction(d->L, save_globals);
    lua_pushlightuserdata(d->L, &t);
    int r = lua_pcall(d->L, 1, 0, 0);
    if(r != LUA_OK)
        lua_pop(d->L, 1);   /* its error: the last snapshot stays */
    else if(!t.failed && t.s)
        lua_db_set_text(d->agent_id, "lua_state", t.s, t.len, 0);
    free(t.s);
}

/* Builds the drone's Lua: library, script, saved variables. Leaves
   launched() ready in the coroutine. 0, or -1 with the reason printed. */
static int lua_drone_build(LuaDrone* d, int with_state)
{
    d->L = lua_newstate(lua_drone_alloc, d);
    if(!d->L)
    {
        vm_append_output("error: no memory for my program\n");
        return -1;
    }
    lua_drone_sandbox(d->L);
    if(luaL_loadbuffer(d->L, lua_library_source, strlen(lua_library_source), "=library") != LUA_OK ||
       lua_pcall(d->L, 0, 0, 0) != LUA_OK)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Lua library: %s", lua_tostring(d->L, -1));
        vm_append_output("error: the standard library didn't load\n");
        return -1;
    }
    lua_drone_mark_builtins(d->L);

    size_t len = 0;
    char* script = lua_db_text(d->agent_id, "lua_script", &len);
    /* The script's top level (settings, functions) is metered like any run */
    g_load_used = 0;
    lua_sethook(d->L, lua_charge_hook, LUA_MASKCOUNT, HOOK_EVERY);
    int r = luaL_loadbuffer(d->L, script ? script : "", script ? len : 0, "=script");
    free(script);
    if(r == LUA_OK)
        r = lua_pcall(d->L, 0, 0, 0);
    lua_sethook(d->L, NULL, 0, 0);
    if(r != LUA_OK)
    {
        vm_append_output("error: ");
        vm_append_output(lua_tostring(d->L, -1));
        vm_append_output("\n");
        return -1;
    }

    if(with_state)
    {
        size_t sl = 0;
        char* state = lua_db_text(d->agent_id, "lua_state", &sl);
        if(state && luaL_loadbuffer(d->L, state, sl, "=state") == LUA_OK && lua_pcall(d->L, 0, 1, 0) == LUA_OK &&
           lua_istable(d->L, -1))
        {
            lua_pushglobaltable(d->L);
            lua_pushnil(d->L);
            while(lua_next(d->L, -3))
            {
                lua_pushvalue(d->L, -2);
                lua_insert(d->L, -2);
                lua_settable(d->L, -4);
            }
            lua_pop(d->L, 1);
        }
        lua_settop(d->L, 0);
        free(state);
    }

    d->co = lua_newthread(d->L);
    lua_setfield(d->L, LUA_REGISTRYINDEX, "drone_thread");
    lua_getglobal(d->co, LAUNCHED_WORD);
    if(!lua_isfunction(d->co, -1))
    {
        vm_append_output("Launched. My program has no launched function: it does nothing.\n");
        d->finished = 1;
        return 1;
    }
    return 0;
}

/* One slice: resume the coroutine until it pauses or ends */
static void lua_drone_slice(LuaDrone* d)
{
    int nargs = 0, nres = 0;
    if(d->has_result)
    {
        lua_pushinteger(d->co, d->result);
        nargs = 1;
        d->has_result = 0;
    }
    lua_sethook(d->co, lua_count_hook, LUA_MASKCOUNT, HOOK_EVERY);
    g_current = d;
    int r = lua_resume(d->co, d->L, nargs, &nres);
    g_current = NULL;
    long long tail = d->co->basehookcount - d->co->hookcount;
    if(tail > 0 && tail < HOOK_EVERY)
        lua_charge(tail);
    lua_sethook(d->co, NULL, 0, 0);

    if(r == LUA_YIELD)
    {
        lua_pop(d->co, nres);
        /* An action: the script waits for it; save its variables now */
        if(vm_waiting_action())
            lua_drone_save_state(d);
        return;
    }
    if(r == LUA_OK)
    {
        d->finished = 1;
        return;
    }
    d->crash = (r == LUA_ERRMEM) ? LUA_CRASH_MEMORY : LUA_CRASH_ERROR;
    vm_append_output("error: ");
    vm_append_output(lua_tostring(d->co, -1) ? lua_tostring(d->co, -1) : "unknown");
    vm_append_output("\n");
}

/******************************* PUBLIC FUNCTIONS ****************************/

ErrorCode_t lua_drone_command(int agent_id, LuaRun_t kind, const char* code, char* out_buf, size_t out_size)
{
    vm_output_begin(out_buf, out_size);
    vm_meter_start(engine_get_slice_instructions());

    if(kind == LUA_RUN_PIECE)
    {
        /* A piece of the released script: kept until <launched> */
        /* Each piece ends at a line end the release split it at */
        size_t n = strlen(code);
        char* piece = malloc(n + 2);
        if(!piece) return ENGINE_INTERNAL_ERROR;
        memcpy(piece, code, n);
        if(n == 0 || code[n - 1] != '\n') piece[n++] = '\n';
        piece[n] = 0;
        ErrorCode_t r = lua_db_set_text(agent_id, "lua_script", piece, n, 1);
        free(piece);
        vm_append_output(r == ENGINE_OK ? "ok\n" : "error: couldn't keep this piece of my program\n");
        return r;
    }

    LuaDrone* d = drone_find(agent_id);
    if(kind == LUA_RUN_LAUNCH)
    {
        if(d) lua_drone_drop(agent_id);
        d = drone_add(agent_id);
        if(!d) return ENGINE_INTERNAL_ERROR;
        /* A clone starts with its parent's variables (as a FORTH clone with
           its parent's memory); a release, with none saved yet */
        int built = lua_drone_build(d, 1);
        if(built < 0) { d->crash = LUA_CRASH_ERROR; return ENGINE_OK; }
        if(built > 0) return ENGINE_OK;
        /* Sent at once, as a FORTH drone's launch line is */
        engine_send_output("Launched. Running my launched function.", false);
    }
    else if(!d)
    {
        /* The engine restarted since this drone last ran: rebuild it from its
           script and saved variables, and start launched() again */
        d = drone_add(agent_id);
        if(!d) return ENGINE_INTERNAL_ERROR;
        int built = lua_drone_build(d, 1);
        if(built < 0) { d->crash = LUA_CRASH_ERROR; return ENGINE_OK; }
        if(built > 0) return ENGINE_OK;
        vm_append_output("(The engine restarted: running my launched function again, with my saved variables.)\n");
    }

    if(d->finished || d->crash)
        return ENGINE_OK;
    lua_drone_slice(d);
    return ENGINE_OK;
}

void lua_drone_set_result(int agent_id, int code)
{
    LuaDrone* d = drone_find(agent_id);
    if(d)
    {
        d->result = code;
        d->has_result = 1;
    }
}

int lua_drone_take_result(int agent_id)
{
    LuaDrone* d = drone_find(agent_id);
    if(!d)
        return 0;
    int code = d->crash;
    if(code)
        lua_drone_drop(agent_id);
    return code;
}
