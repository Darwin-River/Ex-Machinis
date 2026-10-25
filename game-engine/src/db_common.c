/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: DB interface module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#define _XOPEN_SOURCE
#include <stdlib.h>
#include <time.h>
#include <string.h>

#include "db.h"
#include "game_time.h"
#include "trace.h"
#include "engine.h"
#include "vm.h"

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
