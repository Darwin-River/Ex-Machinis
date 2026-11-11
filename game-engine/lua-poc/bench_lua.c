/* Times score.lua's run in Lua, with and without the metering hook (#380) */
#define _POSIX_C_SOURCE 200809L
#include <stdio.h>
#include <time.h>
#include "lua.h"
#include "lauxlib.h"
#include "lualib.h"
#include "lstate.h"
static long long counted;
static void hook(lua_State *L, lua_Debug *ar) { (void)L; (void)ar; counted += 1000; }
static double secs(void) { struct timespec t; clock_gettime(CLOCK_MONOTONIC, &t); return t.tv_sec + t.tv_nsec / 1e9; }
static double run(lua_State *L, int metered)
{
    counted = 0;
    if(metered) lua_sethook(L, hook, LUA_MASKCOUNT, 1000); else lua_sethook(L, NULL, 0, 0);
    double t0 = secs();
    lua_getglobal(L, "run");
    lua_call(L, 0, 0);
    double t = secs() - t0;
    if(metered) counted += L->basehookcount - L->hookcount;
    return t;
}
int main(void)
{
    lua_State *L = luaL_newstate();
    luaL_openlibs(L);
    if(luaL_dofile(L, "score.lua")) { fprintf(stderr, "%s\n", lua_tostring(L, -1)); return 1; }
    run(L, 1);   /* warm up */
    double tm = run(L, 1);
    long long n = counted;
    double tf = run(L, 0);
    printf("lua: %lld instructions for 1,000 rounds of scoring 8 products (%lld a round), %.2f ms metered (%.1f M a second), %.2f ms unmetered\n",
           n, n / 1000, tm * 1000, n / tm / 1e6, tf * 1000);
    return 0;
}
