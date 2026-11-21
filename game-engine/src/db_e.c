/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: DB interface module

******************************************************************************/

/******************************* INCLUDES ************************************/

#define _XOPEN_SOURCE
#include "static_cache.h"
#include <time.h>
#include <sys/time.h>
#include <string.h>
#include <stdlib.h>
#include <unistd.h>

#include "db.h"
#include "db_e.h"
#include "game_time.h"
#include "trace.h"
#include "engine_e.h"
#include "event_heap.h"
#include "wake_heap.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/** ***************************************************************************

  @brief      Scapes an string value that will be used inside a mysql query

  @param[in]  value    Value to be scaped
  @param[in]  len      Value size
  @param[in]  out      out buffer
  @param[in]  out_len  Out buffer size

  @return     void

******************************************************************************/
void ee_db_scape_string(
    DbConnection_t* connection,
    char *value,
    size_t len,
    char *out,
    size_t out_len
)
{
    unsigned long to_len;

    to_len = mysql_real_escape_string(connection->hndl, out, value, strlen(value));
    out[to_len+1] = '\0';

   ee_engine_trace(TRACE_LEVEL_DEBUG, "Value [%s] scaped now is [%s]", value, out);
}


/** ****************************************************************************

    @brief      Handy function to convert an integer into string and return string

    @param[in]  value  Integer value we want to convert
    @param[in]  buffer String buffer where we want to store the result
    @param[in]  szie   String buffer size

    @return     String result

*******************************************************************************/
char* ee_db_int2str(int value, char* buffer, size_t size)
{
    if(value == NULL_VALUE) {
        sprintf(buffer, "NULL");
    } else if(buffer && size) {
        snprintf(buffer, size, "%d", value);
    }

    return buffer;
}

/** ***************************************************************************

  @brief      Converts a given date string into timestamp value

  @param[in]  date    Input date string

  @return     void

******************************************************************************/
time_t ee_db_date_to_timestamp(char* date, char* format)
{
    struct tm tm;
    time_t epoch = 0;

    // input pointers checked by calling function

    // strptime only sets the fields in the format. Clear the rest: a random
    // tm_isdst made mktime shift dates by an hour, and then the events
    // engine looked up a drone's inventory an hour in the past.
    memset(&tm, 0, sizeof(tm));
    tm.tm_isdst = -1;
    char* conversion_result = strptime(date, format, &tm);

    if (conversion_result != NULL) {
        ee_engine_trace(TRACE_LEVEL_DEBUG,
            "Date [%s] converted to:  year, month, day, hour, min ,sec [%d-%d-%d %02d:%02d:%02d]",
            date,
            tm.tm_year,
            tm.tm_mon,
            tm.tm_mday,
            tm.tm_hour,
            tm.tm_min,
            tm.tm_sec);


        epoch = mktime(&tm);
    }

    ee_engine_trace(TRACE_LEVEL_DEBUG,
        "Converting date into epoch [%s] using format [%s], result = [%ld], conversion_result = [%s]",
        date,
        format,
        epoch,
        conversion_result);

    return epoch;
}


/******************************* PUBLIC FUNCTIONS ****************************/

/** ***************************************************************************

  @brief          Creates a DB connection using credentials supplied

  @param[in|out]  Connection info, updated once connected

  @return         Execution result

******************************************************************************/
ErrorCode_t ee_db_init(DbConnection_t* connection)
{
	MYSQL* local_hndl = NULL;
	ErrorCode_t result = ENGINE_OK;

    if(connection)
    {
    	ee_engine_trace(TRACE_LEVEL_DEBUG,
    		"Connecting to DB MySQL Client Version [%lu]",
    		mysql_get_client_version());

        local_hndl = mysql_init(NULL);

        if(local_hndl)
        {
            MYSQL *db_result;
            db_result = mysql_real_connect(local_hndl,
                connection->host,
                connection->user,
                connection->password,
                connection->db_name,
                connection->port,
                NULL, 0);

            if(db_result != NULL) {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                	"Succesfully connected to: %s "
                    "user [%s] host [%s] port [%d] database [%s]",
                    mysql_get_client_info(),
                    connection->user?connection->user:"NULL",
                    connection->host?connection->host:"NULL",
                    connection->port,
                    connection->db_name?connection->db_name:"NULL");

                // Save connection handler
                connection->hndl = local_hndl;

            } else {
                 ee_engine_trace(TRACE_LEVEL_ALWAYS,
                 	"ERROR: Unable to conect to DB. "
                    "user [%s] host [%s] port [%d] database [%s]",
                    connection->user?connection->user:"NULL",
                    connection->host?connection->host:"NULL",
                    connection->port,
                    connection->db_name?connection->db_name:"NULL");

                 mysql_close(local_hndl);   // a failed attempt (#703)
                 result = ENGINE_DB_CONNECT_ERROR;
            }
        }
        else
       	{
       		ee_engine_trace(TRACE_LEVEL_ALWAYS,
    			"ERROR: Unable to init DB connection");

       		result = ENGINE_DB_INIT_ERROR;
       	}
    }
    else
    {
        // NULL input
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
        	"ERROR: Could not connect to DB, NULL input");

        result = ENGINE_INTERNAL_ERROR;
    }

    return result;
}

/** ****************************************************************************

  @brief          Closes connection with DB

  @param[in|out]  Connection info, updated once disconnected

  @return         void

*******************************************************************************/
void ee_db_stop(DbConnection_t* connection)
{
	if(connection && connection->hndl) {
        mysql_close(connection->hndl);
        connection->hndl = NULL;
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
        	"Disconnected from DB [%s]",
            connection->db_name?connection->db_name:"NULL");
    }
}

/** ***************************************************************************

  @brief          Initiates a transaction (group of queries associated)

  @param[in|out]  DB Connection info

  @return         Execution result

******************************************************************************/
ErrorCode_t ee_db_start_transaction(DbConnection_t* connection)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // Execute it at mysql side and check results
    if (mysql_query(connection->hndl, "START TRANSACTION"))
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,"ERROR: Could not start DB transaction");
        result = ENGINE_DB_QUERY_ERROR;
    }
    else
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "DB transaction started");
    }

    return result;
}

/** ***************************************************************************

  @brief          Ends/commits a transaction (group of queries associated)

  @param[in|out]  DB Connection info

  @return         Execution result

******************************************************************************/
ErrorCode_t ee_db_commit_transaction(DbConnection_t* connection)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // Execute it at mysql side and check results
    if (mysql_query(connection->hndl, "COMMIT"))
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,"ERROR: Could not commit DB transaction");
        result = ENGINE_DB_QUERY_ERROR;
        event_heap_stage_discard(connection);   // EVENT_HEAP: never seen
        wake_heap_stage_discard(connection);    // WAKE_HEAP: the same
    }
    else
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "DB transaction commit");
        // EVENT_HEAP (#694 stage 4 step 3): the events this transaction
        // inserted are pending for everyone now
        event_heap_stage_commit(connection);
        // WAKE_HEAP (step 4): a launched drone's first command too
        wake_heap_stage_commit(connection);
    }

    return result;
}

/** ***************************************************************************

  @brief          Rollback a transaction (group of queries associated)

  @param[in|out]  DB Connection info

  @return         Execution result

******************************************************************************/
ErrorCode_t ee_db_rollback_transaction(DbConnection_t* connection)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // Execute it at mysql side and check results
    if (mysql_query(connection->hndl, "ROLLBACK"))
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,"ERROR: Could not rollback DB transaction");
        result = ENGINE_DB_QUERY_ERROR;
    }
    else
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "DB transaction rollback");
    }

    event_heap_stage_discard(connection);   // EVENT_HEAP: these never were
    wake_heap_stage_discard(connection);    // WAKE_HEAP: the same

    return result;
}

/** ****************************************************************************

    @brief          Fills an event from a "SELECT * FROM events" row

*******************************************************************************/
static void db_row_to_event(MYSQL_ROW row, Event_t* event)
{
    memset(event, 0, sizeof(*event));
    // Pick event fields
    event->event_id = row[EVENT_ID_IDX]?atoi(row[EVENT_ID_IDX]):0;
    event->event_type = row[EVENT_TYPE_IDX]?atoi(row[EVENT_TYPE_IDX]):0;
    event->action_id = row[EVENT_ACTION_IDX]?atoi(row[EVENT_ACTION_IDX]):0;
    event->logged = row[EVENT_LOGGED_IDX]?atoi(row[EVENT_LOGGED_IDX]):0;
    event->outcome = row[EVENT_OUTCOME_IDX]?atoi(row[EVENT_OUTCOME_IDX]):0;
    event->drone_id = row[EVENT_DRONE_ID_IDX]?atoi(row[EVENT_DRONE_ID_IDX]):0;
    event->resource_id = row[EVENT_RESOURCE_ID_IDX]?atoi(row[EVENT_RESOURCE_ID_IDX]):0;
    event->installed = row[EVENT_INSTALLED_IDX]?atoi(row[EVENT_INSTALLED_IDX]):0;
    event->locked = row[EVENT_LOCKED_IDX]?atoi(row[EVENT_LOCKED_IDX]):0;
    event->new_quantity = row[EVENT_NEW_QUANTITY_IDX]?atoi(row[EVENT_NEW_QUANTITY_IDX]):NULL_VALUE;
    event->new_credits = row[EVENT_NEW_CREDITS_IDX]?atoi(row[EVENT_NEW_CREDITS_IDX]):NULL_VALUE;
    event->new_location = row[EVENT_NEW_LOCATION_IDX]?atoi(row[EVENT_NEW_LOCATION_IDX]):-1;
    event->new_transmission = row[EVENT_NEW_TRANSMISSION_IDX]?atoi(row[EVENT_NEW_TRANSMISSION_IDX]):-1;
    event->new_cargo = row[EVENT_NEW_CARGO_IDX]?atoi(row[EVENT_NEW_CARGO_IDX]):0;

    // Convert date obtained to time_t
    event->timestamp = ee_db_date_to_timestamp(row[EVENT_TIMESTAMP_IDX], "%F %T");
    event->game_t = row[EVENT_GAME_T_IDX]?atoll(row[EVENT_GAME_T_IDX]):0;

    ee_engine_trace(TRACE_LEVEL_DEBUG,
        "DATE [%s] => TIMESTAMP [%ld] %s",
        row[EVENT_TIMESTAMP_IDX], event->timestamp, row[EVENT_NEW_LOCATION_IDX]);
}

/******************************* DUE EVENTS FROM MEMORY **********************/

/*
 * EVENT_HEAP=1 (#694 stage 4 step 3, #432). The batch query below asked
 * MySQL each pass for the first due, pending events: the costliest
 * statement on the live database. With the heap, event_heap.c keeps every
 * pending event's id and key (game_t, action, id: that query's ORDER BY) in
 * memory, and a pass takes the due ones from it and reads only their rows,
 * by id, with the same conditions and order. An event whose row has gone,
 * has been settled since (outcome != 0: the game engine aborted its action)
 * or whose action is aborted is dropped, as the query would skip it.
 *
 * How the heap stays complete:
 *  - at start, one read of every pending event (the batch query without
 *    its due-time condition and LIMIT);
 *  - the events this program inserts (the game engine's turns, settlement's
 *    follow-ups) go in when their transaction commits (event_heap_stage);
 *  - each pass first reads the events with ids past the highest it has
 *    seen (a short primary-key range), and adds the pending ones not in
 *    yet: other shards' turns and anything another program inserts. So a
 *    pass sees what the batch query would have seen;
 *  - but an auto-increment id is taken before its transaction commits, so
 *    an event can commit after a higher id has been seen: the ids that
 *    read skipped are looked for again each pass for 10 real seconds, and
 *    each real second a sweep reads again the ids of the last 10-20 s;
 *  - every five real minutes, the start's full read again, in case.
 * The log says once a real minute how many each found that weren't in.
 */

#define HEAP_SWEEP_MS        1000
#define HEAP_LAG_STEP_MS     10000
#define HEAP_RECONCILE_MS    (5 * 60 * 1000)
#define HEAP_REPORT_MS       60000

static int heap_loaded = 0;
static int heap_max_id = 0;           /* the highest events id seen */
static int heap_wm_prev = 0;          /* heap_max_id a lag step ago */
static int heap_wm_lag = 0;           /* ... and two: the sweep starts here */
static long long heap_wm_at = 0, heap_swept_at = 0, heap_reconciled_at = 0, heap_reported_at = 0;
static long long heap_swept_new = 0, heap_late_new = 0, heap_reconciled_new = 0, heap_dropped = 0, heap_taken_total = 0;

/* Ids the reads skipped (not committed yet, or never), looked for each
   pass until HEAP_LAG_STEP_MS old */
#define HEAP_GAPS_MAX     1024
#define HEAP_GAP_SPAN_MAX 256
static int heap_gap_ids[HEAP_GAPS_MAX];
static long long heap_gap_at[HEAP_GAPS_MAX];
static int heap_gap_n = 0;

static void db_heap_gap_add(int id, long long now)
{
    if(heap_gap_n == HEAP_GAPS_MAX) {
        // Full: the oldest goes (the sweep still looks)
        memmove(heap_gap_ids, heap_gap_ids + 1, sizeof(int) * (HEAP_GAPS_MAX - 1));
        memmove(heap_gap_at, heap_gap_at + 1, sizeof(long long) * (HEAP_GAPS_MAX - 1));
        heap_gap_n--;
    }
    heap_gap_ids[heap_gap_n] = id;
    heap_gap_at[heap_gap_n] = now;
    heap_gap_n++;
}

static void db_heap_gap_remove(int id)
{
    for(int i = 0; i < heap_gap_n; i++)
        if(heap_gap_ids[i] == id) {
            heap_gap_n--;
            memmove(heap_gap_ids + i, heap_gap_ids + i + 1, sizeof(int) * (size_t)(heap_gap_n - i));
            memmove(heap_gap_at + i, heap_gap_at + i + 1, sizeof(long long) * (size_t)(heap_gap_n - i));
            return;
        }
}

/* The events taken from the heap for this batch, and which had pending rows */
static EventHeapEntry_t* heap_batch = NULL;
static char* heap_batch_loaded = NULL;
static int heap_batch_n = 0, heap_batch_size = 0, heap_batch_scan = 0, heap_batch_queried = 0;

static long long db_heap_ms(void)
{
    struct timespec t;
    clock_gettime(CLOCK_MONOTONIC, &t);
    return (long long)t.tv_sec * 1000 + t.tv_nsec / 1000000;
}

/* Adds the rows of an "id, game_t, action" query to the heap: *added, how
   many weren't in. With two more columns, "outcome, aborted", it adds only
   the pending ones, as the batch query's conditions say, and notes the
   highest id of all. gaps: 1, the rows come in id order past heap_max_id,
   and the ids they skip are noted; 2, the rows are noted ids found */
static ErrorCode_t db_heap_read(DbConnection_t* connection, const char* query, int* added, int* rows, int gaps)
{
    *added = 0;
    if(rows) *rows = 0;
    if(mysql_query(connection->hndl, query)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    if(!res) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s]: no result: %s", query, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    EventHeap_t* heap = event_heap_global();
    ErrorCode_t result = ENGINE_OK;
    MYSQL_ROW row;
    int filter = mysql_num_fields(res) >= 5;
    while((row = mysql_fetch_row(res))) {
        int id = row[0] ? atoi(row[0]) : 0;
        long long game_t = row[1] ? atoll(row[1]) : 0;
        long long action = row[2] ? atoll(row[2]) : EVENT_HEAP_NULL_ACTION;
        if(id <= 0) continue;
        if(gaps == 1 && id > heap_max_id + 1 && id - heap_max_id <= HEAP_GAP_SPAN_MAX) {
            long long now = db_heap_ms();
            for(int g = heap_max_id + 1; g < id; g++)
                db_heap_gap_add(g, now);
        }
        if(gaps == 2)
            db_heap_gap_remove(id);
        if(id > heap_max_id) heap_max_id = id;
        // outcome = 0 and (aborted) != 1: a NULL aborted (no action) is not != 1
        if(filter && (!row[3] || atoi(row[3]) != 0 || !row[4] || atoi(row[4]) == 1))
            continue;
        int pushed = event_heap_push(heap, id, game_t, action);
        if(pushed < 0) { result = ENGINE_MEMORY_ALLOC_ERROR; break; }
        *added += pushed;
        if(rows) (*rows)++;
    }
    mysql_free_result(res);
    return result;
}

static ErrorCode_t db_heap_max_id(DbConnection_t* connection, int* max_id)
{
    if(mysql_query(connection->hndl, "SELECT MAX(id) FROM events")) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [SELECT MAX(id) FROM events] failed: %s", mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    if(!res) return ENGINE_DB_QUERY_ERROR;
    MYSQL_ROW row = mysql_fetch_row(res);
    *max_id = (row && row[0]) ? atoi(row[0]) : 0;
    mysql_free_result(res);
    return ENGINE_OK;
}

/* Every pending event: at start, and every few minutes */
static ErrorCode_t db_heap_load_all(DbConnection_t* connection, const char* why)
{
    int top = 0, added = 0, rows = 0;
    long long started = db_heap_ms();
    // The top id first: what is inserted while this reads, the sweep finds
    ErrorCode_t result = db_heap_max_id(connection, &top);
    if(result == ENGINE_OK)
        result = db_heap_read(connection,
            "SELECT id, game_t, action FROM events AS e WHERE outcome = 0 "
            "AND (SELECT aborted FROM actions WHERE id = e.action) != 1",
            &added, &rows, 0);
    if(result != ENGINE_OK)
        return result;
    if(!heap_loaded) {
        heap_loaded = 1;
        if(top > heap_max_id) heap_max_id = top;
        heap_wm_prev = heap_wm_lag = top;
        heap_wm_at = heap_swept_at = heap_reconciled_at = heap_reported_at = db_heap_ms();
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "NOTICE: EVENT_HEAP=1: due events come from memory: [%d] pending events loaded in [%lld] ms (top id [%d])",
            rows, db_heap_ms() - started, top);
    } else {
        heap_reconciled_new += added;
        ee_engine_trace(added ? TRACE_LEVEL_ALWAYS : TRACE_LEVEL_DEBUG,
            "%sEVENT_HEAP: %s: [%d] pending events, [%d] of them not in the heap, in [%lld] ms",
            added ? "WARNING: " : "", why, rows, added, db_heap_ms() - started);
    }
    return ENGINE_OK;
}

/* Each pass: the events past the highest id seen */
static ErrorCode_t db_heap_read_new(DbConnection_t* connection)
{
    char query[512];
    int added = 0;
    snprintf(query, sizeof(query),
        "SELECT id, game_t, action, outcome, (SELECT aborted FROM actions WHERE id = e.action) "
        "FROM events AS e FORCE INDEX (PRIMARY) WHERE e.id > %d ORDER BY e.id", heap_max_id);
    ErrorCode_t result = db_heap_read(connection, query, &added, NULL, 1);
    heap_swept_new += added;
    return result;
}

/* Each pass: the ids the reads skipped, in case they have committed since */
static ErrorCode_t db_heap_read_gaps(DbConnection_t* connection, long long now)
{
    // Forget those old enough for the sweep
    int kept = 0;
    for(int i = 0; i < heap_gap_n; i++)
        if(now - heap_gap_at[i] < HEAP_LAG_STEP_MS) {
            heap_gap_ids[kept] = heap_gap_ids[i];
            heap_gap_at[kept] = heap_gap_at[i];
            kept++;
        }
    heap_gap_n = kept;
    if(!heap_gap_n)
        return ENGINE_OK;
    size_t len = 256 + (size_t)heap_gap_n * 12;
    char* query = malloc(len);
    if(!query)
        return ENGINE_MEMORY_ALLOC_ERROR;
    char* end = query;
    end += sprintf(end, "SELECT id, game_t, action, outcome, (SELECT aborted FROM actions WHERE id = e.action) "
        "FROM events AS e WHERE e.id IN (");
    for(int i = 0; i < heap_gap_n; i++)
        end += sprintf(end, i ? ",%d" : "%d", heap_gap_ids[i]);
    sprintf(end, ")");
    int added = 0;
    ErrorCode_t result = db_heap_read(connection, query, &added, NULL, 2);
    free(query);
    heap_late_new += added;
    return result;
}

/* Each real second: the pending events past those seen 10-20 real seconds
   ago, for any that committed after a higher id was seen */
static ErrorCode_t db_heap_sweep(DbConnection_t* connection, long long now)
{
    int top = heap_max_id, added = 0;
    ErrorCode_t result = ENGINE_OK;
    if(top > heap_wm_lag) {
        char query[512];
        snprintf(query, sizeof(query),
            "SELECT id, game_t, action FROM events AS e FORCE INDEX (PRIMARY) WHERE e.id > %d AND e.id <= %d "
            "AND e.outcome = 0 AND (SELECT aborted FROM actions WHERE id = e.action) != 1",
            heap_wm_lag, top);
        result = db_heap_read(connection, query, &added, NULL, 0);
        if(result != ENGINE_OK) return result;
        heap_late_new += added;
        if(added)
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "EVENT_HEAP: the sweep (ids %d-%d) found [%d] pending events that committed late",
                heap_wm_lag + 1, top, added);
    }
    heap_swept_at = now;
    // Ids are taken before their transaction commits: look back two steps
    if(now - heap_wm_at >= HEAP_LAG_STEP_MS) {
        heap_wm_lag = heap_wm_prev;
        heap_wm_prev = heap_max_id;
        heap_wm_at = now;
    }
    return ENGINE_OK;
}

/* Before a batch: loads or sweeps the heap when due, takes its due events
   (at most batch_size) and makes the query for their rows; *query = NULL
   when none are due (the caller frees it) */
static ErrorCode_t db_event_heap_take_due(DbConnection_t* connection, int batch_size, char** query)
{
    *query = NULL;
    heap_batch_n = 0;
    heap_batch_scan = 0;
    heap_batch_queried = 0;

    long long now = db_heap_ms();
    ErrorCode_t result = ENGINE_OK;
    if(!heap_loaded)
        result = db_heap_load_all(connection, "start");
    else if(now - heap_reconciled_at >= HEAP_RECONCILE_MS) {
        result = db_heap_load_all(connection, "the five-minute check");
        heap_reconciled_at = now;
    }
    if(result == ENGINE_OK)
        result = db_heap_read_gaps(connection, now);
    if(result == ENGINE_OK)
        result = db_heap_read_new(connection);
    if(result == ENGINE_OK && now - heap_swept_at >= HEAP_SWEEP_MS)
        result = db_heap_sweep(connection, now);
    if(result != ENGINE_OK)
        return result;

    EventHeap_t* heap = event_heap_global();
    if(now - heap_reported_at >= HEAP_REPORT_MS) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "NOTICE: EVENT_HEAP: [%d] pending in memory; last minute: [%lld] taken, [%lld] dropped (settled or aborted "
            "elsewhere), found not pushed by this engine: [%lld] new, [%lld] committed late, [%lld] by the five-minute check",
            event_heap_count(heap), heap_taken_total, heap_dropped, heap_swept_new, heap_late_new, heap_reconciled_new);
        heap_taken_total = heap_dropped = heap_swept_new = heap_late_new = heap_reconciled_new = 0;
        heap_reported_at = now;
    }

    if(batch_size <= 0)
        return ENGINE_OK;
    if(heap_batch_size < batch_size) {
        EventHeapEntry_t* b = realloc(heap_batch, sizeof(EventHeapEntry_t) * (size_t)batch_size);
        if(b) heap_batch = b;
        char* l = realloc(heap_batch_loaded, (size_t)batch_size);
        if(l) heap_batch_loaded = l;
        if(!b || !l) return ENGINE_MEMORY_ALLOC_ERROR;
        heap_batch_size = batch_size;
    }

    // Due: the query's "game_t <= now"
    long long due = game_time_now_seconds();
    const EventHeapEntry_t* first;
    while(heap_batch_n < batch_size && (first = event_heap_peek(heap)) && first->game_t <= due) {
        event_heap_pop(heap, &heap_batch[heap_batch_n]);
        heap_batch_loaded[heap_batch_n] = 0;
        heap_batch_n++;
    }
    heap_taken_total += heap_batch_n;
    if(!heap_batch_n)
        return ENGINE_OK;

    size_t len = 256 + (size_t)heap_batch_n * 12;
    char* q = malloc(len);
    if(!q) {
        // Back they go
        for(int i = 0; i < heap_batch_n; i++)
            event_heap_push(heap, heap_batch[i].id, heap_batch[i].game_t, heap_batch[i].action);
        heap_batch_n = 0;
        return ENGINE_MEMORY_ALLOC_ERROR;
    }
    char* end = q;
    end += sprintf(end, "SELECT * FROM events as e where id IN (");
    for(int i = 0; i < heap_batch_n; i++)
        end += sprintf(end, i ? ",%d" : "%d", heap_batch[i].id);
    // The batch query's conditions but the due time, and its order
    sprintf(end, ") and outcome = 0 and (select aborted from actions where id = e.action) != 1 "
        "ORDER BY game_t ASC, action ASC, id ASC;");
    *query = q;
    return ENGINE_OK;
}

/* A row of this batch was read: its event was still pending */
static void db_event_heap_loaded(int id)
{
    if(!event_heap_on() || !heap_batch_n) return;
    // The rows come in the heap's order: look on from the last one found
    for(int k = 0; k < heap_batch_n; k++) {
        int i = (heap_batch_scan + k) % heap_batch_n;
        if(heap_batch[i].id == id) {
            heap_batch_loaded[i] = 1;
            heap_batch_scan = (i + 1) % heap_batch_n;
            return;
        }
    }
}

static int db_event_heap_taken(void)
{
    return heap_batch_n;
}

void db_event_heap_batch_done(int committed)
{
    if(!event_heap_on() || !heap_batch_n)
        return;
    EventHeap_t* heap = event_heap_global();
    for(int i = 0; i < heap_batch_n; i++) {
        EventHeapEntry_t* e = &heap_batch[i];
        if(!heap_batch_loaded[i]) {
            // Its row wasn't pending: dropped, as the query would have
            // skipped it. Unless the read itself failed: then all go back
            if(heap_batch_queried) { heap_dropped++; continue; }
        } else if(committed) {
            // Settled: done. Still pending (a passing failure): back in, as
            // the query would read it again
            Bool_t settled = ENGINE_FALSE;
            if(db_event_settled_in_batch(e->id, &settled) == ENGINE_OK && settled)
                continue;
        }
        event_heap_push(heap, e->id, e->game_t, e->action);
    }
    heap_batch_n = 0;
}

/** ****************************************************************************

    @brief          Gets next batch of outcome events

    @param[in]      Callback to be invoked when outcome event detected

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_outcome_events(void (*outcomeEventCb)(Event_t *e), int *found)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection = ee_engine_get_db_connection();
    MYSQL_RES* db_result = NULL;
    int rowsNum = 0;
    char* heap_query = NULL;
    const char* query = query_text;
    query_text[0] = '\0';

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    db_settled_batch_start();
    db_batch_writes_discard();

    if(result == ENGINE_OK)
    {
        int events_batch_size = engine_get_outcome_events_batch_size();
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "Getting next batch of [%d] not processed events ...",
            events_batch_size);

        if(event_heap_on())
        {
            // EVENT_HEAP=1 (#694 stage 4 step 3): the due events come from
            // the heap, in this same order, and their rows by id
            result = db_event_heap_take_due(connection, events_batch_size, &heap_query);
            query = heap_query;
        }
        else
        {
            snprintf(query_text,
                DB_MAX_SQL_QUERY_LEN,
                "SELECT * FROM events as e where game_t <= %lld and outcome = 0 and (select aborted from actions where id = e.action) != 1 ORDER BY game_t ASC, action ASC, id ASC LIMIT %d;",
                game_time_now_seconds(),   // due in game time (#327)
                events_batch_size);
        }

        // run it (with the heap and nothing due, there is nothing to run)
        if (result == ENGINE_OK && query && mysql_query(connection->hndl, query))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query);
            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if(result == ENGINE_OK && !query)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "None pending event found");
    }
    else if(result == ENGINE_OK)
    {
        // retrieve the results and process one by one
        db_result = mysql_store_result(connection->hndl);
        rowsNum = db_result ? mysql_num_rows(db_result) : 0;

        if(db_result == NULL)
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get outcome events (invalid result for query [%s])",
                query);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if(rowsNum <= 0)
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "None pending event found");
        }
    }

    if((result == ENGINE_OK) && rowsNum)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "[%d] pending events found", (int)rowsNum);

        // iterate results and delete one by one
        for(int eventId=0; eventId < rowsNum; eventId++)
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row)
            {
                Event_t event;
                db_row_to_event(row, &event);
                db_event_heap_loaded(event.event_id);

                // Invoke callback when != NULL
                if(outcomeEventCb) outcomeEventCb(&event);
            }
            else
            {
                // unexpected error - abort
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get row for query [%s] with [%d] rows",
                    query,
                    rowsNum);

                result = ENGINE_DB_QUERY_ERROR;

                break; // stop for
            }
        }
    }

    if(db_result)
        mysql_free_result(db_result);
    // Every row of the batch read: the events taken but not read weren't
    // pending, and are dropped (db_event_heap_batch_done)
    if(heap_query && result == ENGINE_OK)
        heap_batch_queried = 1;
    free(heap_query);

    // With the heap, the events taken from it: a full batch means more may
    // be due, as a full LIMIT did
    if(found)
        *found = (result == ENGINE_OK) ? (event_heap_on() ? db_event_heap_taken() : rowsNum) : 0;

    return result;
}

/** ****************************************************************************

    @brief          Purges old actions present at DB (older than configurable days value)
                    Those actions that have no event associated

    @param[in]      void

    @return         Execution result

*******************************************************************************/
static long long db_archive_ms(void);

ErrorCode_t db_purge_old_actions()
{
    // One bounded delete a pass (#818): the actions older than the
    // expiration with no event left in events or events_archive. It listed
    // them all and deleted them one by one, inside the events engine's loop:
    // once the history budget (#813) pruned the archive, millions qualified
    DbConnection_t* connection = ee_engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);
    if(result != ENGINE_OK)
        return result;
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "DELETE FROM actions WHERE timestamp < (NOW() - INTERVAL %d DAY) "
        "AND NOT EXISTS (SELECT 1 FROM events e WHERE e.action = actions.id) "
        "AND NOT EXISTS (SELECT 1 FROM events_archive a WHERE a.action = actions.id) "
        "ORDER BY id LIMIT 5000",
        engine_get_actions_expiration_days());
    long long start = db_archive_ms();
    if(mysql_query(connection->hndl, query_text)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    long long n = (long long)mysql_affected_rows(connection->hndl);
    if(n > 0)
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "Purged [%lld] expired actions in [%lld] ms", n, db_archive_ms() - start);
    return ENGINE_OK;
}

/** ****************************************************************************

    @brief          Deletes event giving event_id

    @param[in]      Input event

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_event(Event_t* event)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!event) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "DELETE FROM events WHERE event_id = %d",
            event->event_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "EVENT_ID [%d] deleted from DB",
                event->event_id);

            // Delete also associated action
            //db_delete_action(event->action_id);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Gets previous event using input one and applying some filters

    @param[in]      event     Event to do the search
    @param[in]      filter    Enum that determines the filter to be applied at search
    @param[in]      out_event Output event obtained (when any)

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_previous_event(Event_t *event, PreviousEventFilter_t filter, Event_t *out_event)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // Sanity check
    if(!event || !out_event)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "ERROR: Unable to get previous event for NULL input parameters");

        return ENGINE_INTERNAL_ERROR;
    }

    DbConnection_t* connection = ee_engine_get_db_connection();
    MYSQL_RES* db_result = NULL;
    int rowsNum = 0;

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "Getting previous event for event_id [%d] and filter type [%d] ...",
            event->event_id, filter);

        // Events are ordered by when they happen in game time (#327)
        long long game_t = event->game_t;

        // Build the query depending on filter type
        switch(filter) {
            case PREVIOUS_EVENT_BY_RESOURCE_INFO:
                snprintf(query_text,
                    DB_MAX_SQL_QUERY_LEN,
                    // One step back down events_inventory_time (migration
                    // 0033, #431): events_inventory read every such event
                    // the drone ever had and sorted them
                    "SELECT * "
                    "FROM events FORCE INDEX (events_inventory_time) "
                    "WHERE drone = %d and resource = %d and installed = %d and locked = %d "
                    "and outcome = %d " // success
                    "and (event_type = 1 or event_type = 2)" // look only for increment and depletion events
                    "and game_t <= %lld order by game_t DESC, id DESC limit 1;",  //
                    event->drone_id, event->resource_id, event->installed, event->locked, OUTCOME_OK, game_t);
                break;
            case PREVIOUS_EVENT_BY_DRONE:
                snprintf(query_text,
                    DB_MAX_SQL_QUERY_LEN,
                    "SELECT * "
                    "FROM events "
                    "WHERE drone = %d "
                    "and outcome = %d " // success
                    "and game_t < %lld order by game_t DESC, id DESC limit 1;",
                    event->drone_id, OUTCOME_OK, game_t);
                break;
            case PREVIOUS_EVENT_BY_OWNER:
                snprintf(query_text,
                    DB_MAX_SQL_QUERY_LEN,
                    // Each of the company's drones' latest event, then the
                    // latest of those (#422: gathering and sorting all the
                    // company's events took ~120 ms for the House by day 65)
                    "SELECT e.* FROM agents a JOIN LATERAL (SELECT * FROM events "
                    "WHERE drone = a.agent_id AND outcome = %d " // success
                    "AND game_t < %lld ORDER BY game_t DESC, id DESC LIMIT 1) e ON TRUE "
                    "WHERE a.company_id = (SELECT company_id FROM agents WHERE agent_id = %d) "
                    "ORDER BY e.game_t DESC, e.id DESC LIMIT 1;",
                    OUTCOME_OK, game_t, event->drone_id);
                break;
            case PREVIOUS_EVENT_BY_SELL_ORDER:
                snprintf(query_text,
                    DB_MAX_SQL_QUERY_LEN,
                    "SELECT * from events where "
                    "drone = %d and "
                    "event_type = %d and "
                    "outcome = %d and " // success
                    "resource = %d and game_t < %lld order by game_t DESC, id DESC limit 1;",
                    event->drone_id, SELL_EVENT_TYPE, OUTCOME_OK, event->resource_id, game_t);
                break;
            case PREVIOUS_EVENT_BY_BUY_ORDER:
                snprintf(query_text,
                    DB_MAX_SQL_QUERY_LEN,
                    "SELECT * from events where "
                    "drone = %d and "
                    "event_type = %d and "
                    "outcome = %d and " // success
                    "resource = %d and game_t < %lld order by game_t DESC, id DESC limit 1;",
                    event->drone_id, BUY_EVENT_TYPE, OUTCOME_OK, event->resource_id, game_t);
                break;
            default:
                // Unexpected enum value
                ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unexpected filter value [%d] getting previous event", filter);
                result = ENGINE_INTERNAL_ERROR;
                break;
        }
    }

    if(result == ENGINE_OK)
    {
        // run query
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "Running query [%s]", query_text);


        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if(result == ENGINE_OK)
    {
        // retrieve the results and process one by one
        db_result = mysql_store_result(connection->hndl);

        if(db_result == NULL)
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get previous event (invalid result for query [%s])",
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((rowsNum=mysql_num_rows(db_result)) <= 0)
        {
            memset(out_event, 0, sizeof(*out_event));
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "None previous event found");
            result = ENGINE_NOT_FOUND; // Indicates that no entry was found
        }
    }

    if((result == ENGINE_OK) && rowsNum)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "[%d] previous event found", rowsNum);

        // Store the info obtained at output event
        MYSQL_ROW row = mysql_fetch_row(db_result);
        if(row)
        {
            memset(out_event, 0, sizeof(*out_event));

            // Pick event fields
            out_event->event_id = row[EVENT_ID_IDX]?atoi(row[EVENT_ID_IDX]):0;
            out_event->event_type = row[EVENT_TYPE_IDX]?atoi(row[EVENT_TYPE_IDX]):0;
            out_event->action_id = row[EVENT_ACTION_IDX]?atoi(row[EVENT_ACTION_IDX]):0;
            out_event->logged = row[EVENT_LOGGED_IDX]?atoi(row[EVENT_LOGGED_IDX]):0;
            out_event->outcome = row[EVENT_OUTCOME_IDX]?atoi(row[EVENT_OUTCOME_IDX]):0;
            out_event->drone_id = row[EVENT_DRONE_ID_IDX]?atoi(row[EVENT_DRONE_ID_IDX]):0;
            out_event->resource_id = row[EVENT_RESOURCE_ID_IDX]?atoi(row[EVENT_RESOURCE_ID_IDX]):0;
            out_event->installed = row[EVENT_INSTALLED_IDX]?atoi(row[EVENT_INSTALLED_IDX]):0;
            out_event->locked = row[EVENT_LOCKED_IDX]?atoi(row[EVENT_LOCKED_IDX]):0;
            out_event->new_quantity = row[EVENT_NEW_QUANTITY_IDX]?atoi(row[EVENT_NEW_QUANTITY_IDX]):NULL_VALUE;
            out_event->new_credits = row[EVENT_NEW_CREDITS_IDX]?atoi(row[EVENT_NEW_CREDITS_IDX]):NULL_VALUE;
            out_event->new_location = row[EVENT_NEW_LOCATION_IDX]?atoi(row[EVENT_NEW_LOCATION_IDX]):-1;
            out_event->new_transmission = row[EVENT_NEW_TRANSMISSION_IDX]?atoi(row[EVENT_NEW_TRANSMISSION_IDX]):-1;
            out_event->new_cargo = row[EVENT_NEW_CARGO_IDX]?atoi(row[EVENT_NEW_CARGO_IDX]):0;
            // Convert date obtained to time_t
            out_event->timestamp = ee_db_date_to_timestamp(row[EVENT_TIMESTAMP_IDX], "%F %T");
            out_event->game_t = row[EVENT_GAME_T_IDX]?atoll(row[EVENT_GAME_T_IDX]):0;
        }
        else
        {
            // unexpected error
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get previous event row for query [%s]",
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if(db_result)
        mysql_free_result(db_result);

    return result;
}


/** ****************************************************************************

    @brief          Gets action information from DB using its ID

    @param[in|out]  action Action information obtained, we will as input the ID to do the search

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_action(Action_t *action)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!action) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        char* query_end = query_text;

        query_end += snprintf(query_end,
            QUERY_SPACE_LEFT,
            "SELECT id, drone, protocol, multiplier, aborted, timestamp from actions where id = %d",
            action->action_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            // retrieve the result and check that is an only row with expected fields number
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);
            uint64_t rowsNum = mysql_num_rows(db_result);
            unsigned int fieldsNum = mysql_num_fields(db_result);

            if((db_result == NULL) || (rowsNum != 1) || (fieldsNum != MAX_ACTION_FIELDS))
            {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get action info for ACTION_ID [%d] "
                    "(invalid result for query [%s], rows [%d], fields [%d])",
                    action->action_id,
                    query_text,
                    rowsNum,
                    fieldsNum);

                result = ENGINE_DB_QUERY_ERROR;
            }
            else
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row)
                {
                    // Pick the fields we need
                    action->drone_id = row[ACTION_DRONE_ID_IDX]?atoi(row[ACTION_DRONE_ID_IDX]):0;
                    action->protocol_id = row[ACTION_PROTOCOL_ID_IDX]?atoi(row[ACTION_PROTOCOL_ID_IDX]):0;
                    action->process_multiplier = row[ACTION_PROCESS_MULTIPLIER_IDX]?atoi(row[ACTION_PROCESS_MULTIPLIER_IDX]):-1;
                    action->aborted = row[ACTION_ABORTED_ID_IDX]?atoi(row[ACTION_ABORTED_ID_IDX]):0;

                    ee_engine_trace(TRACE_LEVEL_ALWAYS,
                        "DRONE_ID [%d] PROTOCOL_ID [%d] PROCESS_MULTIPLIER [%d] ABORTED [%d] obtained for ACTION_ID [%d]",
                        action->drone_id,
                        action->protocol_id,
                        action->process_multiplier,
                        action->aborted,
                        action->action_id);
                }
                else
                {
                    ee_engine_trace(TRACE_LEVEL_ALWAYS,
                        "ERROR: Unable to get action info for ACTION_ID [%d] (no row)",
                        action->action_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Aborts an action using its ID

    @param[in]      action_id Action to be aborted

    @return         Execution result

*******************************************************************************/
ErrorCode_t ee_db_abort_action(int action_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        char* query_end = query_text;

        query_end += snprintf(query_end,
            QUERY_SPACE_LEFT,
            "UPDATE actions SET aborted = 1 WHERE ID = %d",
            action_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "Action [%d] aborted", action_id);

            // Its tools in use go back at once (#814): an aborted action's
            // unlock never comes (#748: tools left locked for good)
            char unlock_text[128];
            snprintf(unlock_text, sizeof(unlock_text), "DELETE FROM tool_locks WHERE action = %d", action_id);
            if(mysql_query(connection->hndl, unlock_text))
                ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", unlock_text, mysql_error(connection->hndl));
            db_tool_locks_forget_action(action_id);

            // Its events still to come never happen: say so (#725). Left at
            // outcome 0 they stayed "pending" for good, and every batch of
            // the Event Engine walked past them: 328,000 rows a batch on the
            // live database, about half of MySQL's time
            snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
                "UPDATE events SET outcome = -9 WHERE action = %d AND outcome = 0", action_id);
            if (mysql_query(connection->hndl, query_text))
            {
                ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
                result = ENGINE_DB_QUERY_ERROR;
            }
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Updates event info using input info received

    @param[in]      event Event info to be updated

    @return         Execution result

*******************************************************************************/
/* The events this batch has settled (#446). Only the events engine settles
   events, all in db_update_event, and a batch is one transaction: so what
   this list says is what asking MySQL each event's outcome said, without the
   round trip. A list that fills up says "don't know", and MySQL is asked. */
#define SETTLED_IN_BATCH_MAX 4096
static int settled_ids[SETTLED_IN_BATCH_MAX];
static int settled_count = 0;
static int settled_overflow = 0;

static void db_note_settled(int event_id)
{
    if(settled_count < SETTLED_IN_BATCH_MAX)
        settled_ids[settled_count++] = event_id;
    else
        settled_overflow = 1;
}

void db_settled_batch_start(void)
{
    settled_count = 0;
    settled_overflow = 0;
}

ErrorCode_t db_event_settled_in_batch(int event_id, Bool_t* settled)
{
    for(int i = settled_count - 1; i >= 0; i--)
        if(settled_ids[i] == event_id) { *settled = ENGINE_TRUE; return ENGINE_OK; }
    if(settled_overflow) {
        int outcome = OUTCOME_NO_OUTCOME;
        ErrorCode_t result = db_get_event_outcome(event_id, &outcome);
        *settled = (result == ENGINE_OK && outcome != OUTCOME_NO_OUTCOME) ? ENGINE_TRUE : ENGINE_FALSE;
        return result;
    }
    *settled = ENGINE_FALSE;
    return ENGINE_OK;
}

ErrorCode_t db_update_event(Event_t *event)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!event) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        char* query_end = query_text;

        // logged: the EE sets it once it has recorded the event's observations
        // (market orders are inserted unlogged, #254)
        query_end += snprintf(query_end, QUERY_SPACE_LEFT, "UPDATE events SET outcome = %d, logged = %d,",
            event->outcome, event->logged ? 1 : 0);


        // Check first if we have any value to set
        if((event->new_quantity != NULL_VALUE) || (event->new_credits != NULL_VALUE) || (event->new_cargo != NULL_VALUE))
        {
            if(event->new_quantity != NULL_VALUE)
                query_end += snprintf(query_end, QUERY_SPACE_LEFT, " new_quantity = %d,", event->new_quantity);

            if(event->new_credits != NULL_VALUE)
                query_end += snprintf(query_end, QUERY_SPACE_LEFT, " new_credits = %d,", event->new_credits);

            if(event->new_cargo != NULL_VALUE)
                query_end += snprintf(query_end, QUERY_SPACE_LEFT, " new_cargo = %d,", event->new_cargo);
        }

        query_end--; // overwrite the latest comma
        query_end += snprintf(query_end, QUERY_SPACE_LEFT, " WHERE id = %d", event->event_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            if(event->outcome != OUTCOME_NO_OUTCOME)
                db_note_settled(event->event_id);

            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "Event [%d] updated with outcome [%d] new_quantity [%d] new_credits [%d] new cargo [%d]",
                event->event_id, event->outcome, event->new_quantity, event->new_credits, event->new_cargo);
        }
    }

    return result;
}


/** ****************************************************************************

    @brief          Updates current agent object ID (when current location changes)

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose output we want to update
    @param[in]      New object ID to be set

    @return         Execution result

*******************************************************************************/
ErrorCode_t ee_db_update_agent_object(DbConnection_t* connection, int agent_id, int object_id)
{
    // sanity
    if(!connection) return ENGINE_INTERNAL_ERROR;

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // Prepare query
    char query_text[DB_MAX_SQL_QUERY_LEN + 1]; // enough buffer

    snprintf(query_text,
        DB_MAX_SQL_QUERY_LEN,
        "UPDATE agents SET OBJECT_ID = '%d' where AGENT_ID = %d",
        object_id,
        agent_id);

    // run it
    if (mysql_query(connection->hndl, query_text))
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }
    else
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "Updated Object ID for agent [%d] with value [%d]", agent_id, object_id);
    }

    return result;
}

/** ****************************************************************************

    @brief          Gets the max cargo supported by a given drone

    @param[in|out]  capacity Output param where we store max capacity when success

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_max_drone_cargo(int drone_id, int *capacity)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!capacity) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        char* query_end = query_text;

        query_end += snprintf(query_end,
            QUERY_SPACE_LEFT,
            "SELECT cargo_capacity from hulls where id = (select hull_type from agents where agent_id = %d)",
            drone_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            // retrieve the result and check that is an only row with expected fields number
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);
            uint64_t rowsNum = mysql_num_rows(db_result);
            unsigned int fieldsNum = mysql_num_fields(db_result);

            if((db_result == NULL) || (rowsNum != 1) || (fieldsNum != 1))
            {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get max cargo for DRONE_ID [%d] "
                    "(invalid result for query [%s], rows [%d], fields [%d])",
                    drone_id,
                    query_text,
                    rowsNum,
                    fieldsNum);

                result = ENGINE_DB_QUERY_ERROR;
            }
            else
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row)
                {
                    // Pick the only field value
                    *capacity = row[0]?atoi(row[0]):0;

                    ee_engine_trace(TRACE_LEVEL_ALWAYS,
                        "DRONE_ID [%d] MAX_CAPACITY [%d]",
                        drone_id,
                        *capacity );
                }
                else
                {
                    ee_engine_trace(TRACE_LEVEL_ALWAYS,
                        "ERROR: Unable to get max drone capacity for drone [%d] (no row)",
                        drone_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Inserts a new observation entry

    @param[in|out]  Action info to be inserted

    @return         Execution result

*******************************************************************************/
/* Observations and event places are written once per batch (#448): a
   round trip each was a third of the events engine's writes. Nothing in the
   events engine reads them back while the batch runs, except a refund's
   place, and a move changes the place later events record: those flush the
   places first. A transfer rolled back to its savepoint drops what it added
   (db_batch_writes_mark / db_batch_writes_undo). */
#define BATCH_OBSERVATIONS_MAX 4096
#define BATCH_PLACES_MAX 4096
typedef struct { int drone; int event; char time[24]; long long game_t; } PendingObservation_t;
static PendingObservation_t pending_observations[BATCH_OBSERVATIONS_MAX];
static int pending_observations_count = 0;
static int pending_places[BATCH_PLACES_MAX];
static int pending_places_count = 0;

static ErrorCode_t db_run_write(const char* query_text)
{
    DbConnection_t* connection = ee_engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);
    if(result != ENGINE_OK) return result;
    if(mysql_query(connection->hndl, query_text)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%.200s...] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    return ENGINE_OK;
}

/*
 * Frees the tools left locked by aborted actions (#748), as migration 0067
 * did once (#711): a recipe locks its tool while it works, and an action
 * aborted between the lock and the unlock (a full disk, a lost connection)
 * left it locked for good, so every later make or mine failed with -2. Round
 * 71's matured Makers died of it: the day-2161 outage locked their Smelters,
 * and the one-off repair came 600 game days after they had dissolved. The
 * events engine now does it each time it starts, which it does after any
 * outage. Each locked holding that no pending event will unlock goes back to
 * the drone's free stock. Returns the number freed, or -1 on an error.
 */
int db_free_stuck_tools(void)
{
    DbConnection_t* connection = ee_engine_get_db_connection();
    if(db_reconnect(connection) != ENGINE_OK) return -1;
    const char* steps[] = {
        "SET @free_now := (SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale) FROM game_clock WHERE id = 1)",
        "DROP TEMPORARY TABLE IF EXISTS stuck_tools",
        "CREATE TEMPORARY TABLE stuck_tools AS "
        "SELECT h.drone, h.resource, h.installed, h.units AS locked_units, "
        "IFNULL((SELECT f.units FROM holdings AS f WHERE f.drone = h.drone AND f.resource = h.resource "
        "AND f.installed = h.installed AND f.locked = 0), 0) AS free_units, a.object_id AS place "
        "FROM holdings AS h JOIN agents AS a ON a.agent_id = h.drone "
        "WHERE h.locked = 1 AND h.units > 0 AND h.installed IN (0, 1) "
        "AND NOT EXISTS (SELECT 1 FROM events AS e JOIN actions AS x ON x.id = e.action "
        "WHERE e.drone = h.drone AND e.resource = h.resource AND e.locked = 1 AND e.outcome = 0 AND x.aborted = 0)",
        "START TRANSACTION",
        "INSERT INTO events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, game_t, place) "
        "SELECT 1, NULL, 1, 1, drone, resource, installed, 0, free_units + locked_units, @free_now, place FROM stuck_tools",
        "INSERT INTO events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, game_t, place) "
        "SELECT 2, NULL, 1, 1, drone, resource, installed, 1, 0, @free_now, place FROM stuck_tools",
        "COMMIT",
        "DROP TEMPORARY TABLE IF EXISTS stuck_tools",
        // And locks (#814) whose action will never give them back: aborted,
        // or with no unlock still to settle
        "DELETE t FROM tool_locks AS t WHERE NOT EXISTS (SELECT 1 FROM events AS e JOIN actions AS x ON x.id = e.action "
        "WHERE e.action = t.action AND e.event_type = 16 AND e.outcome = 0 AND x.aborted = 0)",
    };
    int freed = 0;
    for(size_t i = 0; i < sizeof(steps) / sizeof(steps[0]); i++) {
        if(mysql_query(connection->hndl, steps[i])) {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: freeing stuck tools, step %d failed: %s", (int)i, mysql_error(connection->hndl));
            mysql_query(connection->hndl, "ROLLBACK");
            mysql_query(connection->hndl, "DROP TEMPORARY TABLE IF EXISTS stuck_tools");
            return -1;
        }
        if(i == 4) freed = (int)mysql_affected_rows(connection->hndl);
    }
    return freed;
}

/* A number from a one-value query on the events engine's connection, or dflt (#814) */
static long long db_tool_number(DbConnection_t* connection, const char* sql, long long dflt)
{
    long long value = dflt;
    if(mysql_query(connection->hndl, sql)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", sql, mysql_error(connection->hndl));
        return dflt;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    if(row && row[0]) value = atoll(row[0]);
    if(res) mysql_free_result(res);
    return value;
}

/*
 * The fleet cap when a launch lands (#746): the cap when the launcher's
 * company already has that many working drones (the House's companies
 * aren't capped), else 0. The game engine checks it when the launch is
 * planned, but a company still came to 9 drones; the events engine applies
 * launches one at a time, so here the count is exact.
 */
int db_fleet_full_e(int launcher_id)
{
    DbConnection_t* connection = ee_engine_get_db_connection();
    char sql[DB_MAX_SQL_QUERY_LEN+1];
    snprintf(sql, DB_MAX_SQL_QUERY_LEN,
        "SELECT IF(c.house = 0 AND r.fleet_cap > 0 AND "
        "(SELECT COUNT(*) FROM agents w WHERE w.company_id = c.company_id AND w.wrecked_at IS NULL) >= r.fleet_cap, "
        "r.fleet_cap, 0) FROM agents l JOIN companies c ON c.company_id = l.company_id "
        "JOIN house_rules r ON r.id = 1 WHERE l.agent_id = %d", launcher_id);
    return (int)db_tool_number(connection, sql, 0);
}

/* A resource's mass (1 when unknown) */
int db_resource_mass_e(int resource)
{
    char sql[DB_MAX_SQL_QUERY_LEN+1];
    snprintf(sql, DB_MAX_SQL_QUERY_LEN, "SELECT mass FROM resources WHERE id = %d", resource);
    return (int)db_tool_number(ee_engine_get_db_connection(), sql, 1);
}

/*
 * The locks in force, in the events engine's memory (#814), so a decrement
 * checks them without a query: only this engine adds locks (a lock event
 * settling); unlocks, and aborts here, take them out. An abort in the game
 * engine only deletes rows, so the memory can only be stricter than the
 * table, and a decrement it would refuse is checked against the table
 * first (db_tool_lock_blocks).
 */
typedef struct { int drone, resource, installed, units, action; } ToolLock_t;
static ToolLock_t* tool_locks = NULL;
static int tool_locks_count = 0, tool_locks_size = 0;

static void tool_locks_add(int drone, int resource, int installed, int units, int action)
{
    if(tool_locks_count == tool_locks_size) {
        int size = tool_locks_size ? tool_locks_size * 2 : 256;
        ToolLock_t* grown = realloc(tool_locks, sizeof(ToolLock_t) * size);
        if(!grown) return;   // out of memory: the table still holds it, and a refusal is checked there
        tool_locks = grown;
        tool_locks_size = size;
    }
    tool_locks[tool_locks_count++] = (ToolLock_t){ drone, resource, installed, units, action };
}

/* Takes out the locks matching: an action's (resource < 0: all of them) */
static void tool_locks_remove(int action, int drone, int resource, int installed, int only_one)
{
    for(int i = 0; i < tool_locks_count; ) {
        ToolLock_t* t = &tool_locks[i];
        if(t->action == action && (resource < 0 || (t->drone == drone && t->resource == resource && t->installed == installed))) {
            tool_locks[i] = tool_locks[--tool_locks_count];
            if(only_one) return;
        } else {
            i++;
        }
    }
}

/* Loads the locks in force from the table: at start, and when the memory and the table disagree */
void db_tool_locks_load(void)
{
    tool_locks_count = 0;
    DbConnection_t* connection = ee_engine_get_db_connection();
    if(mysql_query(connection->hndl, "SELECT drone, resource, installed, units, action FROM tool_locks")) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: loading tool locks failed: %s", mysql_error(connection->hndl));
        return;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    MYSQL_ROW row;
    while(res && (row = mysql_fetch_row(res)))
        tool_locks_add(atoi(row[0]), atoi(row[1]), atoi(row[2]), atoi(row[3]), atoi(row[4]));
    if(res) mysql_free_result(res);
}

/* An aborted action's locks leave the memory too (its rows are deleted with the abort) */
void db_tool_locks_forget_action(int action)
{
    tool_locks_remove(action, 0, -1, 0, 0);
}

/*
 * Would this decrement of an unlocked holding take a tool in use (#814)?
 * Units left after it (new_quantity) below the locks on the holding. No
 * query unless the memory says so; then the table decides.
 */
int db_tool_lock_blocks(int drone, int resource, int installed, int left)
{
    int held = 0;
    for(int i = 0; i < tool_locks_count; i++)
        if(tool_locks[i].drone == drone && tool_locks[i].resource == resource && tool_locks[i].installed == installed)
            held += tool_locks[i].units;
    if(held == 0 || left >= held)
        return 0;
    int actual = db_tool_locked_units(drone, resource, installed);
    if(actual != held)
        db_tool_locks_load();   // an abort in the game engine freed some
    return left < actual;
}

/*
 * Units of a drone's resource held by locks in force (#814): the tools its
 * actions have taken for their time. 0 with none, or on an error.
 */
int db_tool_locked_units(int drone, int resource, int installed)
{
    DbConnection_t* connection = ee_engine_get_db_connection();
    char sql[DB_MAX_SQL_QUERY_LEN+1];
    snprintf(sql, DB_MAX_SQL_QUERY_LEN,
        "SELECT IFNULL(SUM(units), 0) FROM tool_locks WHERE drone = %d AND resource = %d AND installed = %d",
        drone, resource, installed);
    return (int)db_tool_number(connection, sql, 0);
}

/*
 * Settles a lock tool event (#814): the drone's unlocked units of the tool,
 * less its locks in force, must cover the event's units; then a lock row
 * holds them for the action. OUTCOME_OK, or OUTCOME_NO_RESOURCES as a
 * missing tool was before, or 0 on a database error.
 */
int db_tool_lock(Event_t* event)
{
    DbConnection_t* connection = ee_engine_get_db_connection();
    char sql[DB_MAX_SQL_QUERY_LEN+1];
    int units = event->new_quantity > 0 ? event->new_quantity : 1;
    snprintf(sql, DB_MAX_SQL_QUERY_LEN,
        "SELECT IFNULL((SELECT units FROM holdings WHERE drone = %d AND resource = %d AND installed = %d AND locked = 0), 0)",
        event->drone_id, event->resource_id, event->installed);
    long long free = db_tool_number(connection, sql, -1);
    if(free < 0) return 0;
    free -= db_tool_locked_units(event->drone_id, event->resource_id, event->installed);
    if(free < units) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "Lock event [%d]: drone [%d] has [%lld] free of resource [%d], [%d] needed",
            event->event_id, event->drone_id, free, event->resource_id, units);
        return OUTCOME_NO_RESOURCES;
    }
    snprintf(sql, DB_MAX_SQL_QUERY_LEN,
        "INSERT INTO tool_locks (drone, resource, installed, units, action, game_t) VALUES (%d, %d, %d, %d, %d, %lld)",
        event->drone_id, event->resource_id, event->installed, units, event->action_id, (long long)event->game_t);
    if(mysql_query(connection->hndl, sql)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", sql, mysql_error(connection->hndl));
        return 0;
    }
    tool_locks_add(event->drone_id, event->resource_id, event->installed, units, event->action_id);
    return OUTCOME_OK;
}

/* Settles an unlock tool event (#814): the action's lock on the tool goes. ENGINE_OK, or the error */
ErrorCode_t db_tool_unlock(Event_t* event)
{
    DbConnection_t* connection = ee_engine_get_db_connection();
    char sql[DB_MAX_SQL_QUERY_LEN+1];
    snprintf(sql, DB_MAX_SQL_QUERY_LEN,
        "DELETE FROM tool_locks WHERE action = %d AND drone = %d AND resource = %d AND installed = %d LIMIT 1",
        event->action_id, event->drone_id, event->resource_id, event->installed);
    if(mysql_query(connection->hndl, sql)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", sql, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    tool_locks_remove(event->action_id, event->drone_id, event->resource_id, event->installed, 1);
    return ENGINE_OK;
}

ErrorCode_t db_flush_observations(void)
{
    ErrorCode_t result = ENGINE_OK;
    int i = 0;
    while(result == ENGINE_OK && i < pending_observations_count) {
        static char query_text[64 * 1024];
        char* end = query_text;
        char* limit = query_text + sizeof(query_text) - 128;
        end += sprintf(end, "INSERT INTO observations (drone, event, time, game_t) VALUES ");
        int first = i;
        for(; i < pending_observations_count && end < limit; i++) {
            PendingObservation_t* o = &pending_observations[i];
            end += sprintf(end, "%s(%d, %d, '%s', %lld)", i == first ? "" : ",", o->drone, o->event, o->time, o->game_t);
        }
        result = db_run_write(query_text);
    }
    if(result == ENGINE_OK) pending_observations_count = 0;
    return result;
}

ErrorCode_t db_flush_places(void)
{
    ErrorCode_t result = ENGINE_OK;
    int i = 0;
    while(result == ENGINE_OK && i < pending_places_count) {
        static char query_text[64 * 1024];
        char* end = query_text;
        char* limit = query_text + sizeof(query_text) - 64;
        end += sprintf(end, "UPDATE events e JOIN agents a ON a.agent_id = e.drone SET e.place = a.object_id WHERE e.id IN (");
        int first = i;
        for(; i < pending_places_count && end < limit; i++)
            end += sprintf(end, "%s%d", i == first ? "" : ",", pending_places[i]);
        sprintf(end, ")");
        result = db_run_write(query_text);
    }
    if(result == ENGINE_OK) pending_places_count = 0;
    return result;
}

ErrorCode_t db_flush_batch_writes(void)
{
    ErrorCode_t result = db_flush_places();
    if(result == ENGINE_OK) result = db_flush_observations();
    return result;
}

void db_batch_writes_discard(void)
{
    pending_observations_count = 0;
    pending_places_count = 0;
}

BatchWritesMark_t db_batch_writes_mark(void)
{
    // Room for what the savepoint adds, so no flush inside it writes rows
    // from before the mark (its rollback would undo them)
    if(pending_observations_count > BATCH_OBSERVATIONS_MAX / 2) db_flush_observations();
    if(pending_places_count > BATCH_PLACES_MAX / 2) db_flush_places();
    BatchWritesMark_t mark = { pending_observations_count, pending_places_count };
    return mark;
}

void db_batch_writes_undo(BatchWritesMark_t mark)
{
    // Only what was added since the mark. Had a flush written some of it
    // inside the savepoint, the rollback undid it there
    if(mark.observations < pending_observations_count) pending_observations_count = mark.observations;
    if(mark.places < pending_places_count) pending_places_count = mark.places;
}

ErrorCode_t db_insert_observation(Observation_t* observation)
{
    if(!observation)
        return ENGINE_INTERNAL_ERROR;

    if(pending_observations_count >= BATCH_OBSERVATIONS_MAX) {
        ErrorCode_t result = db_flush_observations();
        if(result != ENGINE_OK) return result;
    }

    PendingObservation_t* o = &pending_observations[pending_observations_count++];
    o->drone = observation->drone_id;
    o->event = observation->event_id;
    strftime(o->time, sizeof(o->time), "%F %T", localtime(&observation->timestamp));
    o->game_t = observation->game_t;
    observation->id = 0;   // not known until the batch is written; unused

    return ENGINE_OK;
}

/** ****************************************************************************

    @brief          Deletes all observations for a given event

    @param[in]      Input event ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_event_observations(int event_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "DELETE FROM observations WHERE event = %d",
            event_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "[%d] observations for EVENT_ID [%d] deleted from DB",
                mysql_affected_rows(connection->hndl),
                event_id);
        }
    }

    return result;
}


/** ****************************************************************************

    @brief          Records where an observable event happened, so the drones
                    there see it (no row per drone)

    @param[in]      Input event ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_local_observations(Event_t *event)
{
    // Sanity check
    if(!event)
        return ENGINE_INTERNAL_ERROR;

    // Every drone where the event's drone is sees it (#324 step 20).
    // No row per drone: the event records its place, and a drone sees
    // the events at its current place since it arrived there
    // (agents.arrived_t), worked out when it asks (query 803, the
    // website). A row per drone grew as events x drones. The place is
    // written with the batch's others (#448).
    if(pending_places_count >= BATCH_PLACES_MAX) {
        ErrorCode_t result = db_flush_places();
        if(result != ENGINE_OK) return result;
    }
    pending_places[pending_places_count++] = event->event_id;

    return ENGINE_OK;
}


/** ****************************************************************************

    @brief          Gets whole observation info for a given event (observable, reportable, etc,..)

    @param[in]      event    Input event
    @param[in\out]  protocol Output struct where we store the protocol info required

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_event_observation_info(Event_t *event, ProtocolInfo_t *protocol)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!event || !protocol) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        char* query_end = query_text;

        query_end += snprintf(query_end,
            QUERY_SPACE_LEFT,
            "SELECT observable, reportable from protocols where id = (select protocol from actions where id = %d)",
            event->action_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            // retrieve the result and check that is an only row with expected fields number
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);
            uint64_t rowsNum = mysql_num_rows(db_result);
            unsigned int fieldsNum = mysql_num_fields(db_result);

            if((db_result == NULL) || (rowsNum != 1) || (fieldsNum != 2))
            {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get observation info for EVENT_ID [%d] "
                    "(invalid result for query [%s], rows [%d], fields [%d])",
                    event->event_id,
                    query_text,
                    rowsNum,
                    fieldsNum);

                result = ENGINE_DB_QUERY_ERROR;
            }
            else
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row)
                {
                    // Pick the only field value
                    int value = row[0]?atoi(row[0]):0;
                    protocol->observable = (value > 0)?1:0;
                    value = row[1]?atoi(row[1]):0;
                    protocol->reportable = (value > 0)?1:0;

                    ee_engine_trace(TRACE_LEVEL_ALWAYS,
                        "EVENT_ID [%d] OBSERVABLE [%d] REPORTABLE [%d]",
                        event->event_id,
                        protocol->observable,
                        protocol->reportable);
                }
                else
                {
                    ee_engine_trace(TRACE_LEVEL_ALWAYS,
                        "ERROR: Unable to get observation info for EVENT_ID [%d] (no row)",
                        event->event_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}


/** ****************************************************************************

    @brief          Gets earth orbit info

    @param[in|out]  name Object name we are looking for
    @param[in|out]  Output parameter where we store the orbit info obtained from DB
                    This object contains also current object ID to do the search in DB

    @return         Execution result

*******************************************************************************/
static ErrorCode_t db_get_object_info_by_name_x_query(char* name, ObjectOrbitInfo_t* object)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!object || !name) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "SELECT * FROM objects WHERE object_name = '%s'", name);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            // retrieve the result and check that is an only row with a single field
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);

            if((db_result == NULL) || (mysql_num_rows(db_result) != 1) ||
                (mysql_num_fields(db_result) != MAX_OBJECT_FIELDS))
            {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get [%s] orbit info "
                    "(invalid result for query [%s])",
                    name, query_text);

                result = ENGINE_DB_QUERY_ERROR;
            }
            else
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row)
                {
                    // Get epoch first and return error if fails
                    // otherwise just fill the rest of fields
                    object->epoch = ee_db_date_to_timestamp(row[EPOCH_IDX], OBJECTS_TIMESTAMP_FORMAT);

                    if(!object->epoch) {
                        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to get EPOCH info for [%s]", name);
                        result = ENGINE_INTERNAL_ERROR;

                    } else {
                        // Store object ID
                        object->object_id = row[OBJECT_ID_IDX]?atoi(row[OBJECT_ID_IDX]):-1;

                        sprintf(object->object_name, "%s", row[OBJECT_NAME_IDX]?row[OBJECT_NAME_IDX]:"");
                        sprintf(object->object_type, "%s", row[OBJECT_TYPE_IDX]?row[OBJECT_TYPE_IDX]:"");

                        object->gravitational_parameter = row[GRAVITATIONAL_PARAMETER_IDX]?atof(row[GRAVITATIONAL_PARAMETER_IDX]):-1;
                        object->central_body_object_id = row[CENTRAL_BODY_OBJECT_ID_IDX]?atoi(row[CENTRAL_BODY_OBJECT_ID_IDX]):-1;

                        object->semimajor_axis = row[SEMIMAJOR_AXIS_IDX]?atof(row[SEMIMAJOR_AXIS_IDX]):-1;
                        object->eccentricity = row[ECCENTRICITY_IDX]?atof(row[ECCENTRICITY_IDX]):-1;
                        object->periapsis_argument = row[PERIAPSIS_ARGUMENT_IDX]?atof(row[PERIAPSIS_ARGUMENT_IDX]):-1;
                        object->mean_anomaly = row[MEAN_ANOMALY_IDX]?atof(row[MEAN_ANOMALY_IDX]):-1;
                        object->inclination = row[INCLINATION_IDX]?atof(row[INCLINATION_IDX]):-1;
                        object->ascending_node_longitude = row[ASCENDING_NODE_LONGITUDE_IDX]?atof(row[ASCENDING_NODE_LONGITUDE_IDX]):-1;
                        object->mean_angular_motion = row[MEAN_ANGULAR_MOTION_IDX]?atof(row[MEAN_ANGULAR_MOTION_IDX]):-1;
                    }

                    ee_engine_trace(TRACE_LEVEL_DEBUG,
                        "NAME [%s] TYPE [%s] obtained for OBJECT_ID [%d]",
                        object->object_name, object->object_type, object->object_id);
                }
                else
                {
                    ee_engine_trace(TRACE_LEVEL_ALWAYS,
                        "ERROR: Unable to get orbits info for OBJECT_ID [%d] (no row)",
                        object->object_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Gets orbit info for a given object ID

    @param[in|out]  Output parameter where we store the orbit info obtained from DB
                    This object contains also current object ID to do the search in DB

    @return         Execution result

*******************************************************************************/
static ErrorCode_t db_get_orbit_info_x_query(ObjectOrbitInfo_t* object)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!object) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "SELECT * FROM objects WHERE object_id = %d", object->object_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            // retrieve the result and check that is an only row with a single field
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);

            if((db_result == NULL) || (mysql_num_rows(db_result) != 1) ||
                (mysql_num_fields(db_result) != MAX_OBJECT_FIELDS))
            {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get orbits info for OBJECT_ID [%d] "
                    "(invalid result for query [%s])",
                    object->object_id,
                    query_text);

                result = ENGINE_DB_QUERY_ERROR;
            }
            else
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row)
                {
                    // Get epoch first and return error if fails
                    // otherwise just fill the rest of fields
                    object->epoch = ee_db_date_to_timestamp(row[EPOCH_IDX], OBJECTS_TIMESTAMP_FORMAT);

                    if(!object->epoch) {
                        ee_engine_trace(TRACE_LEVEL_ALWAYS,
                            "ERROR: Unable to get EPOCH info for OBJECT_ID [%d]",
                            object->object_id);

                        result = ENGINE_INTERNAL_ERROR;

                    } else {
                        sprintf(object->object_name, "%s", row[OBJECT_NAME_IDX]?row[OBJECT_NAME_IDX]:"");
                        sprintf(object->object_type, "%s", row[OBJECT_TYPE_IDX]?row[OBJECT_TYPE_IDX]:"");

                        object->gravitational_parameter = row[GRAVITATIONAL_PARAMETER_IDX]?atof(row[GRAVITATIONAL_PARAMETER_IDX]):-1;
                        object->central_body_object_id = row[CENTRAL_BODY_OBJECT_ID_IDX]?atoi(row[CENTRAL_BODY_OBJECT_ID_IDX]):-1;

                        object->semimajor_axis = row[SEMIMAJOR_AXIS_IDX]?atof(row[SEMIMAJOR_AXIS_IDX]):-1;
                        object->eccentricity = row[ECCENTRICITY_IDX]?atof(row[ECCENTRICITY_IDX]):-1;
                        object->periapsis_argument = row[PERIAPSIS_ARGUMENT_IDX]?atof(row[PERIAPSIS_ARGUMENT_IDX]):-1;
                        object->mean_anomaly = row[MEAN_ANOMALY_IDX]?atof(row[MEAN_ANOMALY_IDX]):-1;
                        object->inclination = row[INCLINATION_IDX]?atof(row[INCLINATION_IDX]):-1;
                        object->ascending_node_longitude = row[ASCENDING_NODE_LONGITUDE_IDX]?atof(row[ASCENDING_NODE_LONGITUDE_IDX]):-1;
                        object->mean_angular_motion = row[MEAN_ANGULAR_MOTION_IDX]?atof(row[MEAN_ANGULAR_MOTION_IDX]):-1;
                    }

                    ee_engine_trace(TRACE_LEVEL_DEBUG,
                        "NAME [%s] TYPE [%s] obtained for OBJECT_ID [%d]",
                        object->object_name, object->object_type, object->object_id);
                }
                else
                {
                    ee_engine_trace(TRACE_LEVEL_ALWAYS,
                        "ERROR: Unable to get orbits info for OBJECT_ID [%d] (no row)",
                        object->object_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Deletes a given action

    @param[in]      Input action ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_action(int action_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "DELETE FROM actions WHERE id = %d",
            action_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "ACTION_ID [%d] deleted from DB",
                action_id);
        }
    }

    return result;
}


/** ****************************************************************************

    @brief          Gets current drone cargo per resource

    @param[in]      Current drone ID
    @param[in|out]  Output parameter where we store the rows obtained
    @param[in|out]  Output parameter where we store the number of rows obtained

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_drone_resources(int droneId, DroneResources_t** resources, int* resourcesNum)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection = ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);
    MYSQL_RES* db_result = NULL;
    int fieldsNum = 0;
    uint64_t rowsNum = 0;

    ee_engine_trace(TRACE_LEVEL_ALWAYS, "Getting resources for droneID [%d]", droneId);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!resources || !resourcesNum) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        // reset entries found
        *resourcesNum = 0;

        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            // What the drone holds, from the holdings table (#431): the same
            // rows, each kind's newest increment or depletion, that the
            // history query rebuilt from every event the drone ever had
            "SELECT resources.id AS resource_id, resources.name AS name, IF(h.locked = -1, NULL, h.locked) AS locked, "
            "resources.mass AS mass, h.units AS new_quantity, h.event_id AS event_id "
            "FROM holdings h LEFT JOIN resources ON h.resource = resources.id WHERE h.drone = %d ORDER BY h.units;",
            droneId);

        ee_engine_trace(TRACE_LEVEL_ALWAYS, "Running query [%s]", query_text);

        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if(result == ENGINE_OK)
    {
        // retrieve the results
        db_result = mysql_store_result(connection->hndl);

        if(db_result == NULL)
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get drone resources for DRONE_ID [%d] "
                "(invalid result for query [%s])",
                droneId,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((fieldsNum=mysql_num_fields(db_result)) != MAX_DRONE_RESOURCE_FIELDS)
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Invalid fields number obtained for drone resources for DRONE_ID [%d] "
                "(expected [%d], obtained [%d], query [%s])",
                droneId,
                MAX_DRONE_RESOURCE_FIELDS,
                fieldsNum,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((rowsNum=mysql_num_rows(db_result)) <= 0)
        {
            // None entry found is OK (keep default OK)
            *resourcesNum = 0;
        }
    }

    if((result == ENGINE_OK) && rowsNum)
    {
        // Allocate output effects
        *resources = (DroneResources_t*)malloc(sizeof(DroneResources_t) * ((unsigned int)rowsNum));

        if(!*resources) {
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to allocate [%d] output drone resources for DRONE_ID [%d]",
                rowsNum,
                droneId);

            rowsNum = 0; // reset to avoid for(;;)
        }

        *resourcesNum = rowsNum;

        // iterate results and store them into output buffer
        for(int resourceId=0; resourceId < rowsNum; resourceId++)
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row)
            {
                DroneResources_t *resource = (*resources + resourceId);
                // Pick effect fields
                resource->id = row[DRONE_RESOURCE_ID_IDX]?atoi(row[DRONE_RESOURCE_ID_IDX]):0;
                sprintf(resource->name, "%s", row[DRONE_RESOURCE_NAME_IDX]?row[DRONE_RESOURCE_NAME_IDX]:"");
                resource->locked = row[DRONE_RESOURCE_LOCKED_IDX]?atoi(row[DRONE_RESOURCE_LOCKED_IDX]):0;
                resource->mass = row[DRONE_RESOURCE_MASS_IDX]?atoi(row[DRONE_RESOURCE_MASS_IDX]):0;
                resource->quantity = row[DRONE_RESOURCE_QUANTITY_IDX]?atoi(row[DRONE_RESOURCE_QUANTITY_IDX]):0;
                resource->event_id = row[DRONE_RESOURCE_EVENT_ID_IDX]?atoi(row[DRONE_RESOURCE_EVENT_ID_IDX]):0;

                // | resource_id | name        | locked | mass | new_quantity | event_id |
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "Resource found "
                    "ID [%d] NAME [%s] LOCKED [%d] MASS [%d] QUANTITY [%d] EVENT_ID [%d] "
                    "for DRONE_ID [%d]",
                    resource->id,
                    resource->name,
                    resource->locked,
                    resource->mass,
                    resource->quantity,
                    resource->event_id,
                    droneId);
            }
            else
            {
                // unexpected error - abort
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get row [%d] for query [%s] with [%d] rows",
                    resourceId+1,
                    query_text,
                    rowsNum);

                result = ENGINE_DB_QUERY_ERROR;

                // Deallocate resources
                free(*resources);
                *resources = NULL;
                *resourcesNum = 0;

                break; // stop for
            }
        }
    }

    if(db_result)
        mysql_free_result(db_result);

    return result;
}


/** ****************************************************************************

    @brief          Gets whole user info for a given drone

    @param[in]      Drone ID
    @param[in|out]  User info structure where we store the info obtained

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_drone_user(int drone_id, User_t* user)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!user) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        char* query_end = query_text;

        query_end += snprintf(query_end,
            QUERY_SPACE_LEFT,
            "SELECT c.company_id, c.user_id, c.credits FROM companies c "
            "JOIN agents a ON a.company_id = c.company_id WHERE a.agent_id = %d;",
            drone_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            // retrieve the result and check that is an only row with expected fields number
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);
            uint64_t rowsNum = mysql_num_rows(db_result);
            unsigned int fieldsNum = mysql_num_fields(db_result);

            if((db_result == NULL) || (rowsNum != 1) || (fieldsNum != 3))
            {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get user info for DRONE_ID [%d] "
                    "(invalid result for query [%s], rows [%d], fields [%d])",
                    drone_id,
                    query_text,
                    rowsNum,
                    fieldsNum);

                result = ENGINE_DB_QUERY_ERROR;
            }
            else
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row)
                {
                    // The drone's company: its ID, its player and its bank account
                    user->user_id = row[0]?atoi(row[0]):0;
                    user->player_id = row[1]?atoi(row[1]):0;
                    user->credits = row[2]?atoi(row[2]):0;

                    ee_engine_trace(TRACE_LEVEL_ALWAYS,
                        "DRONE_ID [%d] COMPANY_ID [%d] PLAYER_ID [%d] CREDITS [%d]",
                        drone_id,
                        user->user_id,
                        user->player_id,
                        user->credits);
                }
                else
                {
                    ee_engine_trace(TRACE_LEVEL_ALWAYS,
                        "ERROR: Unable to get user info for drone [%d] (no row)",
                        drone_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}


/** ****************************************************************************

    @brief          Gets resource quantity present at drone bay

    @param[in]      Current drone ID
    @param[in]      Current resource ID
    @param[in|out]  Output parameter where we store the quantity obtained when success

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_drone_resource(int droneId, int resource_id, int* quantity)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection = ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);
    MYSQL_RES* db_result = NULL;
    int fieldsNum = 0;
    uint64_t rowsNum = 0;

    ee_engine_trace(TRACE_LEVEL_ALWAYS, "Getting quantity for resource ID [%d] and droneID [%d]", resource_id, droneId);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!quantity) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        // reset out value
        *quantity = 0;

        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "select new_quantity from events where drone = %d and resource = %d and (event_type = %d or event_type = %d) and outcome = 1 "
            "order by game_t DESC, id DESC limit 1;",
            droneId,
            resource_id,
            INCREMENT_INVENTORY_EVENT_TYPE,
            DECREMENT_INVENTORY_EVENT_TYPE);

        ee_engine_trace(TRACE_LEVEL_ALWAYS, "Running query [%s]", query_text);

        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if(result == ENGINE_OK)
    {
        // retrieve the results
        db_result = mysql_store_result(connection->hndl);

        if(db_result == NULL)
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get quantity for DRONE_ID [%d] RESOURCE_ID [%d] "
                "(invalid result for query [%s])",
                droneId,
                resource_id,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((fieldsNum=mysql_num_fields(db_result)) != 1)
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Invalid fields number obtained for drone DRONE_ID [%d] RESOURCE_ID [%d] "
                "(expected [1], obtained [%d], query [%s])",
                droneId,
                resource_id,
                fieldsNum,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((rowsNum=mysql_num_rows(db_result)) <= 0)
        {
            // None entry found is OK (keep default OK)
            *quantity = 0;
        }
    }

    if((result == ENGINE_OK) && rowsNum)
    {
        MYSQL_ROW row = mysql_fetch_row(db_result);
        if(row)
        {
            *quantity = row[0]?atoi(row[0]):0;

            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "DRONE [%d] RESOURCE_ID [%d] QUANTITY [%d]",
                droneId,
                resource_id,
                *quantity);
        }
        else
        {
            // unexpected error - abort
            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get row for query [%s] with [%d] rows",
                query_text,
                rowsNum);

            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if(db_result)
        mysql_free_result(db_result);

    return result;
}


/** ****************************************************************************

    @brief          Adds `delta` to a company's credits and reads back its
                    new total into user->credits (#756)

    The events engine used to write the total it had worked out from the
    credits it read when the trade began (credits = N). A change made in
    between by the website (maturing repays the debt, a push fee, the
    bank's payments) was overwritten: round 72's Bram 72-1 matured during
    a sale and kept its 4,200 debt. Now it adds the change, in the row's
    own lock, and reads back what the row holds.

    @param[in|out]  user  Company; credits is set to the new total
    @param[in]      delta Credits to add (negative to take)

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_add_user_credits(User_t* user, long long delta)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = ee_engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);

    if(result != ENGINE_OK) return result;
    if(!user) return ENGINE_INTERNAL_ERROR;

    snprintf(query_text, sizeof(query_text),
        "UPDATE companies SET credits = credits + (%lld) WHERE company_id = %d", delta, user->user_id);
    if(mysql_query(connection->hndl, query_text)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        return ENGINE_DB_QUERY_ERROR;
    }

    snprintf(query_text, sizeof(query_text), "SELECT credits FROM companies WHERE company_id = %d", user->user_id);
    if(mysql_query(connection->hndl, query_text)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        return ENGINE_DB_QUERY_ERROR;
    }
    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    if(db_result) {
        MYSQL_ROW row = mysql_fetch_row(db_result);
        if(row && row[0]) user->credits = atoi(row[0]);
        mysql_free_result(db_result);
    }
    ee_engine_trace(TRACE_LEVEL_ALWAYS, "User [%d] credits changed by [%lld] to [%d]", user->user_id, delta, user->credits);
    return ENGINE_OK;
}


/** ****************************************************************************

    @brief          Inserts a new event entry (from events_engine  _e)

    @param[in|out]  Event info to be inserted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_event_e(Event_t* event)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    char new_quantity_text[MAX_QUERY_VALUE_BUF_LEN];
    char new_credits_text[MAX_QUERY_VALUE_BUF_LEN];
    char new_location_text[MAX_QUERY_VALUE_BUF_LEN];
    char new_transmission_text[MAX_QUERY_VALUE_BUF_LEN];
    char new_cargo_text[MAX_QUERY_VALUE_BUF_LEN];

    DbConnection_t* connection =  ee_engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        // Sanity check
        if(!event)
            return ENGINE_INTERNAL_ERROR;
    }

    // When it happens, in game time: the heap needs the same value (EVENT_HEAP)
    long long game_t = game_time_now_seconds() + event->delay;

    char* query_end = query_text;

    query_end += snprintf(query_end,
        QUERY_SPACE_LEFT,
        "INSERT INTO events "
        "(timestamp, game_t, event_type, action, logged, outcome, drone, resource, installed, locked %s%s%s%s%s) " // Only set fields when value != 0
        "VALUES(CAST(DATE_ADD(NOW(), INTERVAL %d SECOND) AS DATETIME), %lld, %d, %d, %d, %d, %d, %d, %d, %d, %s%s%s%s%s%s%s%s%s%s)", // Only set values != 0
        event->new_quantity!=NULL_VALUE?", new_quantity":"",
        event->new_credits!=NULL_VALUE?", new_credits":"",
        event->new_location!=NULL_VALUE?", new_location":"",
        event->new_transmission!=NULL_VALUE?", new_transmission":"",
        event->new_cargo!=NULL_VALUE?", new_cargo":"",
        game_time_to_real(event->delay), // delay is game seconds; real time for the website
        game_t, // when it happens, in game time
        event->event_type,
        event->action_id,
        event->logged,
        event->outcome,
        event->drone_id,
        event->resource_id,
        event->installed,
        event->locked,
        event->new_quantity!=NULL_VALUE?ee_db_int2str(event->new_quantity, new_quantity_text, MAX_QUERY_VALUE_BUF_LEN):"",
        event->new_quantity!=NULL_VALUE?", ":"",
        event->new_credits!=NULL_VALUE?ee_db_int2str(event->new_credits, new_credits_text, MAX_QUERY_VALUE_BUF_LEN):"",
        event->new_credits!=NULL_VALUE?", ":"",
        event->new_location!=NULL_VALUE?ee_db_int2str(event->new_location, new_location_text, MAX_QUERY_VALUE_BUF_LEN):"",
        event->new_location!=NULL_VALUE?", ":"",
        event->new_transmission!=NULL_VALUE?ee_db_int2str(event->new_transmission, new_transmission_text, MAX_QUERY_VALUE_BUF_LEN):"",
        event->new_transmission!=NULL_VALUE?", ":"",
        event->new_cargo!=NULL_VALUE?ee_db_int2str(event->new_cargo, new_cargo_text, MAX_QUERY_VALUE_BUF_LEN):"",
        event->new_cargo!=NULL_VALUE?", ":"");

    // Trim last comma if any
    char* lastComma = strrchr(query_text, ',');
    if(lastComma) {
        char* nextChar = lastComma;
        nextChar += 2;
        if(*nextChar == ')') *lastComma = ' ';
    }

    // run it
    if (mysql_query(connection->hndl, query_text))
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }
    else
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "Query [%s] executed", query_text);

        // Get auto-increment ID
        event->event_id = mysql_insert_id(connection->hndl);

        // EVENT_HEAP (#694 stage 4 step 3): a pending event goes into the
        // heap when this connection commits
        if(event->outcome == 0)
            event_heap_stage(connection, event->event_id, game_t, event->action_id);

        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "Event [%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d] inserted into DB",
            event->event_id,
            event->event_type,
            event->action_id,
            event->logged,
            event->outcome,
            event->drone_id,
            event->resource_id,
            event->installed,
            event->locked,
            event->new_quantity,
            event->new_credits,
            event->new_location,
            event->new_transmission,
            event->new_cargo,
            event->timestamp);
    }

    return result;
}

/** ****************************************************************************

    @brief          Gets an event's current outcome (OUTCOME_NO_OUTCOME while pending)

    @param[in]      event_id Event ID
    @param[out]     outcome  Current outcome

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_event_outcome(int event_id, int* outcome)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = ee_engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);

    if((result == ENGINE_OK) && outcome)
    {
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN, "SELECT outcome FROM events WHERE id = %d", event_id);

        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);
            MYSQL_ROW row = db_result ? mysql_fetch_row(db_result) : NULL;

            if(row && row[0])
                *outcome = atoi(row[0]);
            else
                result = ENGINE_DB_NOT_FOUND_ERROR;

            if(db_result) mysql_free_result(db_result);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Gets a pending event of an action, other than a given one

    @param[in]      action_id   Action ID
    @param[in]      event_type  Event type wanted
    @param[in]      exclude_id  Event ID to leave out
    @param[out]     out_event   Event found

    @return         ENGINE_OK when found, ENGINE_DB_NOT_FOUND_ERROR when none

*******************************************************************************/
ErrorCode_t db_get_pending_action_event(int action_id, int event_type, int exclude_id, Event_t* out_event)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = ee_engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);

    if((result == ENGINE_OK) && out_event)
    {
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
            "SELECT * FROM events WHERE action = %d AND event_type = %d AND id <> %d AND outcome = %d "
            "ORDER BY game_t ASC, id ASC LIMIT 1",
            action_id, event_type, exclude_id, OUTCOME_NO_OUTCOME);

        if (mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);
            MYSQL_ROW row = db_result ? mysql_fetch_row(db_result) : NULL;

            if(row)
                db_row_to_event(row, out_event);
            else
                result = ENGINE_DB_NOT_FOUND_ERROR;

            if(db_result) mysql_free_result(db_result);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Runs a savepoint statement ("SAVEPOINT x", "ROLLBACK TO SAVEPOINT x",
                    "RELEASE SAVEPOINT x") inside the current transaction

    @param[in]      statement SQL statement

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_savepoint(const char* statement)
{
    DbConnection_t* connection = ee_engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);

    if((result == ENGINE_OK) && mysql_query(connection->hndl, statement))
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", statement);
        result = ENGINE_DB_QUERY_ERROR;
    }

    return result;
}

/** Runs a statement, tracing a failure */
static ErrorCode_t db_launch_exec(DbConnection_t* connection, const char* query_text)
{
    if(mysql_query(connection->hndl, query_text))
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    return ENGINE_OK;
}

/** ****************************************************************************

    @brief          Creates a launched drone: owned by the launching drone's
                    player, at its location, with the kit's hull and a copy of
                    drone source_id's program (VM). Queues the <launched>
                    command so the new drone starts at once.

    @param[in]      launcher_id  Drone that performed the launch
    @param[in]      source_id    Drone whose program is copied
    @param[in]      hull_id      The ship kit's ID (its hull has the same ID)
    @param[out]     new_id       The new drone's ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_launch_drone(int launcher_id, int source_id, int hull_id, int* new_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    char name[32] = "";
    DbConnection_t* connection = ee_engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);
    static int seeded = 0;

    if(!seeded) { srand((unsigned)time(NULL) ^ (unsigned)getpid()); seeded = 1; }

    // A free name like the website gives new players' drones: SN<number>
    for(int tries = 0; result == ENGINE_OK && tries < 50 && !name[0]; tries++)
    {
        char candidate[32];
        snprintf(candidate, sizeof(candidate), "SN%d", rand() % 1000000);
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN, "SELECT COUNT(*) FROM agents WHERE name = '%s'", candidate);
        result = db_launch_exec(connection, query_text);
        if(result != ENGINE_OK) break;

        MYSQL_RES* db_result = mysql_store_result(connection->hndl);
        MYSQL_ROW row = db_result ? mysql_fetch_row(db_result) : NULL;
        if(row && row[0] && atoi(row[0]) == 0)
            snprintf(name, sizeof(name), "%s", candidate);
        if(db_result) mysql_free_result(db_result);
    }

    if(result == ENGINE_OK && !name[0])
        result = ENGINE_LOGIC_ERROR;

    // The copy is taken now, when the launch happens. Drone D must still
    // belong to the company; if it doesn't, the new drone gets a fresh VM.
    // The new drone joins the launcher's company (and player), beside it.
    // Its lineage (#324 step 13): the launcher is its parent, one generation
    // on, and code_from is the drone whose program it got.
    if(result == ENGINE_OK)
    {
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
            "INSERT INTO agents (user_id, company_id, name, vm, object_id, hull_type, parent_id, generation, code_from, "
            "language, lua_script, lua_state) "
            "SELECT l.user_id, l.company_id, '%s', "
            "(SELECT d.vm FROM agents d WHERE d.agent_id = %d AND d.company_id = l.company_id), "
            "l.object_id, %d, l.agent_id, l.generation + 1, "
            "(SELECT d.agent_id FROM agents d WHERE d.agent_id = %d AND d.company_id = l.company_id "
            "AND (d.vm IS NOT NULL OR d.lua_script IS NOT NULL)), "
            /* A Lua drone's copy gets its script and saved variables (#380) */
            "IFNULL((SELECT d.language FROM agents d WHERE d.agent_id = %d AND d.company_id = l.company_id), 'forth'), "
            "(SELECT d.lua_script FROM agents d WHERE d.agent_id = %d AND d.company_id = l.company_id), "
            "(SELECT d.lua_state FROM agents d WHERE d.agent_id = %d AND d.company_id = l.company_id) "
            "FROM agents l WHERE l.agent_id = %d",
            name, source_id, hull_id, source_id, source_id, source_id, source_id, launcher_id);
        result = db_launch_exec(connection, query_text);
    }

    if(result == ENGINE_OK)
    {
        *new_id = (int)mysql_insert_id(connection->hndl);
        if(*new_id <= 0) result = ENGINE_LOGIC_ERROR;
    }

    // Already on board: no light delay before it runs
    if(result == ENGINE_OK)
    {
        long long now = game_time_now_seconds();
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
            "INSERT INTO commands (code, agent_id, created_at, updated_at, subject, email_content, deliver_at) "
            "VALUES ('%s', %d, NOW(), NOW(), 'Launched', '', %lld)",
            META_COMMAND_LAUNCHED, *new_id, now);
        result = db_launch_exec(connection, query_text);
        // WAKE_HEAP (#694 stage 4 step 4): into memory when settlement
        // commits, if the new drone is this shard's (off in bin/events-engine)
        if(result == ENGINE_OK)
            wake_heap_stage_put(connection, (int)mysql_insert_id(connection->hndl), *new_id, now, WAKE_UNKNOWN_DRONE);
    }

    if(result == ENGINE_OK)
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "Drone [%d] launched drone [%d] [%s] (hull [%d], program of drone [%d])",
            launcher_id, *new_id, name, hull_id, source_id);

    return result;
}

/** ****************************************************************************

    @brief          Records a trade between two players (#249)

    @param[in]      resource  Resource traded
    @param[in]      units     Units that changed hands
    @param[in]      credits   Credits paid for them (by the buyer)
    @param[in]      tax       Sales tax the seller paid out of them (#380)
    @param[in]      seller    Drone that gave the goods
    @param[in]      buyer     Drone that received them
    @param[in]      location  Where it happened (object ID, -1 if unknown)

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_trade(int resource, int units, int credits, int seller, int buyer, int location, int tax)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = ee_engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);

    if(result != ENGINE_OK)
        return result;

    if(location >= 0)
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
            "INSERT INTO trades (resource, units, credits, seller, buyer, location, game_t, tax) VALUES (%d, %d, %d, %d, %d, %d, %lld, %d)",
            resource, units, credits, seller, buyer, location, game_time_now_seconds(), tax);
    else
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
            "INSERT INTO trades (resource, units, credits, seller, buyer, game_t, tax) VALUES (%d, %d, %d, %d, %d, %lld, %d)",
            resource, units, credits, seller, buyer, game_time_now_seconds(), tax);

    return db_launch_exec(connection, query_text);
}

/** ****************************************************************************

    @brief          Gives back to their deposits what a failed mining action
                    took (#324 step 11)

    Mining takes its units from the place's deposit when it is scheduled. When
    the action fails, its mining yields (increments of a mined resource that
    were not applied) go back, and are zeroed so they are given back only once.

    @param[in]      action_id Failed action

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_refund_deposits(int action_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = ee_engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);
    if(result != ENGINE_OK)
        return result;

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT e.id, e.resource, e.new_quantity, g.object_id FROM events e "
        "JOIN actions a ON a.id = e.action "
        "JOIN agents g ON g.agent_id = e.drone "
        "JOIN resource_effects r ON r.protocol = a.protocol AND r.resource = e.resource "
        "AND r.event_type = 1 AND r.abundancies = 1 "
        "WHERE e.action = %d AND e.event_type = 1 AND e.new_quantity > 0 AND e.outcome <> %d",
        action_id, OUTCOME_OK);
    if(mysql_query(connection->hndl, query_text)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    if(!res)
        return ENGINE_OK;

    int count = 0;
    int ids[16], resources[16], quantities[16], objects[16];
    MYSQL_ROW row;
    while(count < 16 && (row = mysql_fetch_row(res))) {
        ids[count] = atoi(row[0]);
        resources[count] = atoi(row[1]);
        quantities[count] = atoi(row[2]);
        objects[count] = row[3] ? atoi(row[3]) : 0;
        count++;
    }
    mysql_free_result(res);

    for(int i = 0; i < count; i++) {
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
            "UPDATE deposit_state s JOIN deposits d ON d.object_id = s.object_id AND d.resource = s.resource "
            "SET s.amount = LEAST(d.capacity, s.amount + %d) WHERE s.object_id = %d AND s.resource = %d",
            quantities[i], objects[i], resources[i]);
        if(mysql_query(connection->hndl, query_text)) {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
            return ENGINE_DB_QUERY_ERROR;
        }
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
            "UPDATE events SET new_quantity = 0, new_cargo = 0 WHERE id = %d", ids[i]);
        if(mysql_query(connection->hndl, query_text)) {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
            return ENGINE_DB_QUERY_ERROR;
        }
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "Deposit of resource [%d] at [%d]: [%d] given back by failed action [%d]",
            resources[i], objects[i], quantities[i], action_id);
    }
    return ENGINE_OK;
}

/** ****************************************************************************

    @brief          Gives back the fuel a failed push or pull burned
                    (#380 round 38)

    A pull from a sell order that another drone emptied first (or a push into
    a buy order that filled first) fails, but its fuel was already burned: a
    drone in a crowd paid a fuel for every race it lost. The burn (the
    action's fuel decrement that was applied) is given back as a new fuel
    increment, so the drone's fuel is as it was.

    @param[in]      action_id Failed push or pull

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_refund_fuel(int action_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = ee_engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);
    if(result != ENGINE_OK)
        return result;

    // The burn's place may be waiting to be written with the batch (#448)
    result = db_flush_places();
    if(result != ENGINE_OK)
        return result;

    // The applied burn: its drone, time and place, and the protocol's cost
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT e.drone, e.game_t, IFNULL(e.place, 0), r.quantity FROM events e "
        "JOIN actions a ON a.id = e.action "
        "JOIN resource_effects r ON r.protocol = a.protocol AND r.resource = %d AND r.event_type = 2 AND r.deplete = 1 "
        "WHERE e.action = %d AND e.resource = %d AND e.event_type = 2 AND e.outcome = %d "
        "AND e.installed = 0 AND e.locked = 0 AND a.protocol IN (%d, %d) ORDER BY e.id LIMIT 1",
        FUEL_RESOURCE_ID, action_id, FUEL_RESOURCE_ID, OUTCOME_OK, PUSH_PROTOCOL_ID, PULL_PROTOCOL_ID);
    if(mysql_query(connection->hndl, query_text)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    if(!res)
        return ENGINE_OK;
    MYSQL_ROW row = mysql_fetch_row(res);
    if(!row) {
        mysql_free_result(res);
        return ENGINE_OK;   // nothing burned (it had no fuel, or no cost)
    }
    int drone = atoi(row[0]);
    long long game_t = row[1] ? atoll(row[1]) : 0;
    int place = atoi(row[2]);
    int cost = atoi(row[3]);
    mysql_free_result(res);
    if(cost <= 0)
        return ENGINE_OK;

    // The fuel comes back on top of what the drone holds now. Read first,
    // then inserted (#439): inside INSERT ... SELECT these reads were locking
    // reads, and the cargo one walks events back from the newest, so it held
    // a shared lock on the end of the table until the batch committed, and
    // every new event the game engine wrote waited for it
    //
    // Only inventory events (increments and depletions, types 1 and 2) say
    // what a drone holds (#494). A wait or a share request also names fuel,
    // with 0 and a cargo of 0: read after one, the refund set the tank to the
    // refund alone (86 fuel became 1), and the next power bill wrecked the
    // drone for fuel. The fuel comes from the holdings table (#430), the
    // cargo from the newest inventory event
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT IFNULL((SELECT h.units FROM holdings h WHERE h.drone = %d AND h.resource = %d AND h.installed = 0 "
        "AND h.locked = 0), 0), "
        "IFNULL((SELECT c.new_cargo FROM events c WHERE c.drone = %d AND c.outcome = %d AND c.event_type IN (1, 2) "
        "ORDER BY c.id DESC LIMIT 1), 0)",
        drone, FUEL_RESOURCE_ID, drone, OUTCOME_OK);
    if(mysql_query(connection->hndl, query_text)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    long long fuel = 0, cargo = 0;
    res = mysql_store_result(connection->hndl);
    if(res) {
        row = mysql_fetch_row(res);
        if(row) {
            fuel = row[0] ? atoll(row[0]) : 0;
            cargo = row[1] ? atoll(row[1]) : 0;
        }
        mysql_free_result(res);
    }
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "INSERT INTO events (event_type, action, logged, outcome, drone, resource, installed, locked, "
        "new_quantity, new_credits, new_cargo, game_t, place) "
        "VALUES (1, 0, 1, %d, %d, %d, 0, 0, %lld, 0, %lld, %lld, %d)",
        OUTCOME_OK, drone, FUEL_RESOURCE_ID, fuel + cost, cargo + cost, game_t, place);
    if(mysql_query(connection->hndl, query_text)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    ee_engine_trace(TRACE_LEVEL_ALWAYS, "Drone [%d]: [%d] fuel given back by failed transfer action [%d]",
        drone, cost, action_id);
    return ENGINE_OK;
}

/** ****************************************************************************

    @brief          Gets a protocol's observable and reportable flags, from a
                    cache good for a minute (#446): it was a query per event

    @param[in]      protocol_id  The protocol
    @param[in\out]  protocol     Output struct where we store the flags

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_protocol_flags(int protocol_id, ProtocolInfo_t *protocol)
{
    static StaticCache_t cache = { .ttl = 60 };
    int flags[2];
    time_t now = time(NULL);

    if(!protocol) return ENGINE_INTERNAL_ERROR;
    memset(protocol, 0, sizeof(*protocol));

    if(!static_cache_get(&cache, protocol_id, flags, sizeof(flags), now))
    {
        char query_text[DB_MAX_SQL_QUERY_LEN+1];
        DbConnection_t* connection = ee_engine_get_db_connection();
        ErrorCode_t result = db_reconnect(connection);
        if(result != ENGINE_OK) return result;

        snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
            "SELECT observable, reportable FROM protocols WHERE id = %d", protocol_id);
        if(mysql_query(connection->hndl, query_text))
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            return ENGINE_DB_QUERY_ERROR;
        }

        MYSQL_RES* db_result = mysql_store_result(connection->hndl);
        MYSQL_ROW row = db_result ? mysql_fetch_row(db_result) : NULL;
        if(!row || mysql_num_fields(db_result) != 2)
        {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: No protocol [%d] for its flags", protocol_id);
            if(db_result) mysql_free_result(db_result);
            return ENGINE_DB_QUERY_ERROR;
        }
        flags[0] = (row[0] && atoi(row[0]) > 0) ? 1 : 0;
        flags[1] = (row[1] && atoi(row[1]) > 0) ? 1 : 0;
        mysql_free_result(db_result);
        static_cache_put(&cache, protocol_id, flags, sizeof(flags), now);
    }

    protocol->observable = flags[0];
    protocol->reportable = flags[1];
    return ENGINE_OK;
}

/* Objects (bodies and their orbits) don't change while the engines run: from
   a cache good for a minute (#446). Each reportable event asked for the Sun,
   the Earth and every body up to the Sun. */
static StaticCache_t objects_by_id_cache = { .ttl = 60 };
static StaticCache_t objects_by_name_cache = { .ttl = 60 };

static int object_name_key(const char* name)
{
    unsigned int h = 2166136261u;
    for(; *name; name++) h = (h ^ (unsigned char)*name) * 16777619u;
    return (int)(h & 0x7fffffff);
}

ErrorCode_t db_get_object_info_by_name_x(char* name, ObjectOrbitInfo_t* object)
{
    time_t now = time(NULL);
    if(!object || !name) return ENGINE_INTERNAL_ERROR;
    int key = object_name_key(name);
    ObjectOrbitInfo_t cached;
    if(static_cache_get(&objects_by_name_cache, key, &cached, sizeof(cached), now) &&
       strncmp(cached.object_name, name, sizeof(cached.object_name)) == 0) {
        *object = cached;
        return ENGINE_OK;
    }
    ErrorCode_t result = db_get_object_info_by_name_x_query(name, object);
    if(result == ENGINE_OK)
        static_cache_put(&objects_by_name_cache, key, object, sizeof(*object), now);
    return result;
}

ErrorCode_t db_get_orbit_info_x(ObjectOrbitInfo_t* object)
{
    time_t now = time(NULL);
    if(!object) return ENGINE_INTERNAL_ERROR;
    int id = object->object_id;
    if(static_cache_get(&objects_by_id_cache, id, object, sizeof(*object), now))
        return ENGINE_OK;
    ErrorCode_t result = db_get_orbit_info_x_query(object);
    if(result == ENGINE_OK && object->object_id == id)
        static_cache_put(&objects_by_id_cache, id, object, sizeof(*object), now);
    return result;
}

/** ****************************************************************************

    Old events move out of events (#694 stage 3)

    A load world slowed from 6,780 to 3,804 turns a minute as events grew
    from 316,000 to 675,000 rows, with no code change: the engines' lookups
    get slower as the history grows. The events engine moves settled events
    older than house_rules.events_archive_days game days (30; 0: never) to
    events_archive (archive_events, migration 0072, which says what it
    never moves). A pass starts at most once a real minute (and purges
    month-old actions, #431) and goes through the table a window of ids at a
    time, from the oldest to the cutoff. Each window is its own short
    transaction, between batches on the events engine's own connection, so
    it never overlaps a settlement, under READ COMMITTED, so its reads of
    events take no locks while the game engine inserts. Windows take at most
    a tenth of the engine's time (a window of 2,000 events took about 0.3 s
    on a loaded 4-core machine, most of it deleting from events' indexes),
    so a first pass over weeks of history runs for hours beside the game
    rather than stopping it.

*******************************************************************************/
#define ARCHIVE_EVERY_SECONDS 60
#define ARCHIVE_WINDOW_IDS 2000
#define ARCHIVE_SHARE 10          /* at most 1/10 of the time */
#define ARCHIVE_SHARE_BEHIND 4    /* 1/4 while windows come back full: a backlog (#818) */
#define ARCHIVE_LOG_SECONDS 60
#define ARCHIVE_DEFAULT_DAYS 30

/* A number from a one-value query, or dflt */
static long long db_archive_number(DbConnection_t* connection, const char* sql, long long dflt)
{
    long long value = dflt;
    if(mysql_query(connection->hndl, sql)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", sql, mysql_error(connection->hndl));
        return dflt;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    if(row && row[0])
        value = atoll(row[0]);
    if(res)
        mysql_free_result(res);
    return value;
}

/* Runs a statement, reading and dropping whatever results it leaves (a CALL
   leaves a status result after its own) */
static ErrorCode_t db_archive_exec(DbConnection_t* connection, const char* sql)
{
    if(mysql_query(connection->hndl, sql)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", sql, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    int status = 0;
    do {
        MYSQL_RES* res = mysql_store_result(connection->hndl);
        if(res)
            mysql_free_result(res);
        status = mysql_next_result(connection->hndl);
    } while(status == 0);
    if(status > 0) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", sql, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    return ENGINE_OK;
}

static long long db_archive_ms(void)
{
    struct timeval tv;
    gettimeofday(&tv, NULL);
    return (long long)tv.tv_sec * 1000 + tv.tv_usec / 1000;
}

ErrorCode_t db_archive_due(void)
{
    // A pass: where its next window starts (0: the oldest event), its age
    // and how long it has taken. After a restart a pass starts again from
    // the oldest
    static int active = 0;
    static unsigned long cursor = 0;
    static long long days = 0, started = 0, spent = 0, logged = 0, moved = 0;
    static int windows = 0, behind = 0;
    static time_t next_pass = 0;

    long long now = db_archive_ms();
    if(!active) {
        if(time(NULL) < next_pass)
            return ENGINE_OK;
        next_pass = time(NULL) + ARCHIVE_EVERY_SECONDS;
        db_purge_old_actions();
        DbConnection_t* connection = ee_engine_get_db_connection();
        if(db_reconnect(connection) != ENGINE_OK)
            return ENGINE_DB_QUERY_ERROR;
        days = db_archive_number(connection,
            "SELECT IFNULL((SELECT events_archive_days FROM house_rules WHERE id = 1), 30)", ARCHIVE_DEFAULT_DAYS);
        if(days <= 0)
            return ENGINE_OK;
        // Settled lock and unlock events (#814) aren't history: they go,
        // rather than moving to the archive, once as old as what moves
        char drop[DB_MAX_SQL_QUERY_LEN+1];
        snprintf(drop, DB_MAX_SQL_QUERY_LEN,
            "DELETE FROM events WHERE event_type IN (%d, %d) AND outcome <> 0 AND game_t < "
            "(SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale) FROM game_clock WHERE id = 1) - %lld "
            "LIMIT 50000", LOCK_TOOL_EVENT_TYPE, UNLOCK_TOOL_EVENT_TYPE, days * 86400);
        if(mysql_query(connection->hndl, drop))
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", drop, mysql_error(connection->hndl));
        active = 1;
        started = logged = now;
        spent = moved = 0;
        windows = 0;
        // Said once a pass, so a stall shows in the log (#818: none for hours)
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "Archive pass from event [%lu], events older than [%lld] game days%s",
                     cursor, days, behind ? ", catching up" : "");
    }

    // Its share of the time: more while a backlog fills every window (#818)
    if(spent * (behind ? ARCHIVE_SHARE_BEHIND : ARCHIVE_SHARE) > now - started)
        return ENGINE_OK;

    DbConnection_t* connection = ee_engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN, "CALL archive_events(%lu, %d, %lld, @archive_moved, @archive_next)",
             cursor, ARCHIVE_WINDOW_IDS, days * 86400);
    // Its own transaction (SET TRANSACTION is for the next one only)
    if(result == ENGINE_OK)
        result = db_archive_exec(connection, "SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
    if(result == ENGINE_OK)
        result = ee_db_start_transaction(connection);
    if(result == ENGINE_OK)
        result = db_archive_exec(connection, query_text);
    if(result == ENGINE_OK)
        result = ee_db_commit_transaction(connection);
    if(result != ENGINE_OK) {
        ee_db_rollback_transaction(connection);
        db_archive_exec(connection, "SET @events_archiving = 0");
        active = 0;     // try again next minute, from where it got to
        return result;
    }
    long long window_moved = db_archive_number(connection, "SELECT IFNULL(@archive_moved, 0)", 0);
    moved += window_moved;
    // Behind while a window moves most of what it reads
    behind = window_moved * 2 >= ARCHIVE_WINDOW_IDS;
    cursor = (unsigned long)db_archive_number(connection, "SELECT IFNULL(@archive_next, 0)", 0);
    windows++;
    long long end = db_archive_ms();
    spent += end - now;

    // Caught up (the next pass starts again from the oldest: what was kept as
    // the latest of its kind may move by then), or a long pass's progress. A
    // NOTICE, so a quiet log (live's) shows the first passes catching up
    if(cursor == 0 || end - logged >= ARCHIVE_LOG_SECONDS * 1000) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "%sArchived [%lld] events older than [%lld] game days in [%d] windows, "
                     "[%lld] of [%lld] ms, %s",
                     moved > 0 ? "NOTICE: " : "", moved, days, windows, spent, end - started,
                     cursor == 0 ? "caught up" : "going on");
        logged = end;
        if(cursor == 0)
            active = 0;
        else
            moved = windows = 0, spent = 0, started = end;
    }
    return ENGINE_OK;
}
