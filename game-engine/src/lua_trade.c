/*
 * lua-trade (#768): runs a player's trading script once, in a sandbox.
 *
 *   lua-trade <script.lua> <market.lua> [<memory.lua>]
 *
 * market.lua and memory.lua are Lua chunks that return a table (the website
 * writes them: the share market and the account now, and what the script
 * kept last time). The script defines trade(market); it is called once. It
 * acts through buy, sell, buy_pegged, sell_pegged, cancel, bank_buy,
 * bank_sell and log, and keeps what it likes in the table `memory`.
 *
 * Standard output, one line each, fields separated by tabs:
 *   buy <company> <units> <price>          sell <company> <units> <price>
 *   buy_pegged <company> <units> <peg> <percent>   (sell_pegged the same)
 *   cancel <order>    bank_buy <company> <units>    bank_sell <company> <units>
 *   log <text>
 *   memory <a Lua table constructor>       (last, when the run ended well)
 *   error <message>                        (and exit status 1)
 * The website applies the actions under the player's own rules.
 *
 * The sandbox: no io, os, package, load, require or debug; at most
 * MAX_INSTRUCTIONS Lua instructions, MAX_MEMORY bytes, MAX_ACTIONS actions
 * and MAX_LOG log lines a run.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "lua.h"
#include "lauxlib.h"
#include "lualib.h"

#define MAX_INSTRUCTIONS 20000000L
#define MAX_MEMORY (32L * 1024 * 1024)
#define HOOK_EVERY 1000

static long instructions = 0;
static size_t in_use = 0;

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
    void *p = realloc(ptr, nsize);
    if(p) in_use = in_use - osize + nsize;
    return p;
}

static void count_hook(lua_State *L, lua_Debug *ar)
{
    (void)ar;
    instructions += HOOK_EVERY;
    if(instructions > MAX_INSTRUCTIONS)
        luaL_error(L, "the script used more than %d million instructions in one run", (int)(MAX_INSTRUCTIONS / 1000000));
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

static char *slurp(const char *path)
{
    FILE *f = fopen(path, "rb");
    if(!f) return NULL;
    fseek(f, 0, SEEK_END);
    long size = ftell(f);
    fseek(f, 0, SEEK_SET);
    if(size < 0 || size > 4 * 1024 * 1024) { fclose(f); return NULL; }
    char *buf = malloc((size_t)size + 1);
    if(!buf) { fclose(f); return NULL; }
    size_t got = fread(buf, 1, (size_t)size, f);
    buf[got] = 0;
    fclose(f);
    return buf;
}

/* The trusted side, in Lua: builds the sandbox, the API, runs the script,
   and writes memory back. Its arguments: emit, the script, the market and
   the memory, as strings */
static const char *PRELUDE =
"local emit, script, market_src, memory_src = ...\n"
"local MAX_ACTIONS, MAX_LOG = 20, 50\n"
"local actions, logs, warned = 0, 0, false\n"
"local function data(src, name)\n"
"  if not src or src == '' then return {} end\n"
"  local f, err = load(src, name, 't', {})\n"
"  if not f then error(name .. ': ' .. err, 0) end\n"
"  local ok, v = pcall(f)\n"
"  if not ok or type(v) ~= 'table' then return {} end\n"
"  return v\n"
"end\n"
"local market = data(market_src, 'market')\n"
"local memory = data(memory_src, 'memory')\n"
"local function whole(v, what)\n"
"  local n = math.tointeger(v)\n"
"  if not n then error(what .. ' must be a whole number', 3) end\n"
"  return n\n"
"end\n"
"local function act(...)\n"
"  if actions >= MAX_ACTIONS then\n"
"    -- said once in the log (#832: the extra actions were dropped silently)\n"
"    if not warned then warned = true; emit('log', 'at most ' .. MAX_ACTIONS .. ' actions a run: the rest of this run\\'s were not done') end\n"
"    return false, 'at most ' .. MAX_ACTIONS .. ' actions a run'\n"
"  end\n"
"  actions = actions + 1\n"
"  emit(...)\n"
"  return true\n"
"end\n"
"local function peg(p)\n"
"  if p ~= 'buys_at' and p ~= 'sells_at' then error(\"the peg is 'buys_at' or 'sells_at'\", 3) end\n"
"  return p\n"
"end\n"
"local function percent(v)\n"
"  if type(v) ~= 'number' then error('the percent must be a number', 3) end\n"
"  return string.format('%.2f', v)\n"
"end\n"
"local api = {\n"
"  buy = function(c, n, p) return act('buy', whole(c, 'the company'), whole(n, 'the units'), whole(p, 'the price')) end,\n"
"  sell = function(c, n, p) return act('sell', whole(c, 'the company'), whole(n, 'the units'), whole(p, 'the price')) end,\n"
"  buy_pegged = function(c, n, g, pc) return act('buy_pegged', whole(c, 'the company'), whole(n, 'the units'), peg(g), percent(pc or 0)) end,\n"
"  sell_pegged = function(c, n, g, pc) return act('sell_pegged', whole(c, 'the company'), whole(n, 'the units'), peg(g), percent(pc or 0)) end,\n"
"  cancel = function(o) return act('cancel', whole(o, 'the order')) end,\n"
"  bank_buy = function(c, n) return act('bank_buy', whole(c, 'the company'), whole(n, 'the units')) end,\n"
"  bank_sell = function(c, n) return act('bank_sell', whole(c, 'the company'), whole(n, 'the units')) end,\n"
"  log = function(...)\n"
"    if logs >= MAX_LOG then return end\n"
"    logs = logs + 1\n"
"    local t = {}\n"
"    for i = 1, select('#', ...) do t[i] = tostring((select(i, ...))) end\n"
"    emit('log', string.sub(table.concat(t, ' '), 1, 300))\n"
"  end,\n"
"}\n"
"local env = {\n"
"  assert = assert, error = error, ipairs = ipairs, next = next, pairs = pairs, pcall = pcall,\n"
"  rawequal = rawequal, rawget = rawget, rawlen = rawlen, rawset = rawset, select = select,\n"
"  setmetatable = setmetatable, getmetatable = getmetatable, tonumber = tonumber, tostring = tostring,\n"
"  type = type, xpcall = xpcall, unpack = table.unpack,\n"
"  math = math, string = string, table = table, utf8 = utf8,\n"
"  memory = memory, print = api.log,\n"
"}\n"
"for k, v in pairs(api) do env[k] = v end\n"
"env._G = env\n"
"local chunk, err = load(script, 'script', 't', env)\n"
"if not chunk then error(err, 0) end\n"
"chunk()\n"
"if type(env.trade) ~= 'function' then error('the script defines no trade(market) function', 0) end\n"
"env.trade(market)\n"
"-- What it kept, as a table constructor: strings, numbers, booleans and tables of them\n"
"local out, size = {}, 0\n"
"local function ser(v, depth)\n"
"  local t = type(v)\n"
"  if t == 'number' then\n"
"    if v ~= v or v == math.huge or v == -math.huge then return '0' end\n"
"    return math.type(v) == 'integer' and tostring(v) or string.format('%.17g', v)\n"
"  elseif t == 'string' then return string.format('%q', v):gsub('\\n', 'n')\n"
"  elseif t == 'boolean' then return tostring(v)\n"
"  elseif t == 'table' and depth < 8 then\n"
"    local parts = {}\n"
"    for k, x in pairs(v) do\n"
"      local kt = type(k)\n"
"      if (kt == 'string' or kt == 'number') and (type(x) ~= 'function' and type(x) ~= 'userdata' and type(x) ~= 'thread') then\n"
"        parts[#parts + 1] = '[' .. ser(k, depth + 1) .. ']=' .. ser(x, depth + 1)\n"
"      end\n"
"    end\n"
"    return '{' .. table.concat(parts, ',') .. '}'\n"
"  end\n"
"  return 'nil'\n"
"end\n"
"local kept = ser(env.memory, 0)\n"
"if #kept > 65536 then error('memory holds more than 64 KB', 0) end\n"
"emit('memory', 'return ' .. kept)\n";

int main(int argc, char **argv)
{
    if(argc < 3) {
        fprintf(stderr, "usage: lua-trade <script.lua> <market.lua> [<memory.lua>]\n");
        return 2;
    }
    char *script = slurp(argv[1]);
    char *market = slurp(argv[2]);
    char *memory = argc > 3 ? slurp(argv[3]) : NULL;
    if(!script || !market) {
        printf("error\tthe script or the market could not be read\n");
        return 1;
    }

    lua_State *L = lua_newstate(limited_alloc, NULL);
    if(!L) return 1;
    /* Only the safe libraries; the prelude keeps load for itself */
    luaL_requiref(L, LUA_GNAME, luaopen_base, 1); lua_pop(L, 1);
    luaL_requiref(L, LUA_TABLIBNAME, luaopen_table, 1); lua_pop(L, 1);
    luaL_requiref(L, LUA_STRLIBNAME, luaopen_string, 1); lua_pop(L, 1);
    luaL_requiref(L, LUA_MATHLIBNAME, luaopen_math, 1); lua_pop(L, 1);
    luaL_requiref(L, LUA_UTF8LIBNAME, luaopen_utf8, 1); lua_pop(L, 1);

    if(luaL_loadbuffer(L, PRELUDE, strlen(PRELUDE), "=trade") != LUA_OK) {
        printf("error\t%s\n", lua_tostring(L, -1));
        return 1;
    }
    lua_pushcfunction(L, emit);
    lua_pushstring(L, script);
    lua_pushstring(L, market);
    lua_pushstring(L, memory ? memory : "");
    lua_sethook(L, count_hook, LUA_MASKCOUNT, HOOK_EVERY);
    int status = lua_pcall(L, 4, 0, 0);
    if(status != LUA_OK) {
        const char *msg = lua_tostring(L, -1);
        printf("error\t");
        for(const char *c = msg ? msg : (status == LUA_ERRMEM ? "the script used too much memory" : "it failed"); *c; c++)
            putchar(*c == '\t' || *c == '\n' ? ' ' : *c);
        putchar('\n');
        lua_close(L);
        return 1;
    }
    lua_close(L);
    free(script); free(market); free(memory);
    return 0;
}
