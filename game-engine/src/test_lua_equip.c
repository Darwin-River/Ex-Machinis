/*
 * equip() in the Lua drones' library (#726), run against a pretend drone:
 * query, perform and print are Lua stand-ins, so no engine or database.
 *
 * Round 70: a drone a game day past unpacking its Factory Kit printed
 * "equip: tool 5001 of kit 5902 is missing: buying the kit here". units()
 * read 0 for a tool that was aboard: a query that failed (it returns nil,
 * which units() took for 0), or the game second a tool is out of the hold
 * while it is put to work or put back. Neither may say "missing" or buy.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "lua.h"
#include "lauxlib.h"
#include "lualib.h"

static int failures = 0;

/* The pretend drone: what it holds, which reads of units() fail or read 0
   for a while, and what it printed and performed */
static const char* world =
    "t_hold = {} t_fail = {} t_blink = {} t_empty = {} t_printed = {} t_performed = {} t_parent = 0\n"
    "function query(id, ...)\n"
    "  local a = {...}\n"
    "  if id == 212 then\n"
    "    local r = a[1]\n"
    "    if (t_fail[r] or 0) > 0 then t_fail[r] = t_fail[r] - 1 return nil, -2 end\n"
    "    if (t_blink[r] or 0) > 0 then t_blink[r] = t_blink[r] - 1 return {{0}} end\n"
    "    if (t_empty[r] or 0) > 0 then t_empty[r] = t_empty[r] - 1 return {} end\n"
    "    return {{t_hold[r] or 0}}\n"
    "  elseif id == 671 then return {{t_parent, 1}}\n"
    "  elseif id == 205 then return {{77}}\n"
    "  elseif id == 202 then return {{3}}\n"   /* at Earth */
    "  elseif id == 201 then return {{9}}\n"
    "  end\n"
    "  return {}\n"
    "end\n"
    "function perform(p, ...)\n"
    "  table.insert(t_performed, p)\n"
    "  if #t_performed > 50 then error('equip never finished') end\n"
    "  if p == 3 and t_buys then t_hold[5902] = 1 end\n"            /* a kit turns up for sale and is bought */
    "  if p == 25902 then t_hold[5902] = 0 for _, t in ipairs({5001, 5201, 5211, 5501}) do t_hold[t] = 1 end end\n"
    "  return 0\n"
    "end\n"
    "function print(s) table.insert(t_printed, s) end\n";

static lua_State* drone(const char* library)
{
    lua_State* L = luaL_newstate();
    luaL_openlibs(L);
    if(luaL_dostring(L, world) != LUA_OK || luaL_loadbuffer(L, library, strlen(library), "=library") != LUA_OK
       || lua_pcall(L, 0, 0, 0) != LUA_OK)
    {
        fprintf(stderr, "test_lua_equip: %s\n", lua_tostring(L, -1));
        exit(1);
    }
    return L;
}

/* Runs a scenario: setup, then equip(5902); checks are Lua that returns true or an error message */
static void scenario(const char* library, const char* name, const char* setup, const char* check)
{
    lua_State* L = drone(library);
    char script[2048];
    snprintf(script, sizeof(script), "%s\nequip(5902)\nlocal ok, why = (function() %s end)()\n"
             "if not ok then error(why or 'failed') end", setup, check);
    if(luaL_dostring(L, script) != LUA_OK)
    {
        fprintf(stderr, "FAIL %s: %s\n", name, lua_tostring(L, -1));
        failures++;
    }
    else
        printf("ok   %s\n", name);
    lua_close(L);
}

static char* read_file(const char* path)
{
    FILE* f = fopen(path, "rb");
    if(!f) { perror(path); exit(1); }
    fseek(f, 0, SEEK_END);
    long n = ftell(f);
    fseek(f, 0, SEEK_SET);
    char* s = malloc(n + 1);
    if(!s || fread(s, 1, n, f) != (size_t)n) { fprintf(stderr, "%s: can't read\n", path); exit(1); }
    s[n] = 0;
    fclose(f);
    return s;
}

int main(void)
{
    char* library = read_file("lua_library.lua");
    const char* tools = "for _, t in ipairs({5001, 5201, 5211, 5501}) do t_hold[t] = 1 end";
    char setup[512];

    /* Everything aboard: equip returns at once, says nothing */
    scenario(library, "all tools aboard", tools, "return #t_printed == 0 and #t_performed == 0, 'it acted'");

    /* The query for the Engine fails twice: not missing, nothing bought */
    snprintf(setup, sizeof(setup), "%s t_fail[5001] = 2", tools);
    scenario(library, "a failed query is not a missing tool", setup,
             "return #t_printed == 0, t_printed[1] or ''");

    /* The Engine reads 0 once (a game second at the end of a flight) */
    snprintf(setup, sizeof(setup), "%s t_blink[5001] = 1", tools);
    scenario(library, "a tool out of the hold for a moment is not missing", setup,
             "return #t_printed == 0, t_printed[1] or ''");

    /* The query answers no row (it failed too, #743): not missing */
    snprintf(setup, sizeof(setup), "%s t_empty[5001] = 3", tools);
    scenario(library, "a query with no row is not a missing tool", setup,
             "return #t_printed == 0, t_printed[1] or ''");

    /* The kit aboard: unpacked, nothing said */
    scenario(library, "a kit aboard is unpacked", "t_hold[5902] = 1",
             "return #t_printed == 0 and t_performed[1] == 25902, 'not unpacked'");

    /* Truly missing, no kit: said once, then a kit is bought and unpacked */
    scenario(library, "a missing tool is said once and the kit bought", "t_buys = true",
             "return #t_printed == 1 and t_printed[1]:find('tool 5001 of kit 5902 is missing', 1, true) ~= nil "
             "and t_hold[5001] == 1, t_printed[1] or 'nothing printed'");

    /* A clone waits for its parent's kit, even when a query failed once */
    scenario(library, "a clone never buys", "t_parent = 5 t_buys = true",
             "return #t_printed == 0, t_printed[1] or ''");

    free(library);
    if(failures)
    {
        fprintf(stderr, "test_lua_equip: %d failed\n", failures);
        return 1;
    }
    printf("test_lua_equip: all passed\n");
    return 0;
}
