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

/** Real seconds that a game duration takes (rounded, never negative) */
int game_time_to_real(double game_seconds);

/** Real seconds a light signal takes to cross distance_km */
int game_time_light_delay(double distance_km);

#endif
