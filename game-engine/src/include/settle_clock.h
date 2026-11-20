#ifndef __EM_SETTLE_CLOCK_MODULE__
#define __EM_SETTLE_CLOCK_MODULE__

/*
 * When the game engine settles events between turns (#694 stage 4 step 2,
 * EVENTS_IN_ENGINE=1). One process now does both, so neither may starve
 * the other. The rule, in real milliseconds:
 *
 *   - the first pass is due at once;
 *   - while turns are waiting, a pass is never due sooner after the last
 *     one ended than that pass took: a slow pass (a full batch, an archive
 *     window) leaves turns at least as much time again, so settlement gets
 *     at most about half the engine while both have work;
 *   - then a pass is due at once if the last one read a full batch (more
 *     events are due), or else once SETTLE_INTERVAL_MS has passed since the
 *     last one started;
 *   - with no turns waiting (the engine resting), a full batch is followed
 *     at once by the next pass, as bin/events-engine did.
 *
 * Pure, so test_settle_clock.c checks it without a database.
 */

#define SETTLE_INTERVAL_MS 50   /* a pass about every 50 real ms */

typedef struct
{
    long long interval_ms;   /* SETTLE_INTERVAL_MS */
    int batch;               /* the events engine's batch size (OUTCOME_EVENT_BATCH_SIZE) */
    int ran;                 /* a pass has run */
    long long started_ms;    /* when the last pass started */
    long long ended_ms;      /* and ended */
    int found;               /* the due events it read */
} SettleClock_t;

/* Is a pass due at now_ms? idle: no turns are waiting */
int settle_clock_due(const SettleClock_t *clock, long long now_ms, int idle);

/* While resting (no turns waiting): real ms until the next pass is due (0: now) */
long long settle_clock_wait(const SettleClock_t *clock, long long now_ms);

/* A pass ran from started_ms to ended_ms and read found due events */
void settle_clock_done(SettleClock_t *clock, long long started_ms, long long ended_ms, int found);

/* EVENTS_IN_ENGINE from the environment: 1 when the game engine settles
   (shard 0's) and bin/events-engine rests; anything else, as before */
int settle_flag_on(void);

#endif
