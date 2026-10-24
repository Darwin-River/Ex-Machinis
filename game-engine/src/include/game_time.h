#ifndef __EM_GAME_TIME_MODULE__
#define __EM_GAME_TIME_MODULE__

#include "common_types.h"

/** Reads the game clock (game_clock table), creating it on first start */
ErrorCode_t game_time_load(void);

/** Sets the master scale (game seconds per real second), re-anchoring the
 *  clock so game time carries on without a jump */
ErrorCode_t game_time_set_scale(double scale);

/** Game seconds per real second */
double game_time_scale(void);

/** Current game time, in seconds since 1970 (game calendar) */
double game_time_now(void);

/** Current game time, rounded to whole game seconds: what the engines store
 *  in game_t columns and compare against */
long long game_time_now_seconds(void);

/** The world's first game second: drone dates count days from it */
double game_time_epoch(void);

/** Game seconds a light signal takes to cross distance_km */
long long game_time_light_seconds(double distance_km);

/** Real seconds that a game duration takes (rounded, never negative) */
int game_time_to_real(double game_seconds);

/** Real seconds a light signal takes to cross distance_km */
int game_time_light_delay(double distance_km);

#endif
