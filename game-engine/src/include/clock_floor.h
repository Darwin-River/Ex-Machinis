#ifndef __EM_CLOCK_FLOOR_MODULE__
#define __EM_CLOCK_FLOOR_MODULE__

/*
 * The clock floor (#324 step 20). Once a minute the game engine measures how
 * far behind the engines run (lag, in real seconds) and sets the game
 * clock's scale from it: slower when they fall behind, so actions take
 * longer in real time and drones ask for fewer slices; back up towards the
 * target when they catch up; never below the floor. Behind at the floor,
 * the world is full and new releases queue.
 */

#define CLOCK_CHECK_SECONDS 60     /* how often the engine checks */
#define CLOCK_SLOW_FACTOR   0.75   /* behind: the next scale is this much of it */
#define CLOCK_SPEED_FACTOR  1.25   /* caught up: this much more */

typedef struct
{
    double target;     /* the scale the clock aims for (GAME_TIME_SCALE) */
    double floor;      /* the least it slows to (GAME_TIME_FLOOR; 0: it never floats) */
    double lag_high;   /* behind by more than this (real seconds): slow down */
    double lag_low;    /* behind by less than this: speed back up */
} ClockRule_t;

/* The scale after a check, from the current scale and lag. *full says the
   world is full (at the floor and still behind); it stays as it was while
   the lag is between lag_low and lag_high. */
double clock_floor_next(double scale, double lag, const ClockRule_t *rule, int *full);

/* How far behind the engines run, in real seconds: the commands waiting to
   run at the rate they ran, or the oldest due event, whichever is later */
double clock_floor_lag(long runnable, long slices, double seconds, double oldest_event_late);

#endif
