/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: DB interface module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#define _XOPEN_SOURCE
#include <stdio.h>
#include <stdlib.h>
#include <time.h>
#include <string.h>

#include "db.h"
#include "game_time.h"
#include "trace.h"
#include "engine.h"
#include "vm.h"
#include "event_ids.h"

/******************************* DEFINES *************************************/


/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief          Checks connection status and reconnects when necessary

  @param[in|out]  Connection info

  @return         void

*******************************************************************************/
/* How often db_reconnect checks the connection is alive */
#define DB_CHECK_SECONDS 5

ErrorCode_t db_reconnect(DbConnection_t* connection)
{
	ErrorCode_t result = ENGINE_OK;

	if(connection && connection->hndl) {
		// Check connection status & reconnect if required. Not before every
		// query (#324 step 20): the check is a round trip to the server, and
		// a busy engine makes dozens of queries a slice. Once every
		// DB_CHECK_SECONDS is enough to find a lost connection.
		time_t now = time(NULL);
		if(connection->checked_at && now - connection->checked_at < DB_CHECK_SECONDS)
			return ENGINE_OK;

		// mysql_ping, not mysql_stat (#703): on a lost connection
		// mysql_stat returns the error text, not NULL, so the engine never
		// reconnected and failed every query until it was restarted
		if(mysql_ping(connection->hndl) == 0)
		{
			connection->checked_at = now;
		}
		else
		{
			engine_trace(TRACE_LEVEL_ALWAYS,
        		"WARNING: Connection lost with [%s] database (%s), reconnecting...",
        		connection->db_name?connection->db_name:"NULL", mysql_error(connection->hndl));

			MYSQL* old = connection->hndl;
			result = db_init(connection);
			if(result == ENGINE_OK && connection->hndl != old)
				mysql_close(old);   // the dead one
			// A new connection holds no lock: take it again, or stop
			if(result == ENGINE_OK && connection->lock) {
				const char* engine = connection->lock;
				connection->lock = NULL;
				if(db_claim_engine(connection, engine) != ENGINE_OK) exit(1);
			}
		}
		// else { Connection is alive }
	}
	else
	{
		// NULL input
        engine_trace(TRACE_LEVEL_ALWAYS,
        	"ERROR: Could not reconnect with DB, NULL connection");
	}

	return result;
}

/** ****************************************************************************

    @brief          Makes sure only one engine of each kind runs on a database

    Two game engines on one database read the same drone's input at once, and
    each runs part of it: a script arrives cut up and out of order. That broke
    the station drones of a whole world (#380, round 31). The engine holds a
    MySQL named lock for as long as its connection lives; a second engine
    waits a little for it (the first may be shutting down) and then stops.

    @param[in|out]  connection  An open connection
    @param[in]      engine      "game-engine" or "events-engine"

    @return         ENGINE_OK when this engine holds the lock

*******************************************************************************/
ErrorCode_t db_claim_engine(DbConnection_t* connection, const char* engine)
{
	return db_try_claim_engine(connection, engine, 15);
}

/* db_claim_engine, waiting at most wait_seconds for the lock (0: not at
   all). Only a wait that ends without the lock is an error in the log: the
   game engine's settlement tries again without waiting (#694 stage 4) */
ErrorCode_t db_try_claim_engine(DbConnection_t* connection, const char* engine, int wait_seconds)
{
	if(!connection || !connection->hndl || !engine) return ENGINE_INTERNAL_ERROR;

	char query[256];
	snprintf(query, sizeof(query), "SELECT GET_LOCK(CONCAT(DATABASE(), '/%s'), %d)", engine, wait_seconds);
	int held = 0;
	if(mysql_query(connection->hndl, query) == 0) {
		MYSQL_RES* res = mysql_store_result(connection->hndl);
		MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
		held = row && row[0] && atoi(row[0]) == 1;
		if(res) mysql_free_result(res);
	}
	if(!held) {
		if(wait_seconds > 0) engine_trace(TRACE_LEVEL_ALWAYS,
			"ERROR: another %s is already running on database [%s]: stopping",
			engine, connection->db_name ? connection->db_name : "NULL");
		return ENGINE_DB_QUERY_ERROR;
	}
	connection->lock = engine;
	return ENGINE_OK;
}

/** ****************************************************************************

    @brief          Notes that the game engine started (#750): drones' pages
                    said "The engine restarted" while /world-events, and so
                    the charts, had no record of it. One row a start, at the
                    game time it started; a failure (no table yet, before
                    the deploy's migration) is only traced

    @param[in|out]  connection  An open connection
    @param[in]      engine      The engine's name ("game-engine")

    @return         void

*******************************************************************************/
void db_record_engine_start(DbConnection_t* connection, const char* engine)
{
	if(!connection || !connection->hndl || !engine) return;

	char query[512];
	snprintf(query, sizeof(query),
		"INSERT INTO engine_starts (engine, real_time, game_t) SELECT '%s', UNIX_TIMESTAMP(), "
		"IFNULL((SELECT ROUND(game_anchor + (UNIX_TIMESTAMP() - real_anchor) * scale) FROM game_clock WHERE id = 1), UNIX_TIMESTAMP())",
		engine);
	if(mysql_query(connection->hndl, query))
		engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: couldn't note the engine's start: %s", mysql_error(connection->hndl));
}

/** ****************************************************************************

    @brief          Gets object ID for current event drone

    @param[in]      event      Input event
    @param[out]     object_id  Output object ID obtained when success

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_event_object_id(Event_t *event, int *object_id)
{  
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!event || !object_id) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        char* query_end = query_text;

        query_end += snprintf(query_end, 
            QUERY_SPACE_LEFT, 
            "SELECT object_id from agents where agent_id = %d",
            event->drone_id);

        // run it 
        if (mysql_query(connection->hndl, query_text)) 
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else 
        {
            // retrieve the result and check that is an only row with expeced fields number
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);
            uint64_t rowsNum = mysql_num_rows(db_result);
            unsigned int fieldsNum = mysql_num_fields(db_result);

            if((db_result == NULL) || (rowsNum != 1) || (fieldsNum != 1))
            {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "ERROR: Unable to get object_id for DRONE_ID [%d] "
                    "(invalid result for query [%s], rows [%d], fields [%d])",
                    event->drone_id,
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
                    *object_id = row[0]?atoi(row[0]):0;
                    
                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "Obtained object ID info: EVENT_ID [%d] DRONE_ID [%d] OBJECT_ID [%d]", 
                        event->event_id, 
                        event->drone_id,
                        *object_id);
                }
                else 
                {
                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "ERROR: Unable to get object ID for DRONE_ID [%d] (no row)", 
                        event->drone_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }        

    return result;
}



/** ****************************************************************************

    @brief          Runs one statement that returns no rows

*******************************************************************************/
static ErrorCode_t db_exec(DbConnection_t* connection, const char* query_text)
{
    ErrorCode_t result = db_reconnect(connection);

    if((result == ENGINE_OK) && mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        result = ENGINE_DB_QUERY_ERROR;
    }

    return result;
}

/** ****************************************************************************

    @brief          Records why an action failed (the first reason only), so
                    perform can leave it when the action is done

    @param[in]      action_id Action ID
    @param[in]      outcome   Failing event outcome (Outcome_t)

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_set_action_outcome(int action_id, int outcome)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "UPDATE actions SET outcome = %d WHERE id = %d AND outcome IS NULL", outcome, action_id);

    return db_exec(engine_get_db_connection(), query_text);
}

/** ****************************************************************************

    @brief          Makes a drone a wreck (#324 step 8: it ran out of fuel), if
                    it isn't one already. A wreck runs no more commands.

    @param[in]      drone_id  The drone
    @param[in]      cause     Why (agents.wreck_cause), e.g. "fuel"

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_wreck_drone(int drone_id, const char* cause)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "UPDATE agents SET wrecked_at = %lld, wreck_cause = '%s', launched_running = 0 "
        "WHERE agent_id = %d AND wrecked_at IS NULL",
        game_time_now_seconds(), cause, drone_id);

    engine_trace(TRACE_LEVEL_ALWAYS, "Drone [%d] becomes a wreck (%s)", drone_id, cause);

    ErrorCode_t result = db_exec(engine_get_db_connection(), query_text);
    TurnRow_t* turn = db_turn_row(drone_id);
    if(result == ENGINE_OK && turn) {
        turn->wrecked = 1;
        turn->launched_running = 0;
    }

    // A company ends when its last drone dies (#324 step 13): its credits
    // vanish, as when it can't pay its tax
    if(result == ENGINE_OK) {
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
            "UPDATE companies c SET c.credits = 0, c.eliminated_at = %lld, c.end_cause = 'drones' "
            "WHERE c.company_id = (SELECT a.company_id FROM agents a WHERE a.agent_id = %d) "
            "AND c.eliminated_at IS NULL "
            "AND NOT EXISTS (SELECT 1 FROM agents w WHERE w.company_id = c.company_id AND w.wrecked_at IS NULL)",
            game_time_now_seconds(), drone_id);
        result = db_exec(engine_get_db_connection(), query_text);
    }
    return result;
}

/** ****************************************************************************

  @brief      Whether a drone is on a trip at a game time (#380): its move
              event (event type 3) is still pending after that time. A drone
              keeps the place it left until it arrives, so without this check
              other drones could trade with it all the way to Mars.

  @param[in]  drone_id  The drone
  @param[in]  game_t    The game time to check

  @return     ENGINE_TRUE when it is in flight (ENGINE_FALSE on a DB error)

*******************************************************************************/
Bool_t db_drone_in_transit(int drone_id, long long game_t)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = engine_get_db_connection();
    Bool_t in_transit = ENGINE_FALSE;

    if(db_reconnect(connection) != ENGINE_OK) return ENGINE_FALSE;

    // Only a trip that had begun by game_t (#543): its action has an event
    // (the fuel it burns at the start) at or before then. A pending move set
    // off after the trade's moment, but before the trade settled (the events
    // engine runs behind), refused trades between drones that were together
    snprintf(query_text, sizeof(query_text),
        "SELECT 1 FROM events AS m WHERE m.drone = %d AND m.event_type = 3 "
        "AND m.outcome = 0 AND m.game_t > %lld "
        "AND EXISTS (SELECT 1 FROM events AS b WHERE b.action = m.action AND b.game_t <= %lld) LIMIT 1",
        drone_id, game_t, game_t);

    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        return ENGINE_FALSE;
    }

    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    if(db_result) {
        if(mysql_num_rows(db_result) > 0) in_transit = ENGINE_TRUE;
        mysql_free_result(db_result);
    }
    return in_transit;
}

/** ****************************************************************************

  @brief      Where a drone is at game time game_t (#726): the destination of
              its last move due by then, even if the events engine hasn't
              applied it yet (it runs a few game seconds behind), else its
              place in agents. A drone restarted a game second after reaching
              Earth said "Location: Mars", the place it had left. 0 when
              neither can be read.

*******************************************************************************/
int db_drone_place(int drone_id, long long game_t)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = engine_get_db_connection();
    int place = 0;

    if(db_reconnect(connection) != ENGINE_OK) return 0;

    snprintf(query_text, sizeof(query_text),
        "SELECT IFNULL((SELECT e.new_location FROM events AS e FORCE INDEX (events_drone_game_t) WHERE e.drone = a.agent_id AND e.event_type = 3 "
        "AND e.outcome = 0 AND e.game_t <= %lld AND e.new_location > 0 ORDER BY e.game_t DESC, e.id DESC LIMIT 1), "
        "a.object_id) FROM agents AS a WHERE a.agent_id = %d", game_t, drone_id);

    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        return 0;
    }

    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    if(db_result) {
        MYSQL_ROW row = mysql_fetch_row(db_result);
        if(row && row[0]) place = atoi(row[0]);
        mysql_free_result(db_result);
    }
    return place;
}

/** ****************************************************************************

  @brief      When a drone's trip ends (#675): the game time of its last move
              event (event type 3) still pending after game_t, or 0 when it
              isn't on a trip

*******************************************************************************/
long long db_drone_arrival(int drone_id, long long game_t)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = engine_get_db_connection();
    long long arrival = 0;

    if(db_reconnect(connection) != ENGINE_OK) return 0;

    snprintf(query_text, sizeof(query_text),
        "SELECT MAX(game_t) FROM events WHERE drone = %d AND event_type = 3 "
        "AND outcome = 0 AND game_t > %lld", drone_id, game_t);

    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        return 0;
    }

    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    if(db_result) {
        MYSQL_ROW row = mysql_fetch_row(db_result);
        if(row && row[0]) arrival = atoll(row[0]);
        mysql_free_result(db_result);
    }
    return arrival;
}

/** ****************************************************************************

  @brief      Records a broadcast (#380): what a drone said, where it was and
              when. Queries 690 and 691 deliver it at the speed of light.

  @param[in]  action_id The broadcast action
  @param[in]  drone_id  The drone that broadcast it
  @param[in]  body      The message (at most BROADCAST_MAX_LEN printable chars)
  @param[in]  game_t    When it was sent (game seconds)

  @return     Execution result

*******************************************************************************/
ErrorCode_t db_insert_broadcast(int action_id, int drone_id, const char* body, long long game_t)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    char escaped[2 * BROADCAST_MAX_LEN + 1];
    DbConnection_t* connection = engine_get_db_connection();

    if(!body) return ENGINE_INTERNAL_ERROR;
    ErrorCode_t result = db_reconnect(connection);
    if(result != ENGINE_OK) return result;

    mysql_real_escape_string(connection->hndl, escaped, body, strlen(body));
    snprintf(query_text, sizeof(query_text),
        "INSERT INTO broadcasts (action_id, drone, company_id, object_id, game_t, body) "
        "SELECT %d, agent_id, company_id, object_id, %lld, '%s' FROM agents WHERE agent_id = %d",
        action_id, game_t, escaped, drone_id);

    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        return ENGINE_DB_QUERY_ERROR;
    }
    return ENGINE_OK;
}

/******************************* THE TURN'S ROW (#431 step 2) ****************/

static TurnRow_t g_turn_row;
static int g_turn_row_valid = 0;

void db_turn_row_clear(void)
{
    free(g_turn_row.pending_input);
    memset(&g_turn_row, 0, sizeof(g_turn_row));
    g_turn_row_valid = 0;
}

TurnRow_t* db_turn_row_current(void)
{
    return g_turn_row_valid ? &g_turn_row : NULL;
}

TurnRow_t* db_turn_row(int agent_id)
{
    return (g_turn_row_valid && g_turn_row.agent_id == agent_id) ? &g_turn_row : NULL;
}

ErrorCode_t db_turn_row_load(DbConnection_t* connection, int agent_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    db_turn_row_clear();
    ErrorCode_t result = db_reconnect(connection);
    if(result != ENGINE_OK)
        return result;

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT launched_running, crashes_in_row, wrecked_at IS NOT NULL, release_vm IS NULL, "
        "instructions, power_paid, IFNULL(waiting_action, 0), pending_input, "
        "IFNULL((SELECT c.compute_debt > 0 FROM companies AS c WHERE c.company_id = agents.company_id), 0) "
        "FROM agents WHERE agent_id = %d",
        agent_id);
    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }

    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    MYSQL_ROW row = db_result ? mysql_fetch_row(db_result) : NULL;
    if(row) {
        g_turn_row.agent_id = agent_id;
        g_turn_row.launched_running = row[0] ? atoi(row[0]) : 0;
        g_turn_row.crashes_in_row = row[1] ? atoi(row[1]) : 0;
        g_turn_row.wrecked = row[2] ? atoi(row[2]) : 0;
        g_turn_row.loading = row[3] ? atoi(row[3]) : 0;
        g_turn_row.instructions = row[4] ? atoll(row[4]) : 0;
        g_turn_row.power_paid = row[5] ? atoll(row[5]) : 0;
        g_turn_row.waiting_action = row[6] ? atoi(row[6]) : 0;
        // An empty pending input reads as none, as db_get_agent_pending_input did
        g_turn_row.pending_input = (row[7] && row[7][0]) ? strdup(row[7]) : NULL;
        g_turn_row.throttled = row[8] ? atoi(row[8]) : 0;
        g_turn_row_valid = 1;
    } else {
        result = ENGINE_DB_NOT_FOUND_ERROR;
    }
    if(db_result) mysql_free_result(db_result);
    return result;
}

/******************************* SHARE ORDERS (#469) *************************/

int db_company_trading(int company)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = engine_get_db_connection();
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT COUNT(*) FROM companies WHERE company_id = %d AND eliminated_at IS NULL", company);
    if(mysql_query(connection->hndl, query_text))
        return 0;
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    int found = (row && row[0]) ? atoi(row[0]) > 0 : 0;
    if(res) mysql_free_result(res);
    return found;
}

ErrorCode_t db_insert_share_request(int action_id, int drone_id, int kind, int share_company, int counterparty,
                                    int units, int price, long long placed_t)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    char other[16];
    if(counterparty > 0) snprintf(other, sizeof(other), "%d", counterparty);
    else snprintf(other, sizeof(other), "NULL");
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "INSERT INTO share_requests (action, company, drone, kind, share_company, counterparty, units, price, placed_t) "
        "SELECT %d, company_id, %d, %d, %d, %s, %d, %d, %lld FROM agents WHERE agent_id = %d",
        action_id, drone_id, kind, share_company, other, units, price, placed_t, drone_id);
    return db_exec(engine_get_db_connection(), query_text);
}

/******************************* EVENT IDS (#694 stage 4 step 6) *************/

static long long event_ids_ms(void)
{
    struct timespec t;
    clock_gettime(CLOCK_MONOTONIC, &t);
    return (long long)t.tv_sec * 1000 + t.tv_nsec / 1000000;
}

static int event_ids_check_build(void)
{
#ifdef EVENT_IDS_CHECK
    return 1;
#else
    return 0;
#endif
}

/* The NOTICE once a real minute, with the flag on (or in a check build) */
static void event_ids_tick(void)
{
    if(!event_ids_minute_due(event_ids_ms()))
        return;
    char line[600];
    event_ids_notice(line, sizeof(line), event_ids_flag_on(), event_ids_check_build());
    engine_trace(TRACE_LEVEL_ALWAYS, "%s", line);
}

/* The reservations' own connection: READ COMMITTED, autocommit off, a lock
   wait of a second at most. Made at the first reservation, with the
   settings of the connection that asked */
static MYSQL* g_ids_hndl = NULL;

static void event_ids_disconnect(void)
{
    if(g_ids_hndl)
        mysql_close(g_ids_hndl);
    g_ids_hndl = NULL;
}

static int event_ids_connect(const DbConnection_t* like)
{
    if(g_ids_hndl)
        return 0;
    MYSQL* h = mysql_init(NULL);
    if(!h)
        return -1;
    if(!mysql_real_connect(h, like->host, like->user, like->password, like->db_name, like->port, NULL, 0)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: ENGINE_OWNS_EVENTS: can't connect for reservations: %s", mysql_error(h));
        mysql_close(h);
        return -1;
    }
    // Under REPEATABLE READ a rolled-back insert leaves gap locks behind it,
    // up to the end of the table, until the transaction ends: every other
    // writer of events would wait. READ COMMITTED leaves none. A wait for a
    // lock (another transaction holding the end of the table) gives up
    // after a second: AUTO_INCREMENT instead
    if(mysql_query(h, "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED") ||
       mysql_query(h, "SET SESSION innodb_lock_wait_timeout = 1") ||
       mysql_autocommit(h, 0)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: ENGINE_OWNS_EVENTS: can't set up the reservations' connection: %s",
                     mysql_error(h));
        mysql_close(h);
        return -1;
    }
    g_ids_hndl = h;
    return 0;
}

static int event_ids_query(const char* sql)
{
    if(mysql_query(g_ids_hndl, sql)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: ENGINE_OWNS_EVENTS: [%.60s] failed: %s", sql, mysql_error(g_ids_hndl));
        unsigned int err = mysql_errno(g_ids_hndl);
        if(err == 2006 || err == 2013)   // gone away, lost: connect again next time
            event_ids_disconnect();
        return -1;
    }
    return 0;
}

#ifdef EVENT_IDS_CHECK
/* Check build: how many of the placeholders just inserted lie in
   [first, first + n): n when their ids were consecutive */
static long long event_ids_count_placeholders(int first, int n)
{
    char sql[200];
    snprintf(sql, sizeof(sql), "SELECT COUNT(*) FROM events WHERE id BETWEEN %d AND %d AND game_t = -1",
             first, first + n - 1);
    if(mysql_query(g_ids_hndl, sql))
        return -1;
    MYSQL_RES* res = mysql_store_result(g_ids_hndl);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    long long count = (row && row[0]) ? atoll(row[0]) : -1;
    if(res) mysql_free_result(res);
    return count;
}
#endif

/** ****************************************************************************

    @brief          ENGINE_OWNS_EVENTS=1: reserves n event ids from the events
                    table's own AUTO_INCREMENT counter, for the engine to
                    insert with (see event_ids.h)

    Placeholder rows go in, on a connection of their own, and are rolled
    back at once: InnoDB never hands out an auto-increment value twice, so
    their ids are ours, and every later writer's are higher. A statement of
    known rows takes consecutive values (innodb_autoinc_lock_mode 2, "simple
    inserts"). Not in the caller's transaction: there, under REPEATABLE READ
    (one game engine, and settlement), the rollback left gap locks on the
    end of the table until the caller committed, and the website's inserts
    deadlocked with settlement.

    @param[in]      connection  The connection that will insert the events
                                (its settings make the reservations' own)
    @param[in]      n           How many
    @param[out]     ids         n ids, in increasing order

    @return         ENGINE_OK, or an error and ids all 0: the caller inserts
                    by AUTO_INCREMENT, as with the flag off

*******************************************************************************/
ErrorCode_t db_reserve_event_ids(DbConnection_t* connection, int n, int* ids)
{
    if(n <= 0)
        return ENGINE_OK;
    memset(ids, 0, sizeof(int) * (size_t)n);
    if(!connection || event_ids_connect(connection)) {
        event_ids_stats()->fallbacks++;
        return ENGINE_DB_QUERY_ERROR;
    }
    char sql[64 + EVENT_IDS_CHUNK * 5];
    int done = 0, failed = 0;
    while(done < n && !failed) {
        int chunk = n - done < EVENT_IDS_CHUNK ? n - done : EVENT_IDS_CHUNK;
        if(event_ids_placeholder_sql(sql, sizeof(sql), chunk) < 0 || event_ids_query(sql)) {
            failed = 1;
            break;
        }
        int first = (int)mysql_insert_id(g_ids_hndl);
        if(first <= 0 || (long long)mysql_affected_rows(g_ids_hndl) != chunk) {
            failed = 1;
            break;
        }
#ifdef EVENT_IDS_CHECK
        event_ids_stats()->check_ranges++;
        long long mine = event_ids_count_placeholders(first, chunk);
        if(mine != chunk) {
            event_ids_stats()->check_gaps++;
            engine_trace(TRACE_LEVEL_ALWAYS,
                "WARNING: EVENT_IDS_CHECK: [%d] placeholders from id [%d], [%lld] of them in the range: not consecutive",
                chunk, first, mine);
            failed = 1;
            break;
        }
#endif
        for(int i = 0; i < chunk; i++)
            ids[done + i] = first + i;
        done += chunk;
        event_ids_stats()->reservations++;
    }
    // The placeholders go; the counter stays past them
    if(g_ids_hndl && event_ids_query("ROLLBACK"))
        failed = 1;
    if(failed) {
        // AUTO_INCREMENT instead, as with the flag off
        memset(ids, 0, sizeof(int) * (size_t)n);
        event_ids_stats()->fallbacks++;
        return ENGINE_DB_QUERY_ERROR;
    }
    event_ids_stats()->ids += n;
    static int announced = 0;
    if(!announced) {
        announced = 1;
        engine_trace(TRACE_LEVEL_ALWAYS,
            "NOTICE: ENGINE_OWNS_EVENTS=1: this engine reserves its events' ids (first [%d])", ids[0]);
    }
    return ENGINE_OK;
}

/** ****************************************************************************

    @brief          Notes an event insert: which way it took its id, the
                    check build's per-drone order check, and the NOTICE once
                    a minute. Called after every events INSERT of the engine's
                    own (db_insert_event, db_insert_event_e, the fuel refund),
                    whatever the flag; with it off (and no check build) it
                    returns at once

    @param[in]      connection    The connection that inserted it (the check
                                  build reads the drone's events there)
    @param[in]      id            Its id
    @param[in]      drone         Its drone
    @param[in]      owned         1: the id was reserved (ENGINE_OWNS_EVENTS=1)
    @param[in]      failed_errno  0, or the insert's mysql_errno when it failed

*******************************************************************************/
void db_event_ids_inserted(DbConnection_t* connection, int id, int drone, int owned, unsigned int failed_errno)
{
    if(!event_ids_flag_on() && !event_ids_check_build())
        return;   // flag off: nothing, as before
    if(failed_errno) {
        if(owned && failed_errno == 1062) {   // ER_DUP_ENTRY: never expected
            event_ids_stats()->duplicates++;
            engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: ENGINE_OWNS_EVENTS: event id [%d] was taken already", id);
        }
        event_ids_tick();
        return;
    }
    if(owned)
        event_ids_stats()->inserted++;
    else
        event_ids_stats()->auto_inserted++;
#ifdef EVENT_IDS_CHECK
    // Do this drone's events keep their id order? None of its events that
    // this connection can see may have a higher id than the one just
    // inserted, as with AUTO_INCREMENT at the moment of the insert
    if(drone > 0 && id > 0) {
        char sql[200];
        snprintf(sql, sizeof(sql), "SELECT COUNT(*), MAX(id) FROM events WHERE drone = %d AND id > %d", drone, id);
        if(mysql_query(connection->hndl, sql) == 0) {
            MYSQL_RES* res = mysql_store_result(connection->hndl);
            MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
            long long higher = (row && row[0]) ? atoll(row[0]) : 0;
            event_ids_stats()->check_inserts++;
            if(higher > 0) {
                event_ids_stats()->check_inversions++;
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "WARNING: EVENT_IDS_CHECK: event [%d] of drone [%d] (%s) is below [%lld] of its events (up to id [%s])",
                    id, drone, owned ? "reserved" : "AUTO_INCREMENT", higher, row[1] ? row[1] : "?");
            }
            if(res) mysql_free_result(res);
        }
    }
#else
    (void)connection; (void)drone;
#endif
    event_ids_tick();
}
