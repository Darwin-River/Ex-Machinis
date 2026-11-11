/*
 * The clock floor (#324 step 20). See clock_floor.h.
 */
#include "clock_floor.h"

double clock_floor_next(double scale, double lag, const ClockRule_t *rule, int *full)
{
    double next = scale;

    // A floor of 0 (or at or above the target): the clock never floats
    if(!(rule->floor > 0) || rule->floor >= rule->target) {
        *full = 0;
        return rule->target;
    }

    if(lag > rule->lag_high) {
        // Already at the floor and still behind: the world is full
        *full = scale <= rule->floor * 1.000001;
        next = scale * CLOCK_SLOW_FACTOR;
    } else if(lag < rule->lag_low) {
        *full = 0;
        next = scale * CLOCK_SPEED_FACTOR;
    }

    if(next < rule->floor) next = rule->floor;
    if(next > rule->target) next = rule->target;
    return next;
}

double clock_floor_lag(long runnable, long slices, double seconds, double oldest_event_late)
{
    double lag = 0;

    if(runnable > 0) {
        // At the rate commands ran, the last one waiting runs this much later
        // (none ran: all period long, at least)
        lag = slices > 0 && seconds > 0 ? runnable / (slices / seconds) : seconds;
    }
    if(oldest_event_late > lag)
        lag = oldest_event_late;
    return lag;
}
