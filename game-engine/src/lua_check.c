/*
 * lua-check (#380): reads a Lua drone's script on standard input and
 * compiles it, without running it. Prints nothing and exits 0 when it
 * compiles; else prints Lua's message ("script:12: ...") and exits 1. The
 * website's /check and Release page use it (config forge.lua_check).
 */
#include <stdio.h>
#include <stdlib.h>
#include "lua.h"
#include "lauxlib.h"

int main(void)
{
    size_t size = 0, cap = 65536;
    char *text = malloc(cap);
    size_t n;
    while(text && (n = fread(text + size, 1, cap - size, stdin)) > 0) {
        size += n;
        if(size == cap && size < 4 * 1024 * 1024) text = realloc(text, cap *= 2);
        else if(size == cap) break;
    }
    if(!text) return 2;
    lua_State *L = luaL_newstate();
    if(!L) return 2;
    if(luaL_loadbuffer(L, text, size, "=script") != LUA_OK) {
        printf("%s\n", lua_tostring(L, -1));
        return 1;
    }
    return 0;
}
