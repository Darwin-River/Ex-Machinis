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

#define DB_MAX_SQL_QUERY_LEN (ENGINE_MAX_BUF_SIZE + 1024) // Max engine buffer + extra bytes for query statement

/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief          Checks connection status and reconnects when necessary

  @param[in|out]  Connection info

  @return         void

*******************************************************************************/
ErrorCode_t db_reconnect(DbConnection_t* connection)
{
	ErrorCode_t result = ENGINE_OK;

	if(connection && connection->hndl) {
		// Check connection status & reconnect if required
		if(mysql_stat(connection->hndl) == NULL) 
		{
			engine_trace(TRACE_LEVEL_ALWAYS,
        		"WARNING: Connection lost with [%s] database, reconnecting...",
        		connection->db_name?connection->db_name:"NULL");

			result = db_init(connection);
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
            "SELECT * FROM events WHERE outcome = 0 limit %d;", 
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

        if(db_result == NULL)
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "ERROR: Unable to get outcome events (invalid result for query [%s])",
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((rowsNum=mysql_num_rows(db_result)) <= 0) 
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
                event.new_quantity = row[EVENT_NEW_QUANTITY_IDX]?atoi(row[EVENT_NEW_CREDITS_IDX]):0;
                event.new_credits = row[EVENT_NEW_LOCATION_IDX]?atoi(row[EVENT_NEW_LOCATION_IDX]):0;
                event.new_location = row[EVENT_NEW_LOCATION_IDX]?atoi(row[EVENT_NEW_LOCATION_IDX]):0;
                event.new_transmission = row[EVENT_NEW_TRANSMISSION_IDX]?atoi(row[EVENT_NEW_TRANSMISSION_IDX]):0;
                event.new_cargo = row[EVENT_NEW_CARGO_IDX]?atoi(row[EVENT_NEW_CARGO_IDX]):0;
                event.timestamp = row[EVENT_TIMESTAMP_IDX]?atoi(row[EVENT_TIMESTAMP_IDX]):0;

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
                event.new_quantity = row[EVENT_NEW_QUANTITY_IDX]?atoi(row[EVENT_NEW_QUANTITY_IDX]):0;
                event.new_location = row[EVENT_NEW_LOCATION_IDX]?atoi(row[EVENT_NEW_LOCATION_IDX]):0;
                event.new_transmission = row[EVENT_NEW_TRANSMISSION_IDX]?atoi(row[EVENT_NEW_TRANSMISSION_IDX]):0;
                event.new_cargo = row[EVENT_NEW_CARGO_IDX]?atoi(row[EVENT_NEW_CARGO_IDX]):0;
                event.timestamp = row[EVENT_TIMESTAMP_IDX]?atoi(row[EVENT_TIMESTAMP_IDX]):0;

                if(db_delete_event(event.event_id) == ENGINE_OK) {
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

    @brief          Deletes event giving event_id

    @param[in]      Input event ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_event(int event_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        snprintf(query_text, 
            DB_MAX_SQL_QUERY_LEN, 
            "DELETE FROM events WHERE event_id = %d", 
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
                "EVENT_ID [%d] deleted from DB", 
                event_id);
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

        // Build the query depending on filter type
        switch(filter) {
            case PREVIOUS_EVENT_BY_RESOURCE_INFO:
                snprintf(query_text, 
                    DB_MAX_SQL_QUERY_LEN, 
                    "SELECT * "
                    "FROM events "
                    "WHERE drone = %d and resource = %d and installed = %d and locked = %d "
                    "and timestamp < FROM_UNIXTIME(%ld) order by timestamp DESC limit 1;",  //
                    event->drone_id, event->resource_id, event->installed, event->locked, event->timestamp);
                break;
            case PREVIOUS_EVENT_BY_DRONE:
                snprintf(query_text, 
                    DB_MAX_SQL_QUERY_LEN, 
                    "SELECT * "
                    "FROM events "
                    "WHERE drone = %d "
                    "and timestamp < FROM_UNIXTIME(%ld) order by timestamp DESC limit 1;", 
                    event->drone_id, event->timestamp);
                break;
            case PREVIOUS_EVENT_BY_OWNER:
                snprintf(query_text, 
                    DB_MAX_SQL_QUERY_LEN, 
                    "SELECT * from events where drone in "
                    "(select agent_id from agents where user_id = (select user_id from agents where agent_id = %d)) "
                    "and timestamp < FROM_UNIXTIME(%ld) order by timestamp DESC limit 1;", 
                    event->drone_id, event->timestamp);
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
            result = ENGINE_LOGIC_ERROR; // Indicates that no entry was found
        }
    }  

    if((result == ENGINE_OK) && rowsNum)
    {  
        engine_trace(TRACE_LEVEL_ALWAYS, "[%d] previous event found");

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
            out_event->new_quantity = row[EVENT_NEW_QUANTITY_IDX]?atoi(row[EVENT_NEW_QUANTITY_IDX]):0;
            out_event->new_location = row[EVENT_NEW_LOCATION_IDX]?atoi(row[EVENT_NEW_LOCATION_IDX]):0;
            out_event->new_transmission = row[EVENT_NEW_TRANSMISSION_IDX]?atoi(row[EVENT_NEW_TRANSMISSION_IDX]):0;
            out_event->new_cargo = row[EVENT_NEW_CARGO_IDX]?atoi(row[EVENT_NEW_CARGO_IDX]):0;
            out_event->timestamp = row[EVENT_TIMESTAMP_IDX]?atoi(row[EVENT_TIMESTAMP_IDX]):0;
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
            "SELECT * from actions where action_id = %d",
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

            if((db_result == NULL) || (mysql_num_rows(db_result) != 1) ||  
                (mysql_num_fields(db_result) != MAX_ACTION_FIELDS))
            {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "ERROR: Unable to get action info for ACTION_ID [%d] "
                    "(invalid result for query [%s])",
                    action->action_id,
                    query_text);

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
                        action->protocol_id,
                        action->aborted);
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
