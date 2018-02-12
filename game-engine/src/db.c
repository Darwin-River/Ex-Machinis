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
    @param[in|out]  Command info, command read is stored here once read.

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_next_command(DbConnection_t* connection, Command_t* command)
{
	char query_text[DB_MAX_SQL_QUERY_LEN+1];

	// always check connection is alive
	ErrorCode_t result = db_reconnect(connection);

	snprintf(query_text, 
        DB_MAX_SQL_QUERY_LEN, 
        "SELECT ID, CODE, AGENT_ID FROM commands ORDER BY ID LIMIT 1");

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
                // Read command information
                command->command_id = atoi(row[0]);
                command->agent_id = atoi(row[2]);
                sprintf(command->code, "%s", row[1]);

                engine_trace(TRACE_LEVEL_ALWAYS,
                	"Command info read from DB: CMD_ID=[%d] COMMAND=[%s] AGENT_ID=[%d]",
                	command->command_id,
                	command->code,
                	command->agent_id);
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

/** ****************************************************************************

    @brief          Deletes a given command once processed

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Command info, command to be deleted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_command(DbConnection_t* connection, Command_t* command)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    snprintf(query_text, 
        DB_MAX_SQL_QUERY_LEN, 
        "DELETE FROM commands WHERE ID = %d", command->command_id);

    // run it 
    if (mysql_query(connection->hndl, query_text)) 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }
    else 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Command [%d] deleted from DB", command->command_id);
    }


    return result;
}

/** ****************************************************************************

    @brief          Gets current agent VM

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose VM data we want to get 
                    (we use current if any or create a new one)

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_agent_vm(DbConnection_t* connection, int agent_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    snprintf(query_text, 
        DB_MAX_SQL_QUERY_LEN, 
        "SELECT VM FROM agents WHERE AGENT_ID = %d", agent_id);

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
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "ERROR: Unable to get VM for agent [%d]", agent_id);

            result = ENGINE_DB_QUERY_ERROR;
        } 
        else 
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row) 
            {
                // Check if it is NULL or not

                // When NULL create a new one and return it
            }
            else 
            {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "ERROR: Unable to get VM for agent [%d] (no results)", agent_id);

                result = ENGINE_DB_QUERY_ERROR;
            }
        }

        mysql_free_result(db_result);
    }


    return result;
}

/** ****************************************************************************

    @brief          Gets current agent VM

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose VM we want to update
    @param[in]      VM data to be updated in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_save_agent_vm(DbConnection_t* connection, int agent_id, unsigned char* data)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    snprintf(query_text, 
        DB_MAX_SQL_QUERY_LEN, 
        "UPDATE agents SET VM = %s WHERE AGENT_ID = %d", (char*)data, agent_id);

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
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "ERROR: Unable to update VM for agent [%d]", agent_id);

            result = ENGINE_DB_QUERY_ERROR;
        } 
        else 
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "Updated VM for agent [%d]", agent_id);
        }

        mysql_free_result(db_result);
    }


    return result;
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

