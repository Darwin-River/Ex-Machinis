/* Memory per drone: a lua_State each, or one state per script with each
   clone a coroutine whose globals are its own (the chunk's _ENV upvalue is
   switched to the clone's table before each resume) */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "lua.h"
#include "lauxlib.h"
#include "lualib.h"
static size_t used;
static void *al(void *ud, void *p, size_t o, size_t n) { (void)ud; if(p) used -= o; if(!n) { free(p); return NULL; } used += n; return realloc(p, n); }
static int stub(lua_State *L) { lua_pushinteger(L, 50); return 1; }
static int act(lua_State *L) { return lua_yield(L, 0); }
static void words(lua_State *L) {
  const char *q[] = {"units","credits","here","sell_price","buy_price","board_price",0};
  const char *a[] = {"make","buy_here","sell_here","go","wait",0};
  for(int i=0;q[i];i++){lua_pushcfunction(L,stub);lua_setglobal(L,q[i]);}
  for(int i=0;a[i];i++){lua_pushcfunction(L,act);lua_setglobal(L,a[i]);}
  const char *n[]={"fuel","metals_a1","alloys_a1","rods","moon","earth",0};
  for(int i=0;n[i];i++){lua_pushinteger(L,i+1);lua_setglobal(L,n[i]);}
}
static void libs(lua_State *L){luaL_requiref(L,"_G",luaopen_base,1);luaL_requiref(L,"string",luaopen_string,1);luaL_requiref(L,"table",luaopen_table,1);luaL_requiref(L,"math",luaopen_math,1);lua_pop(L,4);}
int main(int argc,char**argv){
  int N=argc>1?atoi(argv[1]):1000; FILE*f=fopen("maker.lua","rb"); if(!f) return 1; char src[8192]; size_t len=fread(src,1,sizeof src,f); fclose(f);
  /* A: a state each */
  used=0; lua_State **S=malloc(N*sizeof*S);
  for(int i=0;i<N;i++){S[i]=lua_newstate(al,0);libs(S[i]);words(S[i]);luaL_loadbuffer(S[i],src,len,"m");lua_pcall(S[i],0,0,0);
    lua_State*co=lua_newthread(S[i]);lua_setfield(S[i],LUA_REGISTRYINDEX,"t");lua_getglobal(co,"launched");int nr;for(int k=0;k<20;k++)lua_resume(co,S[i],0,&nr);
    lua_gc(S[i],LUA_GCCOLLECT);}
  printf("a state each: %zu bytes a drone\n", used/N);
  for(int i=0;i<N;i++)lua_close(S[i]);
  /* B: one state for the script; each clone a coroutine with its own globals */
  used=0; lua_State*L=lua_newstate(al,0); libs(L); words(L);
  luaL_loadbuffer(L,src,len,"m");             /* the chunk */
  lua_pushvalue(L,-1); lua_setfield(L,LUA_REGISTRYINDEX,"chunk");
  lua_pcall(L,0,0,0);                         /* defines the functions in _G */
  size_t base=used;
  lua_getfield(L,LUA_REGISTRYINDEX,"chunk");
  for(int i=0;i<N;i++){
    /* the clone's globals: its own table, falling back to the shared ones */
    lua_newtable(L); lua_newtable(L); lua_pushglobaltable(L); lua_setfield(L,-2,"__index"); lua_setmetatable(L,-2);
    lua_pushvalue(L,-1); lua_setupvalue(L,-3,1);   /* _ENV of every function of the chunk */
    lua_State*co=lua_newthread(L); char k[32]; snprintf(k,32,"t%d",i); lua_setfield(L,LUA_REGISTRYINDEX,k);
    snprintf(k,32,"e%d",i); lua_setfield(L,LUA_REGISTRYINDEX,k);
    lua_getglobal(co,"launched"); int nr; for(int s=0;s<20;s++) lua_resume(co,L,0,&nr);
  }
  lua_gc(L,LUA_GCCOLLECT);
  printf("one state, a coroutine each: %zu bytes shared, %zu bytes a drone\n", base, (used-base)/N);
  return 0;
}
