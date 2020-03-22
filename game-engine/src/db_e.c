/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: DB interface module

******************************************************************************/

/******************************* INCLUDES ************************************/

#define _XOPEN_SOURCE
#include <time.h>
#include <string.h>

#include "db.h"
#include "trace.h"
#include "engine_e.h"

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
void db_scape_string(
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

   engine_trace(TRACE_LEVEL_DEBUG, "Value [%s] scaped now is [%s]", value, out);
}

/** ***************************************************************************

  @brief      Converts a given date string into timestamp value

  @param[in]  date    Input date string

  @return     void

******************************************************************************/
time_t db_date_to_timestamp(char* date, char* format)
{
    struct tm tm;
    time_t epoch = 0;

    // input pointers checked by calling function

    char* conversion_result = strptime(date, format, &tm);

    if (conversion_result != NULL) {
        engine_trace(TRACE_LEVEL_ALWAYS,
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

    engine_trace(TRACE_LEVEL_ALWAYS,
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
ErrorCode_t db_init(DbConnection_t* connection)
{
	MYSQL* local_hndl = NULL;
	ErrorCode_t result = ENGINE_OK;

    if(connection)
    {
    	engine_trace(TRACE_LEVEL_DEBUG,
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
                engine_trace(TRACE_LEVEL_ALWAYS,
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
                 engine_trace(TRACE_LEVEL_ALWAYS,
                 	"ERROR: Unable to conect to DB. "
                    "user [%s] host [%s] port [%d] database [%s]",
                    connection->user?connection->user:"NULL",
                    connection->host?connection->host:"NULL",
                    connection->port,
                    connection->db_name?connection->db_name:"NULL");

                 result = ENGINE_DB_CONNECT_ERROR;
            }
        }
        else
       	{
       		engine_trace(TRACE_LEVEL_ALWAYS,
    			"ERROR: Unable to init DB connection");

       		result = ENGINE_DB_INIT_ERROR;
       	}
    }
    else
    {
        // NULL input
        engine_trace(TRACE_LEVEL_ALWAYS,
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
void db_stop(DbConnection_t* connection)
{
	if(connection && connection->hndl) {
        mysql_close(connection->hndl);
        connection->hndl = NULL;
        engine_trace(TRACE_LEVEL_ALWAYS,
        	"Disconnected from DB [%s]",
            connection->db_name?connection->db_name:"NULL");
    }
}

/** ***************************************************************************

  @brief          Initiates a transaction (group of queries associated)

  @param[in|out]  DB Connection info

  @return         Execution result

******************************************************************************/
ErrorCode_t db_start_transaction(DbConnection_t* connection)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // Execute it at mysql side and check results
    if (mysql_query(connection->hndl, "START TRANSACTION"))
    {
        engine_trace(TRACE_LEVEL_ALWAYS,"ERROR: Could not start DB transaction");
        result = ENGINE_DB_QUERY_ERROR;
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "DB transaction started");
    }

    return result;
}

/** ***************************************************************************

  @brief          Ends/commits a transaction (group of queries associated)

  @param[in|out]  DB Connection info

  @return         Execution result

******************************************************************************/
ErrorCode_t db_commit_transaction(DbConnection_t* connection)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // Execute it at mysql side and check results
    if (mysql_query(connection->hndl, "COMMIT"))
    {
        engine_trace(TRACE_LEVEL_ALWAYS,"ERROR: Could not commit DB transaction");
        result = ENGINE_DB_QUERY_ERROR;
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "DB transaction commit");
    }

    return result;
}

/** ***************************************************************************

  @brief          Rollback a transaction (group of queries associated)

  @param[in|out]  DB Connection info

  @return         Execution result

******************************************************************************/
ErrorCode_t db_rollback_transaction(DbConnection_t* connection)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // Execute it at mysql side and check results
    if (mysql_query(connection->hndl, "ROLLBACK"))
    {
        engine_trace(TRACE_LEVEL_ALWAYS,"ERROR: Could not rollback DB transaction");
        result = ENGINE_DB_QUERY_ERROR;
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "DB transaction rollback");
    }

    return result;
}

/** ****************************************************************************

    @brief          Gets next batch of outcome events

    @param[in]      Callback to be invoked when outcome event detected

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_outcome_events(void (*outcomeEventCb)(Event_t *e))
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection = engine_get_db_connection();
    MYSQL_RES* db_result = NULL;
    int rowsNum = 0;

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        int events_batch_size = engine_get_outcome_events_batch_size();
        engine_trace(TRACE_LEVEL_ALWAYS,
            "Getting next batch of [%d] not processed events ...",
            events_batch_size);

        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "SELECT * FROM events as e where timestamp <= NOW() and outcome = 0 and (select aborted from actions where id = e.action) != 1 limit %d;",
            events_batch_size);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if(result == ENGINE_OK)
    {
        // retrieve the results and process one by one
        db_result = mysql_store_result(connection->hndl);
        rowsNum = mysql_num_rows(db_result);

        if(db_result == NULL)
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get outcome events (invalid result for query [%s])",
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if(rowsNum <= 0)
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "None pending event found");
        }
    }

    if((result == ENGINE_OK) && rowsNum)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "[%d] pending events found");

        // iterate results and delete one by one
        for(int eventId=0; eventId < rowsNum; eventId++)
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row)
            {
                Event_t event;
                // Pick event fields
                event.event_id = row[EVENT_ID_IDX]?atoi(row[EVENT_ID_IDX]):0;
                event.event_type = row[EVENT_TYPE_IDX]?atoi(row[EVENT_TYPE_IDX]):0;
                event.action_id = row[EVENT_ACTION_IDX]?atoi(row[EVENT_ACTION_IDX]):0;
                event.logged = row[EVENT_LOGGED_IDX]?atoi(row[EVENT_LOGGED_IDX]):0;
                event.outcome = row[EVENT_OUTCOME_IDX]?atoi(row[EVENT_OUTCOME_IDX]):0;
                event.drone_id = row[EVENT_DRONE_ID_IDX]?atoi(row[EVENT_DRONE_ID_IDX]):0;
                event.resource_id = row[EVENT_RESOURCE_ID_IDX]?atoi(row[EVENT_RESOURCE_ID_IDX]):0;
                event.installed = row[EVENT_INSTALLED_IDX]?atoi(row[EVENT_INSTALLED_IDX]):0;
                event.locked = row[EVENT_LOCKED_IDX]?atoi(row[EVENT_LOCKED_IDX]):0;
                event.new_quantity = row[EVENT_NEW_QUANTITY_IDX]?atoi(row[EVENT_NEW_QUANTITY_IDX]):NULL_VALUE;
                event.new_credits = row[EVENT_NEW_CREDITS_IDX]?atoi(row[EVENT_NEW_CREDITS_IDX]):NULL_VALUE;
                event.new_location = row[EVENT_NEW_LOCATION_IDX]?atoi(row[EVENT_NEW_LOCATION_IDX]):-1;
                event.new_transmission = row[EVENT_NEW_TRANSMISSION_IDX]?atoi(row[EVENT_NEW_TRANSMISSION_IDX]):-1;
                event.new_cargo = row[EVENT_NEW_CARGO_IDX]?atoi(row[EVENT_NEW_CARGO_IDX]):0;

                // Convert date obtained to time_t
                event.timestamp = db_date_to_timestamp(row[EVENT_TIMESTAMP_IDX], "%F %T");

                engine_trace(TRACE_LEVEL_ALWAYS,
                    "DATE [%s] => TIMESTAMP [%ld] %s",
                    row[EVENT_TIMESTAMP_IDX], event.timestamp, row[EVENT_NEW_LOCATION_IDX]);

                // Invoke callback when != NULL
                if(outcomeEventCb) outcomeEventCb(&event);
            }
            else
            {
                // unexpected error - abort
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get row for query [%s] with [%d] rows",
                    query_text,
                    rowsNum);

                result = ENGINE_DB_QUERY_ERROR;

                break; // stop for
            }
        }
    }

    if(db_result)
        mysql_free_result(db_result);

    return result;
}

/** ****************************************************************************

    @brief          Purges old events present at DB (older than configurable days value)

    @param[in]      void

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_purge_old_events()
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection = engine_get_db_connection();
    MYSQL_RES* db_result = NULL;
    int rowsNum = 0;

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "SELECT * FROM events WHERE timestamp < (NOW() - interval %d day);",
            engine_get_events_expiration_days());

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if(result == ENGINE_OK)
    {
        // retrieve the results and delete one by one
        db_result = mysql_store_result(connection->hndl);

        if(db_result == NULL)
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get expired events (invalid result for query [%s])",
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((rowsNum=mysql_num_rows(db_result)) <= 0)
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "None expired event found");
        }
    }

    if((result == ENGINE_OK) && rowsNum)
    {
        // iterate results and delete one by one
        for(int eventId=0; eventId < rowsNum; eventId++)
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row)
            {
                Event_t event;

                memset(&event, 0, sizeof(event));

                // Pick event fields
                event.event_id = row[EVENT_ID_IDX]?atoi(row[EVENT_ID_IDX]):0;
                event.event_type = row[EVENT_TYPE_IDX]?atoi(row[EVENT_TYPE_IDX]):0;
                event.action_id = row[EVENT_ACTION_IDX]?atoi(row[EVENT_ACTION_IDX]):0;
                event.logged = row[EVENT_LOGGED_IDX]?atoi(row[EVENT_LOGGED_IDX]):0;
                event.outcome = row[EVENT_OUTCOME_IDX]?atoi(row[EVENT_OUTCOME_IDX]):0;
                event.drone_id = row[EVENT_DRONE_ID_IDX]?atoi(row[EVENT_DRONE_ID_IDX]):0;
                event.resource_id = row[EVENT_RESOURCE_ID_IDX]?atoi(row[EVENT_RESOURCE_ID_IDX]):0;
                event.installed = row[EVENT_INSTALLED_IDX]?atoi(row[EVENT_INSTALLED_IDX]):0;
                event.locked = row[EVENT_LOCKED_IDX]?atoi(row[EVENT_LOCKED_IDX]):0;
                event.new_quantity = row[EVENT_NEW_QUANTITY_IDX]?atoi(row[EVENT_NEW_QUANTITY_IDX]):NULL_VALUE;
                event.new_credits = row[EVENT_NEW_CREDITS_IDX]?atoi(row[EVENT_NEW_CREDITS_IDX]):NULL_VALUE;
                event.new_location = row[EVENT_NEW_LOCATION_IDX]?atoi(row[EVENT_NEW_LOCATION_IDX]):-1;
                event.new_transmission = row[EVENT_NEW_TRANSMISSION_IDX]?atoi(row[EVENT_NEW_TRANSMISSION_IDX]):-1;
                event.new_cargo = row[EVENT_NEW_CARGO_IDX]?atoi(row[EVENT_NEW_CARGO_IDX]):NULL_VALUE;
                event.timestamp = row[EVENT_TIMESTAMP_IDX]?atoi(row[EVENT_TIMESTAMP_IDX]):0;

                if(db_delete_event(&event) == ENGINE_OK) {
                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "Event expired and deleted "
                        "EVENT_ID [%d] EVENT_TYPE [%d] ACTION_ID [%d] LOGGED [%d] OUTCOME [%d] "
                        "DRONE_ID [%d] RESOURCE_ID [%d] INSTALLED [%d] LOCKED [%d] NEW_QUANTITY [%d] "
                        "NEW_CREDITS [%d] NEW_LOCATION [%d] NEW_CARGO [%d] TIMESTAMP [%ld]",
                        event.event_id,
                        event.event_type,
                        event.action_id,
                        event.logged,
                        event.outcome,
                        event.drone_id,
                        event.resource_id,
                        event.installed,
                        event.locked,
                        event.new_quantity,
                        event.new_credits,
                        event.new_location,
                        event.new_transmission,
                        event.new_cargo,
                        event.timestamp);
                }
            }
            else
            {
                // unexpected error - abort
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get row for query [%s] with [%d] rows",
                    query_text,
                    rowsNum);

                result = ENGINE_DB_QUERY_ERROR;

                break; // stop for
            }
        }
    }

    if(db_result)
        mysql_free_result(db_result);

    return result;
}


/** ****************************************************************************

    @brief          Purges old actions present at DB (older than configurable days value)
                    Those actions that have no event associated

    @param[in]      void

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_purge_old_actions()
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection = engine_get_db_connection();
    MYSQL_RES* db_result = NULL;
    int rowsNum = 0;

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "SELECT id FROM actions WHERE id not in (SELECT action FROM events) AND timestamp < (NOW() - interval %d day);",
            engine_get_actions_expiration_days());

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if(result == ENGINE_OK)
    {
        // retrieve the results and delete one by one
        db_result = mysql_store_result(connection->hndl);

        if(db_result == NULL)
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get expired actions (invalid result for query [%s])",
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((rowsNum=mysql_num_rows(db_result)) <= 0)
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "None expired action found");
        }
    }

    if((result == ENGINE_OK) && rowsNum)
    {
        // iterate results and delete actions one by one
        for(int actionId=0; actionId < rowsNum; actionId++)
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row)
            {
                // Pick action ID and delete it
                int actionId = atoi(row[0]);

                if(db_delete_action(actionId) == ENGINE_OK) {
                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "Action expired and deleted ACTION_ID [%d]",
                        actionId);
                }
            }
            else
            {
                // unexpected error - abort
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get row for query [%s] with [%d] rows",
                    query_text,
                    rowsNum);

                result = ENGINE_DB_QUERY_ERROR;

                break; // stop for
            }
        }
    }

    if(db_result)
        mysql_free_result(db_result);

    return result;
}

/** ****************************************************************************

    @brief          Deletes event giving event_id

    @param[in]      Input event

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_event(Event_t* event)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

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
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
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
        engine_trace(TRACE_LEVEL_ALWAYS,
            "ERROR: Unable to get previous event for NULL input parameters");

        return ENGINE_INTERNAL_ERROR;
    }

    DbConnection_t* connection = engine_get_db_connection();
    MYSQL_RES* db_result = NULL;
    int rowsNum = 0;

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        engine_trace(TRACE_LEVEL_ALWAYS,
            "Getting previous event for event_id [%d] and filter type [%d] ...",
            event->event_id, filter);

        // Generate timestamp string from time_t
        char timestamp_buffer[80];
        strftime(timestamp_buffer, 80,"%F %T", localtime(&event->timestamp));
        engine_trace(TRACE_LEVEL_ALWAYS,
                    "TIMESTAMP [%ld] => DATE [%s]",
                    event->timestamp, timestamp_buffer);

        // Build the query depending on filter type
        switch(filter) {
            case PREVIOUS_EVENT_BY_RESOURCE_INFO:
                snprintf(query_text,
                    DB_MAX_SQL_QUERY_LEN,
                    "SELECT * "
                    "FROM events "
                    "WHERE drone = %d and resource = %d and installed = %d and locked = %d "
                    "and outcome = %d " // success
                    "and timestamp < '%s' order by timestamp DESC limit 1;",  //
                    event->drone_id, event->resource_id, event->installed, event->locked, OUTCOME_OK, timestamp_buffer);
                break;
            case PREVIOUS_EVENT_BY_DRONE:
                snprintf(query_text,
                    DB_MAX_SQL_QUERY_LEN,
                    "SELECT * "
                    "FROM events "
                    "WHERE drone = %d "
                    "and outcome = %d " // success
                    "and timestamp < '%s' order by timestamp DESC limit 1;",
                    event->drone_id, OUTCOME_OK, timestamp_buffer);
                break;
            case PREVIOUS_EVENT_BY_OWNER:
                snprintf(query_text,
                    DB_MAX_SQL_QUERY_LEN,
                    "SELECT * from events where drone in "
                    "(select agent_id from agents where user_id = (select user_id from agents where agent_id = %d)) "
                    "and outcome = %d " // success
                    "and timestamp < '%s' order by timestamp DESC limit 1;",
                    event->drone_id, OUTCOME_OK, timestamp_buffer);
                break;
            default:
                // Unexpected enum value
                engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unexpected filter value [%d] getting previous event", filter);
                result = ENGINE_INTERNAL_ERROR;
                break;
        }
    }

    if(result == ENGINE_OK)
    {
        // run query
        engine_trace(TRACE_LEVEL_ALWAYS, "Running query [%s]", query_text);


        if (mysql_query(connection->hndl, query_text))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if(result == ENGINE_OK)
    {
        // retrieve the results and process one by one
        db_result = mysql_store_result(connection->hndl);

        if(db_result == NULL)
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get previous event (invalid result for query [%s])",
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((rowsNum=mysql_num_rows(db_result)) <= 0)
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "None previous event found");
            result = ENGINE_NOT_FOUND; // Indicates that no entry was found
        }
    }

    if((result == ENGINE_OK) && rowsNum)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "[%d] previous event found", rowsNum);

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
            out_event->timestamp = db_date_to_timestamp(row[EVENT_TIMESTAMP_IDX], "%F %T");
        }
        else
        {
            // unexpected error
            engine_trace(TRACE_LEVEL_ALWAYS,
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

    DbConnection_t* connection =  engine_get_db_connection();

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
            DB_MAX_SQL_QUERY_LEN,
            "SELECT * from actions where id = %d",
            action->action_id);

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

            if((db_result == NULL) || (rowsNum != 1) || (fieldsNum != MAX_ACTION_FIELDS))
            {
                engine_trace(TRACE_LEVEL_ALWAYS,
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

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "DRONE_ID [%d] PROTOCOL_ID [%d] PROCESS_MULTIPLIER [%d] ABORTED [%d] obtained for ACTION_ID [%d]",
                        action->drone_id,
                        action->protocol_id,
                        action->process_multiplier,
                        action->aborted,
                        action->action_id);
                }
                else
                {
                    engine_trace(TRACE_LEVEL_ALWAYS,
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
ErrorCode_t db_abort_action(int action_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        char* query_end = query_text;

        query_end += snprintf(query_end,
            DB_MAX_SQL_QUERY_LEN,
            "UPDATE actions SET aborted = 1 WHERE ID = %d",
            action_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "Action [%d] aborted", action_id);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Updates event info using input info received

    @param[in]      event Event info to be updated

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_event(Event_t *event)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

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

        query_end += snprintf(query_end, DB_MAX_SQL_QUERY_LEN, "UPDATE events SET outcome = %d,", event->outcome);


        // Check first if we have any value to set
        if((event->new_quantity != NULL_VALUE) || (event->new_credits != NULL_VALUE) || (event->new_cargo != NULL_VALUE))
        {
            if(event->new_quantity != NULL_VALUE)
                query_end += snprintf(query_end, DB_MAX_SQL_QUERY_LEN, " new_quantity = %d,", event->new_quantity);

            if(event->new_credits != NULL_VALUE)
                query_end += snprintf(query_end, DB_MAX_SQL_QUERY_LEN, " new_credits = %d,", event->new_credits);

            if(event->new_cargo != NULL_VALUE)
                query_end += snprintf(query_end, DB_MAX_SQL_QUERY_LEN, " new_cargo = %d,", event->new_cargo);
        }

        query_end--; // overwrite the latest comma
        query_end += snprintf(query_end, DB_MAX_SQL_QUERY_LEN, " WHERE id = %d", event->event_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "Event [%d] updated with outcome [%d] new_quantity [%d] new_credits [%d] new cargo [%d]",
                event->event_id, event->outcome, event->new_quantity, event->new_credits, event->new_cargo);
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
ErrorCode_t db_delete_previous_events(Event_t *event)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // Sanity check
    if(!event)
    {
        engine_trace(TRACE_LEVEL_ALWAYS,
            "ERROR: Unable to delete previous events for NULL input event");

        return ENGINE_INTERNAL_ERROR;
    }

    DbConnection_t* connection = engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        engine_trace(TRACE_LEVEL_ALWAYS,
            "Deleting previous expired events for "
            "event_id [%d] event_type [%d] drone_id [%d] resource_id [%d] installed [%d] locked [%d] ...",
            event->event_id, event->event_type, event->drone_id, event->resource_id, event->installed, event->locked);

        snprintf(query_text,
                    DB_MAX_SQL_QUERY_LEN,
                    "DELETE "
                    "FROM events "
                    "WHERE event_type = %d and drone = %d and resource = %d and installed = %d and locked = %d "
                    "and timestamp < (NOW() - interval %d day);",  //
                    event->event_type, event->drone_id, event->resource_id, event->installed, event->locked,
                    engine_get_events_expiration_days());

        // run query
        if (mysql_query(connection->hndl, query_text))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if(result == ENGINE_OK)
    {
        engine_trace(TRACE_LEVEL_ALWAYS,
            "Deleted [%d] previous events for event_id [%d] event_type [%d] drone_id [%d] "
            "resource_id [%d] installed [%d] locked [%d] ...",
            mysql_affected_rows(connection->hndl), event->event_id, event->event_type, event->drone_id,
            event->resource_id, event->installed, event->locked);
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

    DbConnection_t* connection =  engine_get_db_connection();

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
            DB_MAX_SQL_QUERY_LEN,
            "SELECT cargo_capacity from hulls where id = (select hull_type from agents where agent_id = %d)",
            drone_id);

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

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "DRONE_ID [%d] MAX_CAPACITY [%d]",
                        drone_id,
                        *capacity );
                }
                else
                {
                    engine_trace(TRACE_LEVEL_ALWAYS,
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
ErrorCode_t db_insert_observation(Observation_t* observation)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        // Sanity check
        if(!observation)
            return ENGINE_INTERNAL_ERROR;
    }

    char* query_end = query_text;

    // time_t -> string
    char timestamp_buffer[80];
    strftime(timestamp_buffer, 80,"%F %T", localtime(&observation->timestamp));
    engine_trace(TRACE_LEVEL_ALWAYS,
                "TIMESTAMP [%ld] => DATE [%s]",
                observation->timestamp, timestamp_buffer);

    query_end += snprintf(query_end,
        DB_MAX_SQL_QUERY_LEN,
        "INSERT INTO observations (drone, event, time) VALUES(%d, %d, '%s')",
        observation->drone_id,
        observation->event_id,
        timestamp_buffer);

    // run it
    if (mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }
    else
    {
        // Get auto-increment ID
        observation->id = mysql_insert_id(connection->hndl);

        engine_trace(TRACE_LEVEL_ALWAYS,
            "Observation [%d, %d, %d, %s] inserted into DB",
            observation->id,
            observation->drone_id,
            observation->event_id,
            timestamp_buffer);
    }


    return result;
}

/** ****************************************************************************

    @brief          Deletes all observations for a given event

    @param[in]      Input event ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_event_observations(int event_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

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
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "[%d] observations for EVENT_ID [%d] deleted from DB",
                mysql_affected_rows(connection->hndl),
                event_id);
        }
    }

    return result;
}


/** ****************************************************************************

    @brief          Generates observations for any drone at the same orbit than current
                    drone (event drone)

    @param[in]      Input event ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_local_observations(Event_t *event)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        // Sanity check
        if(!event)
            return ENGINE_INTERNAL_ERROR;
    }


    if(result == ENGINE_OK)
    {
        snprintf(query_text,
                DB_MAX_SQL_QUERY_LEN,
                "SELECT agent_id FROM agents WHERE object_id = (SELECT object_id FROM agents where agent_id = %d);",
                event->drone_id);

        // run it
        if (mysql_query(connection->hndl, query_text))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    MYSQL_RES* db_result = NULL;
    int rowsNum = 0;

    if(result == ENGINE_OK)
    {
        // retrieve the results and delete one by one
        db_result = mysql_store_result(connection->hndl);

        if(db_result == NULL)
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get local drones for drone_id [%d] (invalid result for query [%s])",
                event->drone_id,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((rowsNum=mysql_num_rows(db_result)) <= 0)
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "None local drone found for drone_id [%d]", event->drone_id);
        }
    }

    if((result == ENGINE_OK) && rowsNum)
    {
        // iterate results and insert observations entry for each one
        for(int droneId=0; droneId < rowsNum; droneId++)
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row)
            {
                Observation_t observation;
                memset(&observation, 0, sizeof(observation));

                // Fill observation fields
                observation.event_id = event->event_id;
                observation.drone_id = row[0]?atoi(row[0]):0;
                observation.timestamp = time(NULL); // They are at the same object
                if(observation.drone_id != event->drone_id) {
                    db_insert_observation(&observation);
                }
            }
            else
            {
                // unexpected error - abort
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get row for query [%s] with [%d] rows",
                    query_text,
                    rowsNum);

                result = ENGINE_DB_QUERY_ERROR;

                break; // stop for
            }
        }
    }

    if(db_result)
        mysql_free_result(db_result);

    return result;
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

    DbConnection_t* connection =  engine_get_db_connection();

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
            DB_MAX_SQL_QUERY_LEN,
            "SELECT observable, reportable from protocols where id = (select protocol from actions where id = %d)",
            event->action_id);

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

            if((db_result == NULL) || (rowsNum != 1) || (fieldsNum != 2))
            {
                engine_trace(TRACE_LEVEL_ALWAYS,
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

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "EVENT_ID [%d] OBSERVABLE [%d] REPORTABLE [%d]",
                        event->event_id,
                        protocol->observable,
                        protocol->reportable);
                }
                else
                {
                    engine_trace(TRACE_LEVEL_ALWAYS,
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
ErrorCode_t db_get_object_info_by_name_x(char* name, ObjectOrbitInfo_t* object)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

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
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            // retrieve the result and check that is an only row with a single field
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);

            if((db_result == NULL) || (mysql_num_rows(db_result) != 1) ||
                (mysql_num_fields(db_result) != MAX_OBJECT_FIELDS))
            {
                engine_trace(TRACE_LEVEL_ALWAYS,
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
                    object->epoch = db_date_to_timestamp(row[EPOCH_IDX], OBJECTS_TIMESTAMP_FORMAT);

                    if(!object->epoch) {
                        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to get EPOCH info for [%s]", name);
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

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "NAME [%s] TYPE [%s] obtained for OBJECT_ID [%d]",
                        object->object_name, object->object_type, object->object_id);
                }
                else
                {
                    engine_trace(TRACE_LEVEL_ALWAYS,
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
ErrorCode_t db_get_orbit_info_x(ObjectOrbitInfo_t* object)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

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
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            // retrieve the result and check that is an only row with a single field
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);

            if((db_result == NULL) || (mysql_num_rows(db_result) != 1) ||
                (mysql_num_fields(db_result) != MAX_OBJECT_FIELDS))
            {
                engine_trace(TRACE_LEVEL_ALWAYS,
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
                    object->epoch = db_date_to_timestamp(row[EPOCH_IDX], OBJECTS_TIMESTAMP_FORMAT);

                    if(!object->epoch) {
                        engine_trace(TRACE_LEVEL_ALWAYS,
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

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "NAME [%s] TYPE [%s] obtained for OBJECT_ID [%d]",
                        object->object_name, object->object_type, object->object_id);
                }
                else
                {
                    engine_trace(TRACE_LEVEL_ALWAYS,
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

    DbConnection_t* connection =  engine_get_db_connection();

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
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
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

    DbConnection_t* connection = engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);
    MYSQL_RES* db_result = NULL;
    int fieldsNum = 0;
    uint64_t rowsNum = 0;

    engine_trace(TRACE_LEVEL_ALWAYS, "Getting resources for droneID [%d]", droneId);

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
            "select  resources.id as resource_id, resources.name as name, events.locked as locked, resources.mass as mass, events.new_quantity as new_quantity, events.id as event_id  from events left join resources on events.resource = resources.id  left join event_types on event_types.id = events.event_type  join (select max(events.id) as id from events where drone = %d and events.outcome = 1 and (events.event_type = 1 or events.event_type = 2) group by events.resource ORDER BY id DESC) as events_latest on events_latest.id = events.id   ORDER BY new_quantity;",
            droneId);

        engine_trace(TRACE_LEVEL_ALWAYS, "Running query [%s]", query_text);

        if (mysql_query(connection->hndl, query_text))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if(result == ENGINE_OK)
    {
        // retrieve the results
        db_result = mysql_store_result(connection->hndl);

        if(db_result == NULL)
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get drone resources for DRONE_ID [%d] "
                "(invalid result for query [%s])",
                droneId,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((fieldsNum=mysql_num_fields(db_result)) != MAX_DRONE_RESOURCE_FIELDS)
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
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
            engine_trace(TRACE_LEVEL_ALWAYS,
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
                engine_trace(TRACE_LEVEL_ALWAYS,
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
                engine_trace(TRACE_LEVEL_ALWAYS,
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
