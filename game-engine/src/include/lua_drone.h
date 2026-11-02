/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Lua drones (#380): a drone whose program is Lua instead of
               FORTH. See lua_drone.c.

******************************************************************************/
#ifndef __EM_LUA_DRONE_MODULE__
#define __EM_LUA_DRONE_MODULE__

#include <stddef.h>
#include "common_types.h"

/* A Lua instruction does about six of the FORTH VM's in work (#380: the
   Systems shop's scoring took 374 Lua instructions a round against 2,149),
   so each is charged as six: slices and fuel stay what they were */
#define LUA_INSTRUCTION_WEIGHT 6

/* The most memory a drone's Lua may hold */
#define LUA_MEMORY_CAP (256 * 1024)

/* What a run of a Lua drone was for */
typedef enum {
    LUA_RUN_PIECE,    /* a piece of the released script: kept, not run */
    LUA_RUN_LAUNCH,   /* <launched>: build the drone and start launched() */
    LUA_RUN_RESUME    /* a paused script carries on */
} LuaRun_t;

/* Runs a Lua drone's command (the engine has set the slice's budget). Its
   output goes to out_buf. Pauses (an action, a used-up slice) show in
   vm_is_yield(), as for a FORTH drone. */
ErrorCode_t lua_drone_command(int agent_id, LuaRun_t kind, const char* code, char* out_buf, size_t out_size);

/* perform's result for a paused drone, handed back when it resumes */
void lua_drone_set_result(int agent_id, int code);

/* After a run that didn't pause: 0 when launched() returned, else the
   crash's code (negative). Leaves the drone stopped. */
int lua_drone_take_result(int agent_id);

/* Forgets a drone's running script (abort, reset, a crash's restart) */
void lua_drone_drop(int agent_id);

/* Forgets its saved variables too: a crash restarts from a clean copy */
void lua_drone_forget_state(int agent_id);

/* Marks a released Lua drone as loaded (release_vm no longer NULL), so it
   gets normal slices and no loading priority */
void lua_drone_mark_released(int agent_id);

/* Clears its script (reset) */
void lua_drone_clear_script(int agent_id);

/* The standard library every Lua drone loads before its script
   (lua_library.c) */
extern const char* lua_library_source;

#endif
