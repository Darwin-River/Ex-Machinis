/*
 * lua-check (#380): reads a Lua drone's script on standard input and
 * compiles it, without running it.
 *
 * - A syntax error: prints Lua's message ("script:12: ...") and exits 1.
 * - Otherwise exits 0, after a warning line for each global name the script
 *   reads that nothing defines: not the game's library (lua-check
 *   <library.lua> loads it to learn its names), not Lua's own, and not one
 *   the script sets anywhere ("warning: line 12: crowed is used but never
 *   defined"). A misspelt game word otherwise fails only once the drone runs
 *   it (#380 round 35), as FORTH's undefined-word warning catches for FORTH.
 *
 * The website's /check and Release page use it (config forge.lua_check).
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "lua.h"
#include "lauxlib.h"
#include "lualib.h"
#include "lobject.h"
#include "lstate.h"
#include "lopcodes.h"
#include "ldebug.h"

#define MAX_NAMES 4096

typedef struct { const char *name; int line; } Use;
static Use reads[MAX_NAMES];
static int nreads = 0;
static const char *writes[MAX_NAMES];
static int nwrites = 0;

static int is_env(const Proto *p, int up)
{
    if(up < 0 || up >= p->sizeupvalues || !p->upvalues[up].name)
        return 0;
    return strcmp(getstr(p->upvalues[up].name), "_ENV") == 0;
}

static const char *kstring(const Proto *p, int k)
{
    if(k < 0 || k >= p->sizek || !ttisstring(&p->k[k]))
        return NULL;
    return getstr(tsvalue(&p->k[k]));
}

/* Every global read and write in a function and those inside it */
static void scan(const Proto *p)
{
    for(int pc = 0; pc < p->sizecode; pc++) {
        Instruction i = p->code[pc];
        OpCode op = GET_OPCODE(i);
        if(op == OP_GETTABUP && is_env(p, GETARG_B(i))) {
            const char *name = kstring(p, GETARG_C(i));
            if(name && nreads < MAX_NAMES) {
                reads[nreads].name = name;
                reads[nreads].line = luaG_getfuncline(p, pc);
                nreads++;
            }
        } else if(op == OP_SETTABUP && is_env(p, GETARG_A(i))) {
            const char *name = kstring(p, GETARG_B(i));
            if(name && nwrites < MAX_NAMES)
                writes[nwrites++] = name;
        }
    }
    for(int f = 0; f < p->sizep; f++)
        scan(p->p[f]);
}

static int noop(lua_State *L) { (void)L; return 0; }

/* A state with what a drone has: Lua's libraries and the game's */
static lua_State *drone_like(const char *library)
{
    lua_State *L = luaL_newstate();
    luaL_requiref(L, LUA_GNAME, luaopen_base, 1);
    luaL_requiref(L, LUA_STRLIBNAME, luaopen_string, 1);
    luaL_requiref(L, LUA_TABLIBNAME, luaopen_table, 1);
    luaL_requiref(L, LUA_MATHLIBNAME, luaopen_math, 1);
    luaL_requiref(L, LUA_UTF8LIBNAME, luaopen_utf8, 1);
    lua_pop(L, 5);
    const char *prims[] = { "print", "report", "perform", "query", "set_status", NULL };
    for(int i = 0; prims[i]; i++) {
        lua_pushcfunction(L, noop);
        lua_setglobal(L, prims[i]);
    }
    if(library && luaL_dofile(L, library) != LUA_OK)
        fprintf(stderr, "lua-check: the library didn't load: %s\n", lua_tostring(L, -1));
    lua_settop(L, 0);
    return L;
}

static int known(lua_State *L, const char *name)
{
    lua_getglobal(L, name);
    int found = !lua_isnil(L, -1);
    lua_pop(L, 1);
    return found;
}

int main(int argc, char **argv)
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

    lua_State *L = drone_like(argc > 1 ? argv[1] : NULL);
    if(!L) return 2;
    if(luaL_loadbuffer(L, text, size, "=script") != LUA_OK) {
        printf("%s\n", lua_tostring(L, -1));
        return 1;
    }
    /* Names: only with the library to compare with */
    if(argc < 2)
        return 0;
    const LClosure *cl = clLvalue(s2v(L->top.p - 1));
    scan(cl->p);
    for(int r = 0; r < nreads; r++) {
        const char *name = reads[r].name;
        int seen = 0;
        for(int e = 0; e < r && !seen; e++)
            if(!strcmp(reads[e].name, name)) seen = 1;   /* one warning a name */
        for(int w = 0; w < nwrites && !seen; w++)
            if(!strcmp(writes[w], name)) seen = 1;
        if(!seen && !known(L, name))
            printf("warning: line %d: %s is used but never defined\n", reads[r].line, name);
    }
    return 0;
}
