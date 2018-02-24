/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: DB interface module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>

#include "db.h"
#include "trace.h"
#include "engine.h"
#include "vm.h"

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
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to get next command (no more results)");
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
            		"WARNING: No more commands to process");

                result = ENGINE_DB_NOT_FOUND_ERROR;
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
    @param[in|out]  Output address where engine is stored when success

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_agent_vm(DbConnection_t* connection, int agent_id, VirtualMachine_t** vm)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    snprintf(query_text, 
        DB_MAX_SQL_QUERY_LEN, 
        "SELECT VM FROM agents WHERE ID = %d", agent_id);

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
            (mysql_num_fields(db_result) != 1))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "ERROR: Unable to get VM for agent [%d] (invalid result)",
                agent_id);

            result = ENGINE_DB_QUERY_ERROR;
        } 
        else 
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row) 
            {
                // Check if agent has a VM or new one must be created
                if(row[0] == NULL)
                {
                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "WARNING: None VM defined for agent [%d], new one created",
                        agent_id);

                    // Create a new VM object
                    *vm = vm_new(agent_id);
                }
                else
                {
                    size_t vm_size = mysql_fetch_lengths(db_result)[0];

                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "VM found for agent [%d] with size [%ld] bytes", 
                        agent_id, vm_size);

                    // Convert VM bytes into VM object
                    *vm = vm_from_bytes(row[0], vm_size);
                }
            }
            else 
            {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "ERROR: Unable to get VM for agent [%d] (no row)", agent_id);

                result = ENGINE_DB_QUERY_ERROR;
            }
        }

        mysql_free_result(db_result);
    }


    return result;
}

/** ****************************************************************************

    @brief          Saves current agent VM in DB

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose VM we want to update
    @param[in]      vm  VM object

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_save_agent_vm(DbConnection_t* connection, int agent_id, VirtualMachine_t* vm)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // Serialize VM into bytes
    size_t vm_size = 0;
    char* vm_data = vm_to_bytes(vm, &vm_size);

    if(!vm_data || !vm_size)
    {
        return ENGINE_FORTH_SERIALIZE_ERROR;
    }

    // Query must have enough room for whole VM bytes scaped
    // Add 3 parts of the query:
    // - Start of the statement
    // - Binary data
    // - End of the statement
    char query_text[DB_MAX_SQL_QUERY_LEN + vm_size*2 + 1];
    char* query_end = query_text;

    query_end += snprintf(query_end, 
        DB_MAX_SQL_QUERY_LEN, 
        "UPDATE agents SET VM = '");
    
    query_end += mysql_real_escape_string(connection->hndl, query_end, vm_data, vm_size);

    query_end += snprintf(query_end, 
        DB_MAX_SQL_QUERY_LEN, 
        "' WHERE ID = %d", 
        agent_id);

    // run it 
    if (mysql_query(connection->hndl, query_text)) 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }
    else 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Updated VM for agent [%d]", agent_id);
    }

    // Deallocate dynamic memory
    if(vm_data)
    {
        free(vm_data);
        vm_data = NULL;
    }

    return result;
}

/** ****************************************************************************

    @brief          Updates latest command output in DB

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose VM we want to update
    @param[in]      Output msg we need to update

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_agent_output(DbConnection_t* connection, int agent_id, char* msg)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // ignore null msg
    if(!msg) return ENGINE_INTERNAL_ERROR;

    // Prepare query
    char query_text[DB_MAX_SQL_QUERY_LEN + 1];

    snprintf(query_text, 
        DB_MAX_SQL_QUERY_LEN, 
        "UPDATE agents SET OUTPUT = '%s'",
        msg);

    // run it 
    if (mysql_query(connection->hndl, query_text)) 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }
    else 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Updated VM output for agent [%d] with value [%s]", agent_id, msg);
    }

    return result;
}

/** ****************************************************************************

    @brief          Gets company ID for a given agent ID

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose company ID we want to obtain
    @param[in|out]  Output parameter where we store the company ID once obtained

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_agent_company_id(DbConnection_t* connection, int agent_id, int* company_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!company_id) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        snprintf(query_text, 
            DB_MAX_SQL_QUERY_LEN, 
            "SELECT company_id FROM agents WHERE id = %d", agent_id);

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
                (mysql_num_fields(db_result) != 1))
            {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "ERROR: Unable to get COMPANY_ID for agent [%d] (invalid result)",
                    agent_id);

                result = ENGINE_DB_QUERY_ERROR;
            } 
            else 
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row) 
                {
                    // store result
                    *company_id = atoi(row[0]);

                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "COMPANY_ID [%d] obtained for agent [%d]", *company_id, agent_id);
                }
                else 
                {
                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "ERROR: Unable to get COMPANY_ID for agent [%d] (no row)", agent_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Gets user ID for a given agent using its company ID previously obtained

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Current agent company's ID 
    @param[in|out]  Output parameter where we store the user ID once obtained

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_agent_user_id(DbConnection_t* connection, int company_id, int* user_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!user_id) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        snprintf(query_text, 
            DB_MAX_SQL_QUERY_LEN, 
            "SELECT user_id FROM companies WHERE id = %d", company_id);

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
                (mysql_num_fields(db_result) != 1))
            {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "ERROR: Unable to get USER_ID for COMPANY_ID [%d] (invalid result)",
                    company_id);

                result = ENGINE_DB_QUERY_ERROR;
            } 
            else 
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row) 
                {
                    // store result
                    *user_id = atoi(row[0]);

                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "USER_ID [%d] obtained for COMPANY_ID [%d]", *user_id, company_id);
                }
                else 
                {
                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "ERROR: Unable to get USER_ID for COMPANY_ID [%d] (no row)", company_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Gets email and name for a given user ID

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Current user ID 
    @param[in|out]  Output parameter where we store the users's email address
    @param[in|out]  Output parameter where we store the users's name

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_agent_email_name(DbConnection_t* connection, int user_id, char* email, char* name)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!email || !name) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        snprintf(query_text, 
            DB_MAX_SQL_QUERY_LEN, 
            "SELECT email, name FROM users WHERE id = %d", user_id);

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
                (mysql_num_fields(db_result) != 2))
            {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "ERROR: Unable to get EMAIL info for USER_ID [%d] "
                    "(invalid result for query [%s])",
                    user_id,
                    query_text);

                result = ENGINE_DB_QUERY_ERROR;
            } 
            else 
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row) 
                {
                    // store results
                    strcpy(email, row[0]);
                    strcpy(name, row[1]);

                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "EMAIL [%s] NAME [%s] obtained for USER_ID [%d]", 
                        email, name, user_id);
                }
                else 
                {
                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "ERROR: Unable to get EMAIL info for USER_ID [%d] (no row)", 
                        user_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Gets email data for a given agent ID

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Agent email info, calling function supplies here the agent_id and 
                    this function fills the rest of the information querying DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_agent_email_info(DbConnection_t* connection, EmailInfo_t* email_info)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        // ignore null msg
        if(!email_info) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    { 
        // Get agent company ID first
        result = db_get_agent_company_id(connection, 
                                         email_info->agent_id,
                                         &email_info->company_id);
    }

    if(result == ENGINE_OK)
    { 
        // Get now user ID using company_id
        result = db_get_agent_user_id(connection, 
                                      email_info->company_id,
                                      &email_info->user_id);
    }

    if(result == ENGINE_OK)
    { 
        // Get now email and name
        result = db_get_agent_email_name(connection,
                                         email_info->user_id, 
                                         email_info->email_addr,
                                         email_info->agent_name);
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

