#ifndef __EM_SETTLE_MODULE__
#define __EM_SETTLE_MODULE__

/*
 * Settlement inside the game engine (#694 stage 4 step 2). With
 * EVENTS_IN_ENGINE=1, shard 0's game engine runs the events engine's passes
 * (ee_pass) between turns, on a second database connection of its own and
 * under the events engine's lock, and bin/events-engine rests. Off (the
 * default), these do nothing but rest as the engine always has. See
 * settle.c, and settle_clock.h for when a pass runs.
 */

#include "trace.h"

/* At start, after the engine's own database connection: reads the flag
   and, when on and this is shard 0, gets the events engine ready */
void settle_init(const char* plat_home, TraceHndl_t trace);

/* After a turn has committed (or rolled back): a pass, if one is due */
void settle_between_turns(void);

/* The engine has nothing to run: rest this many seconds, settling meanwhile
   when on (off: sleep(seconds), as before) */
void settle_rest(unsigned int seconds);

/* At stop: closes the settlement connection */
void settle_stop(void);

#endif
