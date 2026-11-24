/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Limits on a Lua drone (#380, #1035), in a header of their own
               so the website's lua-check can use them without the engine's.

******************************************************************************/
#ifndef __EM_LUA_LIMITS__
#define __EM_LUA_LIMITS__

/* The most memory a drone's Lua may hold: the game's library (about 113 KB
   with Lua's own), its script once compiled, and its data */
#define LUA_MEMORY_CAP (256 * 1024)

/* What a released script must leave of LUA_MEMORY_CAP for its data (#1035):
   tables, strings and query results while it runs */
#define LUA_DATA_HEADROOM (64 * 1024)

#endif
