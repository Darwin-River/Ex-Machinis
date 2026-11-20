/* When the game engine settles events between turns (see settle_clock.h) */
#include <stdlib.h>

#include "settle_clock.h"

int settle_clock_due(const SettleClock_t *clock, long long now_ms, int idle)
{
    if(!clock->ran)
        return 1;
    long long took = clock->ended_ms - clock->started_ms;
    // Turns waiting: as much time for them as the last pass took
    if(!idle && now_ms - clock->ended_ms < took)
        return 0;
    if(clock->batch > 0 && clock->found >= clock->batch)
        return 1;
    return now_ms - clock->started_ms >= clock->interval_ms;
}

long long settle_clock_wait(const SettleClock_t *clock, long long now_ms)
{
    if(settle_clock_due(clock, now_ms, 1))
        return 0;
    return clock->started_ms + clock->interval_ms - now_ms;
}

void settle_clock_done(SettleClock_t *clock, long long started_ms, long long ended_ms, int found)
{
    clock->ran = 1;
    clock->started_ms = started_ms;
    clock->ended_ms = ended_ms < started_ms ? started_ms : ended_ms;
    clock->found = found;
}

int settle_flag_on(void)
{
    const char *v = getenv("EVENTS_IN_ENGINE");
    return v && atoi(v) == 1;
}
