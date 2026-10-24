/*
 * Checks the game clock (game_time.c, #327): game time carries on without a
 * jump when the clock's rate changes, and something scheduled for a game
 * time (an event's or observation's game_t) comes due at that game time
 * whatever the rate does in between. The database and the system clock are
 * replaced by fakes, so no MySQL server is needed. Run with: make test
 */
#include <math.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#include "game_time.h"
#include "db.h"
#include "trace.h"

/* --- fakes ------------------------------------------------------------- */

static time_t fake_now = 1800000000;                /* the system clock */
time_t __wrap_time(time_t *t) { if(t) *t = fake_now; return fake_now; }

/* The game_clock row, and the last history row written */
static int clock_exists = 0;
static long long real_anchor, history_rows = 0;
static double game_anchor, scale, epoch;
static char row_buf[4][64];
static char *row_ptrs[4];
static int row_ready = 0;

static DbConnection_t connection;
DbConnection_t* engine_get_db_connection() { return &connection; }
ErrorCode_t db_reconnect(DbConnection_t* c) { (void)c; return ENGINE_OK; }
void engine_trace(TraceLevel_t level, const char *trace, ...) { (void)level; (void)trace; }

int mysql_query(MYSQL *m, const char *q)
{
    (void)m;
    long long ra; double ga, sc;
    if(strstr(q, "INSERT IGNORE INTO game_clock")) {
        if(!clock_exists) {
            clock_exists = 1;
            real_anchor = fake_now; game_anchor = (double)fake_now; scale = 1; epoch = (double)fake_now;
        }
    } else if(strstr(q, "SELECT real_anchor")) {
        snprintf(row_buf[0], 64, "%lld", real_anchor);
        snprintf(row_buf[1], 64, "%.3f", game_anchor);
        snprintf(row_buf[2], 64, "%.6f", scale);
        snprintf(row_buf[3], 64, "%.3f", epoch);
        row_ready = 1;
    } else if(sscanf(q, "UPDATE game_clock SET real_anchor = %lld, game_anchor = %lf, scale = %lf", &ra, &ga, &sc) == 3) {
        real_anchor = ra; game_anchor = ga; scale = sc;
    } else if(strstr(q, "INSERT INTO game_clock_history")) {
        history_rows++;
    }
    return 0;
}
MYSQL_RES *mysql_store_result(MYSQL *m) { (void)m; return row_ready ? (MYSQL_RES*)row_ptrs : NULL; }
MYSQL_ROW mysql_fetch_row(MYSQL_RES *r)
{
    if(!r || !row_ready) return NULL;
    for(int i = 0; i < 4; i++) row_ptrs[i] = row_buf[i];
    row_ready = 0;
    return row_ptrs;
}
void mysql_free_result(MYSQL_RES *r) { (void)r; }
const char *mysql_error(MYSQL *m) { (void)m; return ""; }

/* --- checks ------------------------------------------------------------ */

static int failures = 0;
static void check(const char *what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

/* An item scheduled at game time game_t is due once game time reaches it,
   which is what the engines' "game_t <= [game now]" queries test */
static int due(long long game_t) { return game_t <= game_time_now_seconds(); }

int main(void)
{
    check("a new world's clock loads", game_time_load() == ENGINE_OK);
    long long start = game_time_now_seconds();
    check("a new world's game time starts at its creation time", start == (long long)fake_now);
    check("the epoch is the world's first second", llround(game_time_epoch()) == start);

    /* An event one game hour away, at 1 game second per real second */
    long long event_t = start + 3600;
    fake_now += 1800;
    game_time_load();
    check("not due halfway there", !due(event_t));

    /* Speed the clock up 60 times: game time carries on without a jump */
    long long before = game_time_now_seconds();
    check("the rate changes", game_time_set_scale(60) == ENGINE_OK);
    check("game time doesn't jump when the rate changes", game_time_now_seconds() == before);
    check("the rate change is recorded in the history", history_rows == 1);

    /* The remaining 1800 game seconds now take 30 real seconds */
    fake_now += 29;
    game_time_load();
    check("not due one real second early at the new rate", !due(event_t));
    fake_now += 1;
    game_time_load();
    check("due at its game time after the rate change", due(event_t));

    /* Light delay is a game duration: 10 light-seconds take 10 game seconds
       (a sixth of a real second at this rate) */
    check("light delay is counted in game seconds",
          game_time_light_seconds(LIGHT_SPEED_KM_PER_SECOND * 10) == 10);
    long long seen_t = game_time_now_seconds() + game_time_light_seconds(LIGHT_SPEED_KM_PER_SECOND * 600);
    fake_now += 9;
    game_time_load();
    check("an observation 10 light-minutes away isn't seen early", !due(seen_t));
    fake_now += 1;
    game_time_load();
    check("it's seen 10 game minutes later", due(seen_t));

    /* Slow the clock right down: nothing already due becomes undue */
    game_time_set_scale(0.5);
    check("slowing the clock doesn't undo what is due", due(event_t) && due(seen_t));
    check("the epoch never moves", llround(game_time_epoch()) == start);

    if(failures) {
        printf("%d check(s) failed\n", failures);
        return 1;
    }
    printf("All game clock checks passed\n");
    return 0;
}
