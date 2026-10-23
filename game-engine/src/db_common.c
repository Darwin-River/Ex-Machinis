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

    @brief          Adds columns newer engines need to databases created
                    before them: actions.outcome (why an action failed),
                    agents.waiting_action (the action a paused script waits
                    for), commands.deliver_at, and the game_clock and outbox
                    tables

    @return         Execution result

*******************************************************************************/
/* Adds a column when the table lacks it */
static ErrorCode_t db_add_column(DbConnection_t* connection, const char* table, const char* column, const char* definition)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    int missing = 0;

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT COUNT(*) FROM information_schema.columns "
        "WHERE table_schema = DATABASE() AND table_name = '%s' AND column_name = '%s'", table, column);

    if(mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        return ENGINE_DB_QUERY_ERROR;
    }

    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    MYSQL_ROW row = db_result ? mysql_fetch_row(db_result) : NULL;
    missing = (row && row[0] && atoi(row[0]) == 0);
    if(db_result) mysql_free_result(db_result);

    if(!missing)
        return ENGINE_OK;

    engine_trace(TRACE_LEVEL_ALWAYS, "Adding the %s.%s column", table, column);
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN, "ALTER TABLE %s ADD COLUMN %s %s", table, column, definition);
    return db_exec(connection, query_text);
}

ErrorCode_t db_upgrade_schema(void)
{
    DbConnection_t* connection = engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
        result = db_add_column(connection, "actions", "outcome", "INT NULL");

    if(result == ENGINE_OK)
        result = db_add_column(connection, "agents", "waiting_action", "INT UNSIGNED NULL");

    // Signals travel at the speed of light: when a player's command reaches
    // its drone (unix seconds), and replies waiting to reach Earth
    if(result == ENGINE_OK)
        result = db_add_column(connection, "commands", "deliver_at", "BIGINT NULL");

    if(result == ENGINE_OK)
        result = db_exec(connection, GAME_CLOCK_TABLE_SQL);

    if(result == ENGINE_OK)
        result = db_exec(connection, OUTBOX_TABLE_SQL);

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
