/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: DB interface module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include "db.h"
#include "trace.h"
#include "engine.h"

/******************************* DEFINES *************************************/

#define DB_MAX_SQL_QUERY_LEN 1024

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

/** ****************************************************************************

  @brief          Gets the next command

  @param[in|out]  Connection info, updated once disconnected

  @return         void

*******************************************************************************/
ErrorCode_t db_get_next_command(DbConnection_t* connection)
{
	char query_text[DB_MAX_SQL_QUERY_LEN+1];

	// always check connection is alive
	ErrorCode_t result = db_reconnect(connection);

	snprintf(query_text, 
        DB_MAX_SQL_QUERY_LEN, 
        "SELECT * FROM COMMANDS ORDER BY ID LIMIT 1");

    // run it 
    if (mysql_query(connection->hndl, query_text)) 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }
    else 
    {
        // retrieve the result
        MYSQL_RES* db_result = mysql_store_result(connection->hndl);

        if(db_result == NULL)  
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to get next command");
            result = ENGINE_DB_QUERY_ERROR;
        } 
        else 
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row) 
            {
                engine_trace(TRACE_LEVEL_ALWAYS,
                	"Command info read from DB: CMD_ID=[%d] COMMAND=[%s] AGENT_ID=[%d]",
                	atoi(row[0]),
                	row[1],
                	atoi(row[2]));
            }
            else 
            {
            	engine_trace(TRACE_LEVEL_ALWAYS,
            		"ERROR: Unable to get next command");

                result = ENGINE_DB_QUERY_ERROR;
            }
        }

        mysql_free_result(db_result);
    }


	return result;
}

