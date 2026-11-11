#include <stdio.h>
#include "lua.h"
#include "lauxlib.h"
#include "lualib.h"
static int act(lua_State *L){return lua_yield(L,0);}
int main(){lua_State*L=luaL_newstate();luaL_openlibs(L);lua_register(L,"wait",act);
 luaL_loadstring(L,"rounds=0 function launched() while true do rounds=rounds+1 wait() end end");
 lua_pushvalue(L,-1);lua_pcall(L,0,0,0);              /* chunk stays below */
 int env[2]; lua_State*co[2];
 for(int i=0;i<2;i++){lua_newtable(L);lua_newtable(L);lua_pushglobaltable(L);lua_setfield(L,-2,"__index");lua_setmetatable(L,-2);
  env[i]=luaL_ref(L,LUA_REGISTRYINDEX); co[i]=lua_newthread(L); luaL_ref(L,LUA_REGISTRYINDEX); lua_getglobal(co[i],"launched");}
 int order[]={0,0,1,0,1,0,0,1}; int nr;
 for(int k=0;k<8;k++){int i=order[k]; lua_rawgeti(L,LUA_REGISTRYINDEX,env[i]); lua_setupvalue(L,-2,1); lua_resume(co[i],L,0,&nr);}
 for(int i=0;i<2;i++){lua_rawgeti(L,LUA_REGISTRYINDEX,env[i]);lua_getfield(L,-1,"rounds");printf("clone %d rounds %lld\n",i,lua_tointeger(L,-1));lua_pop(L,2);}
 lua_getglobal(L,"rounds"); printf("shared rounds %lld\n", lua_tointeger(L,-1));}
