/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Settlement inside the game engine (#694 stage 4 step 2,
               EVENTS_IN_ENGINE=1). See settle.h.

  How settlement reaches the database, and how its lines show in the log:

  - Its own connection. The events engine's files (event.c, db_e.c,
    db_station.c...) already ask ee_engine_get_db_connection() for theirs,
    which is engine_e.c's ee_engine.db_connection: a second connection,
    opened here, not the turns'. So a pass's transactions are never a turn's,
    and a pass runs only between turns, after the turn has committed or
    rolled back and given up its event-order locks: one thread never holds
    row locks on one connection while waiting for them on the other.

  - The shared files (db_common.c, game_time.c, orbits.c) ask
    engine_get_db_connection(), which in this program is engine.c's. During
    a pass, settle_pass sets engine.c's "current settlement connection" to
    the events engine's, and engine_get_db_connection() returns it until
    the pass ends; between passes it is NULL and the turns' connection is
    returned, as before. A pointer set around the pass, rather than passing
    the connection to every shared function: those functions are called
    from dozens of places in both engines, and the pointer leaves all of
    them, and bin/events-engine, unchanged.

  - The log. The events engine's own lines (ee_engine_trace) go to the game
    engine's log file with an "[events] " prefix (engine_e.c's embedded
    mode), and so do the shared files' lines while a pass runs
    (engine_trace, while the settlement connection is current). One log, so
    the order of turns and settlement is plain to see, and `grep "\[events\]"`
    picks out settlement.

  - The lock. Only one settler may run on a database: the pass takes the
    events engine's named lock ("events-engine", db_common.c) on its own
    connection, without waiting, and tries again every few seconds until it
    holds it (a bin/events-engine still stopping may have it). Nothing
    settles until then. A reconnect re-takes it (db_reconnect), as the
    events engine always has.

******************************************************************************/

#include <stdlib.h>
#include <time.h>
#include <unistd.h>

#include "engine.h"
#include "ee_embed.h"
#include "settle.h"
#include "settle_clock.h"

/* How often to try for the events engine's lock while another holds it */
#define SETTLE_CLAIM_EVERY_SECONDS 5

static int g_on = 0;             /* this engine settles */
static SettleClock_t g_clock;
static time_t g_claim_tried_at = 0;
static int g_claim_warned = 0;

static long long settle_now_ms(void)
{
    struct timespec t;
    clock_gettime(CLOCK_MONOTONIC, &t);
    return (long long)t.tv_sec * 1000 + t.tv_nsec / 1000000;
}

void settle_init(const char* plat_home, TraceHndl_t trace)
{
    if(!settle_flag_on())
        return;
    // One settler: shard 0's game engine (#433: it does the shared work)
    if(engine_shard() != 0) {
        engine_trace(TRACE_LEVEL_ALWAYS, "NOTICE: EVENTS_IN_ENGINE=1: shard 0 settles events, not this shard");
        return;
    }
    if(ee_embed_init(plat_home, trace) != ENGINE_OK) {
        engine_trace(TRACE_LEVEL_ALWAYS,
            "ERROR: EVENTS_IN_ENGINE=1 but the events engine's configuration (conf/events-engine.conf) "
            "didn't load: nothing settles");
        return;
    }
    g_on = 1;
    g_clock.interval_ms = SETTLE_INTERVAL_MS;
    g_clock.batch = engine_get_outcome_events_batch_size();
    engine_trace(TRACE_LEVEL_ALWAYS,
        "NOTICE: EVENTS_IN_ENGINE=1: this game engine settles events between turns, on its own "
        "connection, about every %d ms (batches of %d)", SETTLE_INTERVAL_MS, g_clock.batch);
}

/* The events engine's lock, tried without waiting at most every few seconds */
static int g_claim_said = 0;
static int settle_claim(void)
{
    // The settlement connection takes the lock as it connects, as the events
    // engine's always has: say so once, however it came to be held (the smoke
    // test looks for this line)
    if(ee_embed_claimed()) {
        if(!g_claim_said) {
            engine_trace(TRACE_LEVEL_ALWAYS, "NOTICE: [events] settlement holds the events-engine lock: settling");
            g_claim_said = 1;
        }
        return 1;
    }
    time_t now = time(NULL);
    if(g_claim_tried_at && now - g_claim_tried_at < SETTLE_CLAIM_EVERY_SECONDS)
        return 0;
    g_claim_tried_at = now;

    engine_set_settlement_connection(ee_embed_connection());
    ErrorCode_t claimed = ee_embed_claim();
    engine_set_settlement_connection(NULL);

    if(claimed == ENGINE_OK) {
        engine_trace(TRACE_LEVEL_ALWAYS, "NOTICE: [events] settlement holds the events-engine lock: settling");
        g_claim_said = 1;
        g_claim_warned = 0;
        return 1;
    }
    if(!g_claim_warned) {
        engine_trace(TRACE_LEVEL_ALWAYS,
            "WARNING: [events] no events-engine lock yet (is a bin/events-engine without EVENTS_IN_ENGINE=1 "
            "still settling?): trying every %d s", SETTLE_CLAIM_EVERY_SECONDS);
        g_claim_warned = 1;
    }
    return 0;
}

static void settle_pass(void)
{
    if(!settle_claim())
        return;
    int found = 0;
    long long started = settle_now_ms();
    engine_set_settlement_connection(ee_embed_connection());
    ee_pass(&found);
    engine_set_settlement_connection(NULL);
    settle_clock_done(&g_clock, started, settle_now_ms(), found);
}

void settle_between_turns(void)
{
    if(g_on && settle_clock_due(&g_clock, settle_now_ms(), 0))
        settle_pass();
}

void settle_rest(unsigned int seconds)
{
    if(!g_on) {
        sleep(seconds);
        return;
    }
    // Nothing to run: settle while resting, at once after a full batch
    long long until = settle_now_ms() + (long long)seconds * 1000;
    for(;;) {
        long long now = settle_now_ms();
        if(now >= until || engine_is_stopped())
            break;
        long long wait = settle_clock_wait(&g_clock, now);
        if(wait <= 0) {
            settle_pass();
            // Waiting for the lock: don't spin
            if(!ee_embed_claimed())
                wait = until - settle_now_ms();
            else
                continue;
        }
        if(wait > until - now)
            wait = until - now;
        if(wait > 0)
            usleep((useconds_t)(wait * 1000));
    }
}

void settle_stop(void)
{
    if(g_on) {
        ee_embed_stop();
        g_on = 0;
    }
}
