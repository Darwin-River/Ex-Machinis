/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Drone wakes from memory, the database side (#694 stage 4
               step 4, #432, WAKE_HEAP=1)

  db.c's batch of commands to run came from three queries over every due
  command of the shard, each second (the "next command" statement: about
  27 ms and 383 rows a call on the live server). With WAKE_HEAP=1 the
  commands are kept in memory (wake_heap.c) and a batch is made from there:

  - the ready commands are taken in the queries' order (tier, id) and
    checked, a chunk at a time, by one query on their ids with the batch
    queries' own conditions as a column (db_runnable_where_at). A command
    that is runnable goes in the batch; one not due yet goes back to wait
    for its time; a resume held for its drone's action waits until the
    action's next pending event is due (less a real second of game time:
    an action is aborted, or its events settle, only from then); one held
    for another reason is looked at again next batch, as the query would;
    one whose row is gone is dropped. A command whose drone changed tier
    goes back with its real key. Each command is still read by id just
    before it runs (db_get_next_command), as before.

  How it stays complete:
  - at start, one read of the shard's commands;
  - the engine's own writes (resumes, <launched> on arrival and for a new
    drone, deleted commands, delayed resumes) are staged and applied when
    their transaction commits (wake_heap_stage_*);
  - before each batch, the ids past the highest seen (the website's
    releases, pushes and mail, other shards' and other programs' inserts);
    once a real second, the ids that read skipped (not committed yet), for
    10 real seconds, and the ids of the last 10-20 real seconds again;
  - the commands without a delivery time yet are looked up before each
    batch until the stamping (shard 0) has timed them;
  - every five real minutes, the start's read again: a WARNING says how
    many it found.
  A NOTICE once a real minute gives the counts.

******************************************************************************/

#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <limits.h>

#include "db.h"
#include "engine.h"
#include "game_time.h"
#include "trace.h"
#include "wake_heap.h"

#define WAKE_READ_MS         1000
#define WAKE_SWEEP_MS        1000
#define WAKE_LAG_STEP_MS     10000
#define WAKE_RECONCILE_MS    (5 * 60 * 1000)
#define WAKE_REPORT_MS       60000
#define WAKE_GAPS_MAX        1024
#define WAKE_GAP_SPAN_MAX    256
/* Commands checked by one query */
#define WAKE_CHECK_CHUNK     96

static int wake_loaded = 0;
static int wake_max_id = 0;
static int wake_wm_prev = 0, wake_wm_lag = 0;
static long long wake_wm_at = 0, wake_swept_at = 0, wake_reconciled_at = 0, wake_reported_at = 0;
static unsigned int wake_fill_no = 0;

/* The minute's counts */
static long long wake_picked = 0, wake_checked = 0, wake_dropped = 0, wake_held = 0;
static long long wake_held_asked = 0, wake_released = 0;
static long long wake_found_new = 0, wake_found_late = 0, wake_found_check = 0;

static int wake_gap_ids[WAKE_GAPS_MAX];
static long long wake_gap_at[WAKE_GAPS_MAX];
static int wake_gap_n = 0;

static long long wake_ms(void)
{
    struct timespec t;
    clock_gettime(CLOCK_MONOTONIC, &t);
    return (long long)t.tv_sec * 1000 + t.tv_nsec / 1000000;
}

static void wake_gap_add(int id, long long now)
{
    if(wake_gap_n == WAKE_GAPS_MAX) {
        memmove(wake_gap_ids, wake_gap_ids + 1, sizeof(int) * (WAKE_GAPS_MAX - 1));
        memmove(wake_gap_at, wake_gap_at + 1, sizeof(long long) * (WAKE_GAPS_MAX - 1));
        wake_gap_n--;
    }
    wake_gap_ids[wake_gap_n] = id;
    wake_gap_at[wake_gap_n] = now;
    wake_gap_n++;
}

static void wake_gap_remove(int id)
{
    for(int i = 0; i < wake_gap_n; i++)
        if(wake_gap_ids[i] == id) {
            wake_gap_n--;
            memmove(wake_gap_ids + i, wake_gap_ids + i + 1, sizeof(int) * (size_t)(wake_gap_n - i));
            memmove(wake_gap_at + i, wake_gap_at + i + 1, sizeof(long long) * (size_t)(wake_gap_n - i));
            return;
        }
}

/* What the batch queries order by, for "FROM commands c LEFT JOIN agents a"
   (a drone with no row is in neither of the first two tiers: those
   queries join agents) */
static int wake_columns(char* out, size_t size)
{
    return snprintf(out, size,
        "c.id, c.agent_id, c.deliver_at, c.code LIKE '%s%%', a.station_id IS NOT NULL, "
        "(a.agent_id IS NOT NULL AND a.release_vm IS NULL)",
        engine_get_vm_resume_command());
}

static unsigned int wake_row_flags(MYSQL_ROW row)
{
    unsigned int flags = 0;
    if(row[3] && atoi(row[3])) flags |= WAKE_RESUME;
    if(row[4] && atoi(row[4])) flags |= WAKE_STATION;
    if(row[5] && atoi(row[5])) flags |= WAKE_LOADING;
    return flags;
}

/* Adds the commands of a wake_columns() query not in yet: *added. gaps: 1,
   the rows come in id order past wake_max_id and the ids they skip are
   noted; 2, they are noted ids found */
static ErrorCode_t wake_read(DbConnection_t* connection, const char* query, int* added, int* rows, int gaps)
{
    *added = 0;
    if(rows) *rows = 0;
    if(mysql_query(connection->hndl, query)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    if(!res) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s]: no result: %s", query, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    WakeHeap_t* heap = wake_heap_global();
    ErrorCode_t result = ENGINE_OK;
    MYSQL_ROW row;
    while((row = mysql_fetch_row(res))) {
        int id = row[0] ? atoi(row[0]) : 0;
        if(id <= 0) continue;
        if(gaps == 1 && id > wake_max_id + 1 && id - wake_max_id <= WAKE_GAP_SPAN_MAX) {
            long long now = wake_ms();
            for(int g = wake_max_id + 1; g < id; g++)
                wake_gap_add(g, now);
        }
        if(gaps == 2)
            wake_gap_remove(id);
        if(id > wake_max_id) wake_max_id = id;
        // No drone (NULL): the queries' "c.agent_id % n = s" is not true
        if(!row[1] && heap->shards > 1) continue;
        int agent_id = row[1] ? atoi(row[1]) : 0;
        if(!wake_heap_mine(heap, agent_id)) continue;
        if(rows) (*rows)++;
        // Known already: what is known of it is newer
        if(wake_heap_find(heap, id)) continue;
        long long deliver_at = row[2] ? atoll(row[2]) : WAKE_UNSTAMPED;
        int put = wake_heap_put(heap, id, agent_id, deliver_at, wake_row_flags(row));
        if(put == -1) { result = ENGINE_MEMORY_ALLOC_ERROR; break; }
        if(put == 1) (*added)++;
    }
    mysql_free_result(res);
    return result;
}

static ErrorCode_t wake_max(DbConnection_t* connection, int* max_id)
{
    if(mysql_query(connection->hndl, "SELECT MAX(id) FROM commands")) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [SELECT MAX(id) FROM commands] failed: %s", mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    if(!res) return ENGINE_DB_QUERY_ERROR;
    MYSQL_ROW row = mysql_fetch_row(res);
    *max_id = (row && row[0]) ? atoi(row[0]) : 0;
    mysql_free_result(res);
    return ENGINE_OK;
}

/* Every command of this shard: at start, and every five minutes */
static ErrorCode_t wake_load_all(DbConnection_t* connection, const char* why)
{
    char columns[512], shard[64] = "", query[1024];
    int top = 0, added = 0, rows = 0;
    long long started = wake_ms();
    wake_columns(columns, sizeof(columns));
    if(engine_shards() > 1)
        snprintf(shard, sizeof(shard), " WHERE c.agent_id %% %d = %d", engine_shards(), engine_shard());
    snprintf(query, sizeof(query), "SELECT %s FROM commands c LEFT JOIN agents a ON a.agent_id = c.agent_id%s",
        columns, shard);
    // The top id first: what is inserted while this reads, the next reads find
    ErrorCode_t result = wake_max(connection, &top);
    if(result == ENGINE_OK)
        result = wake_read(connection, query, &added, &rows, 0);
    if(result != ENGINE_OK)
        return result;
    if(!wake_loaded) {
        wake_loaded = 1;
        if(top > wake_max_id) wake_max_id = top;
        wake_wm_prev = wake_wm_lag = wake_max_id;
        wake_wm_at = wake_swept_at = wake_reconciled_at = wake_reported_at = wake_ms();
        engine_trace(TRACE_LEVEL_ALWAYS,
            "NOTICE: WAKE_HEAP=1: drone wakes come from memory: [%d] commands loaded in [%lld] ms (top id [%d])",
            rows, wake_ms() - started, top);
    } else {
        wake_found_check += added;
        engine_trace(added ? TRACE_LEVEL_ALWAYS : TRACE_LEVEL_DEBUG,
            "%sWAKE_HEAP: %s: [%d] commands, [%d] of them not in memory, in [%lld] ms",
            added ? "WARNING: " : "", why, rows, added, wake_ms() - started);
    }
    return ENGINE_OK;
}

static ErrorCode_t wake_read_new(DbConnection_t* connection)
{
    char columns[512], query[1024];
    int added = 0;
    wake_columns(columns, sizeof(columns));
    // Every shard's rows: an id of another shard is no gap
    snprintf(query, sizeof(query),
        "SELECT %s FROM commands c FORCE INDEX (PRIMARY) LEFT JOIN agents a ON a.agent_id = c.agent_id "
        "WHERE c.id > %d ORDER BY c.id", columns, wake_max_id);
    ErrorCode_t result = wake_read(connection, query, &added, NULL, 1);
    wake_found_new += added;
    return result;
}

static ErrorCode_t wake_read_gaps(DbConnection_t* connection, long long now)
{
    int kept = 0;
    for(int i = 0; i < wake_gap_n; i++)
        if(now - wake_gap_at[i] < WAKE_LAG_STEP_MS) {
            wake_gap_ids[kept] = wake_gap_ids[i];
            wake_gap_at[kept] = wake_gap_at[i];
            kept++;
        }
    wake_gap_n = kept;
    if(!wake_gap_n)
        return ENGINE_OK;
    char columns[512];
    wake_columns(columns, sizeof(columns));
    size_t len = 1024 + (size_t)wake_gap_n * 12;
    char* query = malloc(len);
    if(!query)
        return ENGINE_MEMORY_ALLOC_ERROR;
    char* end = query;
    end += sprintf(end, "SELECT %s FROM commands c LEFT JOIN agents a ON a.agent_id = c.agent_id WHERE c.id IN (", columns);
    for(int i = 0; i < wake_gap_n; i++)
        end += sprintf(end, i ? ",%d" : "%d", wake_gap_ids[i]);
    sprintf(end, ")");
    int added = 0;
    ErrorCode_t result = wake_read(connection, query, &added, NULL, 2);
    free(query);
    wake_found_late += added;
    return result;
}

static ErrorCode_t wake_sweep(DbConnection_t* connection, long long now)
{
    int top = wake_max_id, added = 0;
    if(top > wake_wm_lag) {
        char columns[512], query[1024];
        wake_columns(columns, sizeof(columns));
        snprintf(query, sizeof(query),
            "SELECT %s FROM commands c FORCE INDEX (PRIMARY) LEFT JOIN agents a ON a.agent_id = c.agent_id "
            "WHERE c.id > %d AND c.id <= %d", columns, wake_wm_lag, top);
        ErrorCode_t result = wake_read(connection, query, &added, NULL, 0);
        if(result != ENGINE_OK) return result;
        wake_found_late += added;
        if(added)
            engine_trace(TRACE_LEVEL_ALWAYS, "WAKE_HEAP: the sweep (ids %d-%d) found [%d] commands that committed late",
                wake_wm_lag + 1, top, added);
    }
    wake_swept_at = now;
    if(now - wake_wm_at >= WAKE_LAG_STEP_MS) {
        wake_wm_lag = wake_wm_prev;
        wake_wm_prev = wake_max_id;
        wake_wm_at = now;
    }
    return ENGINE_OK;
}

/* Commands with no delivery time yet: timed since (by shard 0's stamping), or gone */
static ErrorCode_t wake_read_unstamped(DbConnection_t* connection)
{
    WakeHeap_t* heap = wake_heap_global();
    const int* ids;
    int n = wake_heap_unstamped(heap, &ids);
    if(!n)
        return ENGINE_OK;
    size_t len = 256 + (size_t)n * 12;
    char* query = malloc(len);
    if(!query)
        return ENGINE_MEMORY_ALLOC_ERROR;
    char* end = query;
    end += sprintf(end, "SELECT c.id, c.deliver_at FROM commands c WHERE c.id IN (");
    for(int i = 0; i < n; i++)
        end += sprintf(end, i ? ",%d" : "%d", ids[i]);
    sprintf(end, ")");
    int* seen = calloc((size_t)n, sizeof(int));
    int* asked = malloc(sizeof(int) * (size_t)n);
    if(!seen || !asked) {
        free(query); free(seen); free(asked);
        return ENGINE_MEMORY_ALLOC_ERROR;
    }
    memcpy(asked, ids, sizeof(int) * (size_t)n);
    ErrorCode_t result = ENGINE_OK;
    if(mysql_query(connection->hndl, query)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query, mysql_error(connection->hndl));
        result = ENGINE_DB_QUERY_ERROR;
    } else {
        MYSQL_RES* res = mysql_store_result(connection->hndl);
        MYSQL_ROW row;
        while(res && (row = mysql_fetch_row(res))) {
            int id = row[0] ? atoi(row[0]) : 0;
            for(int i = 0; i < n; i++)
                if(asked[i] == id) seen[i] = 1;
            WakeEntry_t* e = wake_heap_find(heap, id);
            if(e && row[1]) {
                e->deliver_at = atoll(row[1]);
                wake_heap_to_waiting(heap, e, e->deliver_at, 0);
            }
        }
        if(res) mysql_free_result(res);
        for(int i = 0; i < n; i++)
            if(!seen[i] && wake_heap_remove(heap, asked[i]))
                wake_dropped++;
    }
    free(query); free(seen); free(asked);
    return result;
}

/* For the resumes found held: when each one's action has its next pending
   event (the action isn't aborted), by one query on the actions */
static void wake_hold_times(DbConnection_t* connection, const int* actions, int n, long long* next_t)
{
    for(int i = 0; i < n; i++) next_t[i] = LLONG_MIN;
    if(!n) return;
    size_t len = 512 + (size_t)n * 12;
    char* query = malloc(len);
    if(!query) return;
    char* end = query;
    end += sprintf(end, "SELECT e.action, MIN(e.game_t) FROM events e WHERE e.action IN (");
    for(int i = 0; i < n; i++)
        end += sprintf(end, i ? ",%d" : "%d", actions[i]);
    sprintf(end, ") AND e.outcome = 0 AND EXISTS (SELECT 1 FROM actions x WHERE x.id = e.action AND x.aborted = 0) "
        "GROUP BY e.action");
    if(mysql_query(connection->hndl, query)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query, mysql_error(connection->hndl));
        free(query);
        return;   // looked at again next batch
    }
    free(query);
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    MYSQL_ROW row;
    while(res && (row = mysql_fetch_row(res))) {
        int action = row[0] ? atoi(row[0]) : 0;
        for(int i = 0; i < n; i++)
            if(actions[i] == action && row[1]) next_t[i] = atoll(row[1]);
    }
    if(res) mysql_free_result(res);
}

/* Checks the ids in cand against the batch queries' conditions, and puts
   each where it belongs */
static ErrorCode_t wake_check(DbConnection_t* connection, const int* cand, int n, long long now)
{
    WakeHeap_t* heap = wake_heap_global();
    char columns[512], where[1024];
    wake_columns(columns, sizeof(columns));
    db_runnable_where_at(where, sizeof(where), 0, now);
    size_t len = strlen(columns) + strlen(where) + 512 + (size_t)n * 12;
    char* query = malloc(len);
    if(!query)
        return ENGINE_MEMORY_ALLOC_ERROR;
    char* end = query;
    // The queries' conditions as a column, and what a resume may wait for
    end += sprintf(end, "SELECT %s, (%s), a.waiting_action "
        "FROM commands c LEFT JOIN agents a ON a.agent_id = c.agent_id WHERE c.id IN (", columns, where);
    for(int i = 0; i < n; i++)
        end += sprintf(end, i ? ",%d" : "%d", cand[i]);
    sprintf(end, ")");

    if(mysql_query(connection->hndl, query)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query, mysql_error(connection->hndl));
        free(query);
        // Back they go, for the next batch
        for(int i = 0; i < n; i++) {
            WakeEntry_t* e = wake_heap_find(heap, cand[i]);
            if(e) wake_heap_to_recheck(heap, e);
        }
        return ENGINE_DB_QUERY_ERROR;
    }
    free(query);
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    if(!res) {
        for(int i = 0; i < n; i++) {
            WakeEntry_t* e = wake_heap_find(heap, cand[i]);
            if(e) wake_heap_to_recheck(heap, e);
        }
        return ENGINE_DB_QUERY_ERROR;
    }
    // Due resumes the conditions hold back while their drone has an action
    int held_ids[WAKE_CHECK_CHUNK], held_actions[WAKE_CHECK_CHUNK], held_n = 0;
    MYSQL_ROW row;
    while((row = mysql_fetch_row(res))) {
        int id = row[0] ? atoi(row[0]) : 0;
        WakeEntry_t* e = wake_heap_find(heap, id);
        if(!e || e->where != WAKE_NOWHERE) continue;
        e->confirmed = wake_fill_no;   // seen: not dropped below
        e->agent_id = row[1] ? atoi(row[1]) : e->agent_id;
        e->flags = (unsigned char)wake_row_flags(row);
        e->deliver_at = row[2] ? atoll(row[2]) : WAKE_UNSTAMPED;
        int runnable = row[6] && atoi(row[6]);
        if(e->deliver_at == WAKE_UNSTAMPED) {
            e->confirmed = 0;
            wake_heap_to_unstamped(heap, e);
        } else if(e->deliver_at > now) {
            e->confirmed = 0;
            wake_heap_to_waiting(heap, e, e->deliver_at, 0);
        } else if(runnable) {
            wake_heap_to_ready(heap, e);   // with its real tier
        } else {
            e->confirmed = 0;
            // A resume is held only for its drone's action (the other hold
            // is for new scripts and <launched>): see when that can end
            if((e->flags & WAKE_RESUME) && row[7] && held_n < WAKE_CHECK_CHUNK) {
                held_ids[held_n] = id;
                held_actions[held_n++] = atoi(row[7]);
            }
            wake_heap_to_recheck(heap, e);
        }
    }
    mysql_free_result(res);
    // Not returned: gone (run, aborted, replaced by a new release...)
    for(int i = 0; i < n; i++) {
        WakeEntry_t* e = wake_heap_find(heap, cand[i]);
        if(e && e->where == WAKE_NOWHERE && e->confirmed != wake_fill_no) {
            wake_heap_remove(heap, cand[i]);
            wake_dropped++;
        } else if(e && e->where == WAKE_NOWHERE) {
            // Seen but placed nowhere (cannot happen): next batch
            e->confirmed = 0;
            wake_heap_to_recheck(heap, e);
        }
    }
    // An action is aborted, or its events settle, only once its next
    // pending event is due: wait until a real second of game time before
    // that (game time moves a real second at a time, and another engine's
    // clock may tick a moment before this one's). None pending (settled
    // just now): looked at again next batch
    long long next_t[WAKE_CHECK_CHUNK];
    long long margin = (long long)game_time_scale() + 1;
    wake_hold_times(connection, held_actions, held_n, next_t);
    for(int i = 0; i < held_n; i++) {
        WakeEntry_t* e = wake_heap_find(heap, held_ids[i]);
        if(!e || next_t[i] == LLONG_MIN) continue;
        wake_held++;
        wake_heap_to_waiting(heap, e, next_t[i] - margin, held_actions[i]);
    }
    return ENGINE_OK;
}

/* The held resumes whose action's next event is about due: those whose
   action has no event left to settle (or is aborted) go to be checked in
   full; the others wait for its next one (each batch while it is overdue,
   as the query would look) */
static void wake_held_check(DbConnection_t* connection, long long now)
{
    WakeHeap_t* heap = wake_heap_global();
    const int* list;
    int n = wake_heap_held_due(heap, &list);
    if(!n)
        return;
    int* ids = calloc((size_t)n, sizeof(int));
    int* actions = calloc((size_t)n, sizeof(int));
    long long* next_t = calloc((size_t)n, sizeof(long long));
    if(!ids || !actions || !next_t) {
        free(ids); free(actions); free(next_t);
        return;   // they stay held, asked about next batch
    }
    for(int i = 0; i < n; i++) {
        ids[i] = list[i];
        actions[i] = wake_heap_find(heap, list[i])->action;
    }
    wake_hold_times(connection, actions, n, next_t);
    long long margin = (long long)game_time_scale() + 1;
    for(int i = 0; i < n; i++) {
        WakeEntry_t* e = wake_heap_find(heap, ids[i]);
        if(!e || e->where != WAKE_HELD_DUE) continue;
        if(next_t[i] == LLONG_MIN) {
            wake_heap_to_ready(heap, e);
            wake_released++;
        } else {
            wake_held_asked++;
            wake_heap_to_waiting(heap, e, next_t[i] - margin, actions[i]);
        }
    }
    free(ids); free(actions); free(next_t);
}

static void wake_report(long long now)
{
    if(now - wake_reported_at < WAKE_REPORT_MS)
        return;
    WakeHeap_t* heap = wake_heap_global();
    const int* ids;
    int unstamped = wake_heap_unstamped(heap, &ids);
    engine_trace(TRACE_LEVEL_ALWAYS,
        "NOTICE: WAKE_HEAP: [%d] commands in memory ([%d] ready, [%d] waiting, [%d] not timed yet); last minute: "
        "[%lld] picked to run, [%lld] checked, [%lld] dropped as stale, [%lld] held for their action ([%lld] asked "
        "again, [%lld] let go); found not put by this engine: [%lld] by the id read, [%lld] late, [%lld] by the "
        "five-minute check",
        wake_heap_count(heap), event_heap_count(&heap->ready), event_heap_count(&heap->waiting), unstamped,
        wake_picked, wake_checked, wake_dropped, wake_held, wake_held_asked, wake_released, wake_found_new, wake_found_late, wake_found_check);
    wake_picked = wake_checked = wake_dropped = wake_held = 0;
    wake_held_asked = wake_released = 0;
    wake_found_new = wake_found_late = wake_found_check = 0;
    wake_reported_at = now;
}

ErrorCode_t db_wake_fill(DbConnection_t* connection, long long now, int* ids, int max, int* count)
{
    WakeHeap_t* heap = wake_heap_global();
    *count = 0;
    if(!heap)
        return ENGINE_INTERNAL_ERROR;

    long long ms = wake_ms();
    ErrorCode_t result = ENGINE_OK;
    if(!wake_loaded)
        result = wake_load_all(connection, "start");
    else if(ms - wake_reconciled_at >= WAKE_RECONCILE_MS) {
        result = wake_load_all(connection, "the five-minute check");
        wake_reconciled_at = ms;
    }
    // What others wrote. Each batch, as the queries saw it: the ids past
    // the highest seen (a primary-key range, usually empty) and the untimed
    // commands (another shard's stamping times them); once a real second,
    // the ids skipped and the sweep
    static long long read_at = 0;
    if(result == ENGINE_OK)
        result = wake_read_new(connection);
    if(result == ENGINE_OK)
        result = wake_read_unstamped(connection);
    if(result == ENGINE_OK && ms - read_at >= WAKE_READ_MS) {
        read_at = ms;
        result = wake_read_gaps(connection, ms);
        if(result == ENGINE_OK && ms - wake_swept_at >= WAKE_SWEEP_MS)
            result = wake_sweep(connection, ms);
    }
    if(result != ENGINE_OK)
        return result;
    wake_report(ms);

    // Due now: into ready
    wake_heap_release(heap, now);
    wake_held_check(connection, now);
    wake_fill_no++;
    if(!wake_fill_no) wake_fill_no = 1;

    // The ready commands in the queries' order: those found runnable this
    // batch go in the batch as they come to the top; the others are checked
    // a chunk at a time, and put back with their real keys
    int cand[WAKE_CHECK_CHUNK];
    int* picked = malloc(sizeof(int) * (size_t)(max > 0 ? max : 1));
    int* skipped = malloc(sizeof(int) * WAKE_CHECK_CHUNK);
    if(!picked || !skipped) {
        free(picked); free(skipped);
        return ENGINE_MEMORY_ALLOC_ERROR;
    }
    while(*count < max) {
        WakeEntry_t* top = wake_heap_peek_ready(heap);
        if(!top)
            break;
        if(top->confirmed == wake_fill_no) {
            top = wake_heap_pop_ready(heap);
            picked[*count] = top->id;
            ids[(*count)++] = top->id;
            continue;
        }
        int n = 0, s = 0;
        WakeEntry_t* e;
        while(n < WAKE_CHECK_CHUNK && s < WAKE_CHECK_CHUNK && (e = wake_heap_pop_ready(heap))) {
            if(e->confirmed == wake_fill_no) skipped[s++] = e->id;
            else cand[n++] = e->id;
        }
        for(int i = 0; i < s; i++) {
            WakeEntry_t* back = wake_heap_find(heap, skipped[i]);
            if(back) wake_heap_to_ready(heap, back);
        }
        if(!n)
            continue;
        wake_checked += n;
        result = wake_check(connection, cand, n, now);
        if(result != ENGINE_OK)
            break;
    }
    // The batch stays ready: its commands leave memory when they run
    // (their delete commits), as the query would return them until then
    for(int i = 0; i < *count; i++) {
        WakeEntry_t* back = wake_heap_find(heap, picked[i]);
        if(back) wake_heap_to_ready(heap, back);
    }
    wake_picked += *count;
    free(picked);
    free(skipped);
    return result;
}
