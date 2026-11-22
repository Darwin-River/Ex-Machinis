/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Current state in memory, the database side (#694 stage 4
               step 5, #431, MEMORY_STATE=1)

  units() (query 212) was the drones' most frequent question: one row
  sum over `holdings` a call. With MEMORY_STATE=1 each game engine keeps a
  copy of `holdings` (holding_state.c) and answers it from there.

  How the copy stays the table:
  - every change to `holdings`, whoever makes it (settlement's events
    triggers, the website, procedures), is written to `holding_changes` by
    triggers on `holdings`, in the writer's own transaction (migration
    0092). The engine turns that feed on when it starts;
  - at the first use, one read of `holdings` in a consistent snapshot;
  - a turn's transaction reads the feed once, at its first units(): the
    ids past the highest seen, then the ids that read skipped (not
    committed yet), for 60 real seconds. Under REPEATABLE READ (one engine)
    that is the snapshot every statement of the transaction reads, so the
    answer is the SQL's own. Between turns the feed is read once a real
    second, so an idle engine keeps up;
  - a change applies to its row only over an older one (by feed id), so a
    change read late can't undo a newer one;
  - every five real minutes the read of `holdings` again, in a snapshot
    with the feed read in it: a WARNING gives how many rows differed, and
    the copy takes the table's rows;
  - an engine that hasn't read the feed for 90 real seconds reads the
    table again (shard 0 deletes changes over two real minutes old).
  A NOTICE once a real minute gives the counts.

  The engine's own turns change no holdings: they insert events pending,
  and only settlement (another connection, between turns) settles them.
  So nothing is staged per connection here: every change comes through
  the feed, the engine's own settlement's as well as everyone else's.

******************************************************************************/

#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <ctype.h>

#include "db.h"
#include "db_state.h"
#include "engine.h"
#include "trace.h"
#include "holding_state.h"
#include "settle.h"

#define STATE_READ_MS        1000
#define STATE_STALE_MS       90000
#define STATE_GAP_KEEP_MS    60000
#define STATE_CHECK_MS       (5 * 60 * 1000)
#define STATE_REPORT_MS      60000
#define STATE_PRUNE_MS       60000
#define STATE_PRUNE_AGE_MS   120000
#define STATE_GAPS_MAX       4096
#define STATE_GAP_SPAN_MAX   512
#define STATE_READ_LIMIT     20000
/* At start the feed is read again from this many ids before the top the
   snapshot saw, so a change committed late, after it, still comes in */
#define STATE_START_LOOKBACK 5000

static int state_flag = 0;          // MEMORY_STATE=1 and the feed turned on
static int state_loaded = 0;
static HoldingState_t state_holdings;
static unsigned long long state_max_id = 0;
static long long state_read_at = 0, state_checked_at = 0, state_reported_at = 0, state_pruned_at = 0;

/* The turn connection's transaction: whether its first answer read the feed */
static int state_in_transaction = 0, state_fresh = 0;
/* A settlement pass ran here since the feed was last read */
static int state_settled = 1;

/* The minute's counts */
static long long state_answers = 0, state_reads = 0, state_reads_turn = 0, state_applied = 0, state_late = 0;
static long long state_found_check = 0, state_checks = 0, state_mismatches = 0, state_pruned = 0;

/* Ids a read skipped (not committed yet), and when */
static unsigned long long state_gap_ids[STATE_GAPS_MAX];
static long long state_gap_at[STATE_GAPS_MAX];
static int state_gap_n = 0;

/* What the prune may delete: the highest id seen, a minute at a time */
#define STATE_MARKS 4
static unsigned long long state_mark_id[STATE_MARKS];
static long long state_mark_at[STATE_MARKS];

static long long state_ms(void)
{
    struct timespec t;
    clock_gettime(CLOCK_MONOTONIC, &t);
    return (long long)t.tv_sec * 1000 + t.tv_nsec / 1000000;
}

int memory_state_flag_on(void)
{
    const char* v = getenv("MEMORY_STATE");
    return v && atoi(v) == 1;
}

int memory_state_check_on(void)
{
    static int on = -1;
    if(on < 0) {
        const char* v = getenv("MEMORY_STATE_CHECK");
        on = v && atoi(v) == 1;
    }
    return on;
}

int memory_state_on(void)
{
    return state_flag;
}

static void state_gap_add(unsigned long long id, long long now)
{
    if(state_gap_n == STATE_GAPS_MAX) {
        memmove(state_gap_ids, state_gap_ids + 1, sizeof(state_gap_ids[0]) * (STATE_GAPS_MAX - 1));
        memmove(state_gap_at, state_gap_at + 1, sizeof(state_gap_at[0]) * (STATE_GAPS_MAX - 1));
        state_gap_n--;
    }
    state_gap_ids[state_gap_n] = id;
    state_gap_at[state_gap_n] = now;
    state_gap_n++;
}

static void state_gap_remove(unsigned long long id)
{
    for(int i = 0; i < state_gap_n; i++)
        if(state_gap_ids[i] == id) {
            state_gap_n--;
            memmove(state_gap_ids + i, state_gap_ids + i + 1, sizeof(state_gap_ids[0]) * (size_t)(state_gap_n - i));
            memmove(state_gap_at + i, state_gap_at + i + 1, sizeof(state_gap_at[0]) * (size_t)(state_gap_n - i));
            return;
        }
}

static void state_gap_expire(long long now)
{
    int keep = 0;
    for(int i = 0; i < state_gap_n; i++)
        if(now - state_gap_at[i] < STATE_GAP_KEEP_MS) {
            state_gap_ids[keep] = state_gap_ids[i];
            state_gap_at[keep] = state_gap_at[i];
            keep++;
        }
    state_gap_n = keep;
}

static int state_exec(DbConnection_t* connection, const char* sql)
{
    if(mysql_query(connection->hndl, sql)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", sql, mysql_error(connection->hndl));
        return -1;
    }
    return 0;
}

/* One change read from the feed */
typedef struct
{
    unsigned long long id;
    unsigned int drone, resource;
    int installed, locked, present, units;
} StateChange_t;

static StateChange_t* state_changes = NULL;
static size_t state_changes_n = 0, state_changes_size = 0;

static int state_change_add(MYSQL_ROW row)
{
    if(state_changes_n == state_changes_size) {
        size_t size = state_changes_size ? state_changes_size * 2 : 1024;
        StateChange_t* grown = realloc(state_changes, size * sizeof(StateChange_t));
        if(!grown) return -1;
        state_changes = grown;
        state_changes_size = size;
    }
    StateChange_t* c = &state_changes[state_changes_n++];
    c->id = strtoull(row[0], NULL, 10);
    c->drone = (unsigned int)strtoul(row[1], NULL, 10);
    c->resource = (unsigned int)strtoul(row[2], NULL, 10);
    c->installed = atoi(row[3]);
    c->locked = atoi(row[4]);
    c->present = row[5] != NULL;
    c->units = row[5] ? atoi(row[5]) : 0;
    return 0;
}

static int state_change_cmp(const void* a, const void* b)
{
    unsigned long long x = ((const StateChange_t*)a)->id, y = ((const StateChange_t*)b)->id;
    return x < y ? -1 : x > y;
}

/* Reads changes into state_changes: gaps 1, they come past state_max_id in
   id order and the ids they skip are noted; 2, they are noted ids found.
   *rows: how many came. -1 on an error */
static int state_read_changes(DbConnection_t* connection, const char* sql, int gaps, int* rows)
{
    *rows = 0;
    if(state_exec(connection, sql)) return -1;
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    if(!res) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s]: no result: %s", sql, mysql_error(connection->hndl));
        return -1;
    }
    int result = 0;
    long long now = state_ms();
    MYSQL_ROW row;
    while((row = mysql_fetch_row(res))) {
        if(!row[0] || !row[1] || !row[2] || !row[3] || !row[4]) continue;
        if(state_change_add(row)) { result = -1; break; }
        unsigned long long id = state_changes[state_changes_n - 1].id;
        (*rows)++;
        if(gaps == 1) {
            if(id > state_max_id + 1 && id - state_max_id <= STATE_GAP_SPAN_MAX)
                for(unsigned long long g = state_max_id + 1; g < id; g++)
                    state_gap_add(g, now);
            if(id > state_max_id) state_max_id = id;
        } else {
            state_gap_remove(id);
            state_late++;
        }
    }
    mysql_free_result(res);
    return result;
}

/* Reads the feed and applies what it brings, in id order. -1 on an error */
static int state_read_feed(DbConnection_t* connection)
{
    char sql[256 + STATE_GAPS_MAX * 21];
    int rows = 0;
    state_changes_n = 0;
    state_reads++;
    state_settled = 0;
    // The ids past the highest seen, first: a change found by the lookup
    // of skipped ids below is then never older than one this read missed
    do {
        snprintf(sql, sizeof(sql),
            "SELECT id, drone, resource, installed, locked, units FROM holding_changes "
            "WHERE id > %llu ORDER BY id LIMIT %d", state_max_id, STATE_READ_LIMIT);
        if(state_read_changes(connection, sql, 1, &rows)) return -1;
    } while(rows == STATE_READ_LIMIT);
    // Then the ids skipped before, committed since
    state_gap_expire(state_ms());
    if(state_gap_n > 0) {
        char* end = sql + snprintf(sql, sizeof(sql),
            "SELECT id, drone, resource, installed, locked, units FROM holding_changes WHERE id IN (");
        for(int i = 0; i < state_gap_n; i++)
            end += sprintf(end, "%s%llu", i ? "," : "", state_gap_ids[i]);
        strcpy(end, ")");
        if(state_read_changes(connection, sql, 2, &rows)) return -1;
    }
    qsort(state_changes, state_changes_n, sizeof(StateChange_t), state_change_cmp);
    for(size_t i = 0; i < state_changes_n; i++) {
        StateChange_t* c = &state_changes[i];
        int applied = holding_state_apply(&state_holdings, c->id, c->drone, c->resource, c->installed, c->locked,
                                          c->present, c->units);
        if(applied < 0) return -1;
        state_applied += applied;
    }
    state_read_at = state_ms();
    return 0;
}

/* The table, in a consistent snapshot: at start, and every five minutes
   (then with the feed read in the same snapshot first, and compared) */
static int state_load_all(DbConnection_t* connection, const char* why)
{
    long long started = state_ms();
    HoldingState_t fresh;
    holding_state_init(&fresh);
    unsigned long long top = 0;
    int ok = 0, feed_on = 1;
    // Its own transaction, whatever the session's level (READ COMMITTED
    // with several engines): both reads see one moment
    if(state_exec(connection, "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ") ||
       state_exec(connection, "START TRANSACTION WITH CONSISTENT SNAPSHOT"))
        return -1;
    do {
        if(state_loaded && state_read_feed(connection)) break;
        if(state_exec(connection, "SELECT IFNULL(MAX(id), 0), "
                                  "IFNULL((SELECT enabled FROM engine_feeds WHERE name = 'holdings'), 0) "
                                  "FROM holding_changes")) break;
        MYSQL_RES* res = mysql_store_result(connection->hndl);
        MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
        if(row) {
            top = row[0] ? strtoull(row[0], NULL, 10) : 0;
            feed_on = row[1] ? atoi(row[1]) : 0;
        }
        if(res) mysql_free_result(res);
        if(!row) break;
        if(state_exec(connection, "SELECT drone, resource, installed, locked, units FROM holdings")) break;
        res = mysql_use_result(connection->hndl);
        if(!res) break;
        int failed = 0;
        while((row = mysql_fetch_row(res)))
            if(row[0] && row[1] && row[2] && row[3] && row[4] &&
               holding_state_set(&fresh, (unsigned int)strtoul(row[0], NULL, 10), (unsigned int)strtoul(row[1], NULL, 10),
                                 atoi(row[2]), atoi(row[3]), atoi(row[4])))
                failed = 1;
        if(mysql_errno(connection->hndl)) failed = 1;
        mysql_free_result(res);
        ok = !failed;
    } while(0);
    state_exec(connection, "COMMIT");
    if(!ok) {
        holding_state_free(&fresh);
        return -1;
    }
    size_t rows = fresh.present;
    if(!state_loaded) {
        state_loaded = 1;
        // The feed is read again from a little before the top this
        // snapshot saw: a change it couldn't see (not committed yet) comes
        // in, and the ones it saw replay to what it read
        state_max_id = top > STATE_START_LOOKBACK ? top - STATE_START_LOOKBACK : 0;
        holding_state_swap_in(&state_holdings, &fresh);
        state_read_at = state_checked_at = state_reported_at = state_pruned_at = state_ms();
        engine_trace(TRACE_LEVEL_ALWAYS,
            "NOTICE: MEMORY_STATE=1: units() comes from memory: [%zu] holdings loaded in [%lld] ms (feed at id [%llu])",
            rows, state_ms() - started, top);
    } else {
        size_t differ = holding_state_diff(&state_holdings, &fresh);
        state_found_check = (long long)differ;   // the last check's, kept until the next
        if(top > state_max_id) state_max_id = top;
        holding_state_swap_in(&state_holdings, &fresh);
        engine_trace(differ ? TRACE_LEVEL_ALWAYS : TRACE_LEVEL_DEBUG,
            "%sMEMORY_STATE: %s: [%zu] holdings, [%zu] of them not as in memory, in [%lld] ms",
            differ ? "WARNING: " : "", why, rows, differ, state_ms() - started);
    }
    holding_state_free(&fresh);
    if(!feed_on) {
        // Someone turned the feed off: on again, and the next check sees
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: MEMORY_STATE: the holdings feed was off; turning it on again");
        state_exec(connection, "UPDATE engine_feeds SET enabled = 1, changed_at = NOW() WHERE name = 'holdings'");
    }
    return 0;
}

void db_state_start(DbConnection_t* connection)
{
    if(memory_state_flag_on()) {
        if(db_reconnect(connection) != ENGINE_OK ||
           mysql_query(connection->hndl,
               "UPDATE engine_feeds SET enabled = 1, changed_at = NOW() WHERE name = 'holdings'")) {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "NOTICE: MEMORY_STATE=1 but the holdings feed can't be turned on (%s): units() is asked of MySQL",
                connection->hndl ? mysql_error(connection->hndl) : "no connection");
            return;
        }
        holding_state_init(&state_holdings);
        state_flag = 1;
        engine_trace(TRACE_LEVEL_ALWAYS,
            "NOTICE: MEMORY_STATE=1: this game engine keeps holdings in memory and answers units() from there, "
            "not from MySQL (the holdings feed is on)");
        return;
    }
    // Off: shard 0 turns the feed off, so nothing grows, and empties it
    if(engine_shard() != 0 || db_reconnect(connection) != ENGINE_OK)
        return;
    if(mysql_query(connection->hndl, "SELECT enabled FROM engine_feeds WHERE name = 'holdings'")) {
        return;   // before migration 0092: nothing to do
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    int was_on = row && row[0] && atoi(row[0]) == 1;
    if(res) mysql_free_result(res);
    if(state_exec(connection, "UPDATE engine_feeds SET enabled = 0, changed_at = NOW() WHERE name = 'holdings' AND enabled <> 0"))
        return;
    long long deleted = 0;
    for(int i = 0; i < 1000; i++) {
        if(state_exec(connection, "DELETE FROM holding_changes LIMIT 50000")) break;
        long long n = (long long)mysql_affected_rows(connection->hndl);
        deleted += n;
        if(n < 50000) break;
    }
    if(was_on || deleted)
        engine_trace(TRACE_LEVEL_ALWAYS,
            "NOTICE: MEMORY_STATE=0: the holdings feed is off ([%lld] old changes deleted)", deleted);
}

void db_state_transaction_begins(const DbConnection_t* connection)
{
    (void)connection;
    state_in_transaction = 1;
    state_fresh = 0;
}

void db_state_transaction_ends(const DbConnection_t* connection)
{
    (void)connection;
    state_in_transaction = 0;
    state_fresh = 0;
}

int db_state_is_units_script(const char* script)
{
    static const char units_sql[] =
        "SELECT (SELECT IFNULL(SUM(hd.units), 0) FROM holdings hd WHERE hd.drone = [drone_id] "
        "AND hd.resource = [value_1] AND hd.installed IN (0, 1) AND hd.locked IN (0, 1))";
    if(!script)
        return 0;
    size_t n = strlen(script);
    while(n > 0 && (isspace((unsigned char)script[n - 1]) || script[n - 1] == ';'))
        n--;
    return n == sizeof(units_sql) - 1 && !strncmp(script, units_sql, n);
}

/* One game engine, settling itself: then turns needn't read the feed but
   after a settlement pass */
static int state_settles_alone(void)
{
    return engine_shards() == 1 && settle_here();
}

/* The memory up to what a statement now would read, or -1 (MySQL answers).
   The table is read (at start, or after the feed was lost) only between
   turns: its read is a transaction of its own, which inside a turn's would
   commit the turn's */
static int state_ready(DbConnection_t* connection)
{
    if(!state_flag)
        return -1;
    long long now = state_ms();
    if(state_loaded && now - state_read_at > STATE_STALE_MS) {
        engine_trace(TRACE_LEVEL_ALWAYS,
            "WARNING: MEMORY_STATE: the feed wasn't read for [%lld] ms: reading holdings again", now - state_read_at);
        state_loaded = 0;
    }
    if(!state_loaded) {
        if(state_in_transaction || state_load_all(connection, "start"))
            return -1;
        // Up to the feed's newest
        if(state_read_feed(connection)) {
            state_loaded = 0;
            return -1;
        }
        return 0;
    }
    if(state_fresh)
        return 0;
    // One engine that settles itself (EVENTS_IN_ENGINE=1, one shard): only
    // its own settlement changes holdings often, and only between turns. So
    // the feed is read after a pass, and once a real second for the rest
    // (the website's wind-ups and resets: as if they committed up to a
    // second later)
    if(state_settles_alone() && !state_settled)
        return 0;
    state_reads_turn++;
    if(state_read_feed(connection)) {
        // The next chance between turns reads the table again
        state_loaded = 0;
        return -1;
    }
    state_fresh = state_in_transaction;
    return 0;
}

void db_state_settled(void)
{
    state_settled = 1;
}

int db_state_units(DbConnection_t* connection, long long drone, long long resource, long long* units)
{
    if(!state_flag || db_reconnect(connection) != ENGINE_OK || state_ready(connection))
        return 0;
    *units = holding_state_units(&state_holdings, drone, resource);
    state_answers++;
    return 1;
}

void db_state_check_result(long long drone, long long resource, long long memory, long long sql)
{
    state_checks++;
    if(memory != sql) {
        state_mismatches++;
        engine_trace(TRACE_LEVEL_ALWAYS,
            "WARNING: MEMORY_STATE check: units() of drone [%lld] resource [%lld]: memory [%lld], MySQL [%lld]",
            drone, resource, memory, sql);
    }
}

/* Shard 0: changes every engine has had (over two real minutes old) go */
static void state_prune(DbConnection_t* connection, long long now)
{
    // The marks: the highest id seen, a minute apart
    memmove(state_mark_id + 1, state_mark_id, sizeof(state_mark_id[0]) * (STATE_MARKS - 1));
    memmove(state_mark_at + 1, state_mark_at, sizeof(state_mark_at[0]) * (STATE_MARKS - 1));
    state_mark_id[0] = state_max_id;
    state_mark_at[0] = now;
    unsigned long long below = 0;
    for(int i = 0; i < STATE_MARKS; i++)
        if(state_mark_at[i] && now - state_mark_at[i] >= STATE_PRUNE_AGE_MS) {
            below = state_mark_id[i];
            break;
        }
    if(!below)
        return;
    char sql[160];
    snprintf(sql, sizeof(sql), "DELETE FROM holding_changes WHERE id < %llu LIMIT 20000", below);
    for(int i = 0; i < 20; i++) {
        if(state_exec(connection, sql)) return;
        long long n = (long long)mysql_affected_rows(connection->hndl);
        state_pruned += n;
        if(n < 20000) break;
    }
}

void db_state_tick(DbConnection_t* connection)
{
    if(!state_flag)
        return;
    long long now = state_ms();
    if(!state_loaded || now - state_read_at >= STATE_READ_MS) {
        if(db_reconnect(connection) != ENGINE_OK)
            return;
        if(state_ready(connection))
            return;
    }
    now = state_ms();
    if(now - state_checked_at >= STATE_CHECK_MS) {
        state_checked_at = now;
        if(state_load_all(connection, "five-minute check"))
            state_loaded = 0;
    }
    if(engine_shard() == 0 && now - state_pruned_at >= STATE_PRUNE_MS) {
        state_pruned_at = now;
        state_prune(connection, now);
    }
    if(now - state_reported_at >= STATE_REPORT_MS) {
        state_reported_at = now;
        engine_trace(TRACE_LEVEL_ALWAYS,
            "NOTICE: MEMORY_STATE: [%zu] holdings in memory (feed at id [%llu], [%d] ids skipped); last minute: "
            "[%lld] units() answered from memory, [%lld] feed reads ([%lld] by turns%s), [%lld] changes applied, "
            "[%lld] found late, [%lld] old changes deleted; the last five-minute check: [%lld] rows differed",
            state_holdings.present, state_max_id, state_gap_n, state_answers, state_reads, state_reads_turn,
            state_settles_alone() ? ", after settlement" : ", each transaction", state_applied, state_late,
            state_pruned, state_found_check);
        if(memory_state_check_on())
            engine_trace(TRACE_LEVEL_ALWAYS,
                "NOTICE: MEMORY_STATE check: last minute [%lld] answers asked of MySQL too, [%lld] differed",
                state_checks, state_mismatches);
        state_answers = state_reads = state_reads_turn = state_applied = state_late = state_pruned = 0;
        state_checks = state_mismatches = 0;
    }
}
