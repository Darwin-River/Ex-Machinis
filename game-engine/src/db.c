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
#include "engine.h"
#include "vm.h"

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

/** ****************************************************************************

  @brief          Compares two email subjects to determine if we are on the same email chain

  @param[in]      last_subject    Last email subject
  @param[in]      current_subject Current email subject

  @return         Boolean result (ENGINE_TRUE: we are on the same chain, ENGINE_FALSE: new chain)

*******************************************************************************/
Bool_t db_is_same_email_chain(char* last_subject, char* current_subject)
{
    Bool_t is_same = ENGINE_FALSE;

    // input pointers checked at calling function

    // If both are the same, no doubt - assume the same
    if(!strcmp(last_subject, current_subject))
    {
        is_same = ENGINE_TRUE;
    }
    else
    {
        // Check if it is a reply first
        char* reply_pos = strstr(current_subject, "Re:");

        if(reply_pos)
        {
            // Check now if our current subject is the same than last one
            reply_pos += (strlen("Re:"));
            char* subject_pos = strstr(reply_pos, last_subject);

            if(subject_pos)
            {
                is_same = ENGINE_TRUE;
            }
        }
    }    

    engine_trace(TRACE_LEVEL_ALWAYS, 
        "Last subject [%s], current [%s] same [%d]", 
        last_subject,
        current_subject,
        is_same);    

    return is_same;
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

    // Initialize out command
    memset(command, 0, sizeof(Command_t));
    command->command_id = -1;

	snprintf(query_text, 
        DB_MAX_SQL_QUERY_LEN, 
        "SELECT id, code, agent_id, subject, email_content FROM commands ORDER BY ID LIMIT 1");

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
                sprintf(command->subject, "%s", row[3]);
                command->email_content = engine_malloc(strlen(row[4])+1);
                sprintf(command->email_content, "%s", row[4]);

                engine_trace(TRACE_LEVEL_ALWAYS,
                	"Command info read from DB: CMD_ID=[%d] COMMAND=[%s] AGENT_ID=[%d] SUBJECT=[%s]",
                	command->command_id,
                	command->code,
                	command->agent_id,
                    command->subject);

                engine_trace(TRACE_LEVEL_ALWAYS,
                    "Command info read from DB: EMAIL_CONTENT=[%s]",
                    command->email_content);
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

    @brief          Inserts given command info into DB

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Command info, command to be inserted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_command(DbConnection_t* connection, Command_t* command)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        // Sanity check
        if(!command || !command->code || !command->email_content)
            return ENGINE_INTERNAL_ERROR;
    }

    char* query_end = query_text;

    query_end += snprintf(query_end, 
        DB_MAX_SQL_QUERY_LEN, 
        "INSERT INTO commands (code, agent_id, created_at, updated_at, subject, email_content) VALUES('");
    
    query_end += mysql_real_escape_string(connection->hndl, query_end, command->code, strlen(command->code));
    query_end += snprintf(query_end, 
        DB_MAX_SQL_QUERY_LEN, 
        "', %d, NOW(), NOW(), '", 
        command->agent_id);

    query_end += mysql_real_escape_string(connection->hndl, query_end, command->subject, strlen(command->subject));
    query_end += snprintf(query_end, 
        DB_MAX_SQL_QUERY_LEN, 
        "', '");

    query_end += mysql_real_escape_string(connection->hndl, query_end, command->email_content, strlen(command->email_content));
    query_end += snprintf(query_end, 
        DB_MAX_SQL_QUERY_LEN, 
        "')");

    // run it 
    if (mysql_query(connection->hndl, query_text)) 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }
    else 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Command [%s] inserted into DB", command->code);
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

    if(result == ENGINE_OK)
    {
        // Treat no more commands as OK
        if(!command || command->command_id < 0)
            return ENGINE_OK;
    }

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

    @brief          Deletes all resume commands currently stored in DB for a given user

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  agent_id  Current agent ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_resume_commands(DbConnection_t* connection, int agent_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        snprintf(query_text, 
            DB_MAX_SQL_QUERY_LEN, 
            "DELETE FROM commands WHERE AGENT_ID = %d and code = '%s'", 
            agent_id, engine_get_vm_resume_command());

        // run it 
        if (mysql_query(connection->hndl, query_text)) 
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else 
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "Resume commands for agent [%d] deleted from DB", agent_id);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Checks if there is any pending/paused command already running
                    for this agent

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  agent_id  Current agent ID

    @return         Checking result boolean

*******************************************************************************/
Bool_t db_agent_has_running_command(DbConnection_t* connection, int agent_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    Bool_t has_running_command = ENGINE_TRUE;

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        snprintf(query_text, 
            DB_MAX_SQL_QUERY_LEN, 
            "SELECT count(*) from commands WHERE AGENT_ID = %d and code = '%s'", 
            agent_id, engine_get_vm_resume_command());

        // run it 
        if (mysql_query(connection->hndl, query_text)) 
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        }
        else 
        {
            // Result got, check how many commands we obtained
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);

            if(db_result == NULL)  
            {
                engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to get [%s] result", query_text);
            } 
            else
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row) 
                {
                    // Get the number
                    int pending_commands = atoi(row[0]);

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "[%d] pending commands for agent [%d]",
                        pending_commands, agent_id);

                    if(pending_commands <= 0) 
                    {
                        has_running_command = ENGINE_FALSE;
                    }

                    mysql_free_result(db_result);
                }
                else
                {
                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "ERROR: Unable to get row for [%s] query", 
                        query_text);
                }
            }
        }
    }

    return has_running_command;
}

/** ****************************************************************************

    @brief          Gets current agent VM and other info required by the engine

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose VM data we want to get 
                    (we use current if any or create a new one)
    @param[in|out]  Output info obtained for input agent

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_agent_engine_info(DbConnection_t* connection, int agent_id, AgentInfo_t* agent)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    snprintf(query_text, 
        DB_MAX_SQL_QUERY_LEN, 
        "SELECT VM, object_id FROM agents WHERE AGENT_ID = %d", agent_id);

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
                "ERROR: Unable to get info for agent [%d] (invalid result)",
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
                    agent->vm = vm_new(agent_id);
                }
                else
                {
                    size_t vm_size = mysql_fetch_lengths(db_result)[0];

                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "VM found for agent [%d] with size [%ld] bytes", 
                        agent_id, vm_size);

                    // Convert VM bytes into VM object
                    agent->vm = vm_from_bytes(row[0], vm_size);
                }

                // Store also the objectID
                agent->object_id = atoi(row[1]);

                engine_trace(TRACE_LEVEL_ALWAYS, 
                        "Agent [%d] has object ID [%d]", 
                        agent_id, agent->object_id);
            }
            else 
            {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "ERROR: Unable to get info for agent [%d] (no row)", agent_id);

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
        "' WHERE AGENT_ID = %d", 
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
    @param[in]      Agent ID whose output we want to update
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
    char query_text[strlen(msg) * 2 + 1 + 64]; // enough buffer
    size_t buffer_size = strlen(msg)*2 + 1;
    char* scaped_msg = engine_malloc(buffer_size);

    db_scape_string(connection, msg, strlen(msg), scaped_msg, buffer_size);

    snprintf(query_text, 
        DB_MAX_SQL_QUERY_LEN, 
        "UPDATE agents SET OUTPUT = '%s' where AGENT_ID = %d",
        scaped_msg,
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
            "Updated VM output for agent [%d] with value [%s]", agent_id, msg);
    }

    engine_free(scaped_msg, buffer_size);

    return result;
}

/** ****************************************************************************

    @brief          Updates latest input output in DB

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose input we want to update
    @param[in]      Command information including msg and subject

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_agent_input(DbConnection_t* connection, int agent_id, Command_t* command)
{
    char new_subject[MAX_COMMAND_CODE_SIZE+1];
    char* new_input = NULL;

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // ignore null msg
    if(!command) return ENGINE_INTERNAL_ERROR;

    // Get firstly the current agent info
    AgentInfo_t agent_info;
    memset(&agent_info, 0, sizeof(agent_info));
    agent_info.agent_id = agent_id;
    result = db_get_agent_info(connection, &agent_info);

    if(result == ENGINE_OK) 
    {
        // Check current subject to see if it is a new email chain or not
        Bool_t same_email_chain = db_is_same_email_chain(agent_info.subject, command->subject);
        if(same_email_chain == ENGINE_TRUE) 
        {
            // Keep subject and update input content
            sprintf(new_subject, "%s", agent_info.subject);
            new_input = command->email_content;
        }
        else
        {
            // New subject and input content
            sprintf(new_subject, "%s", command->subject);
            new_input = command->email_content;
        }
    }

    if(result == ENGINE_OK) 
    {
        // Prepare query to update input and subject
        size_t query_size = strlen(new_input) + strlen(new_subject) + DB_MAX_SQL_QUERY_LEN;
        char* query_text = engine_malloc(query_size);

        size_t input_size = strlen(new_input)*2 + 1;
        char* scaped_input = engine_malloc(input_size);

        db_scape_string(connection, new_input, strlen(new_input), scaped_input, input_size);

        snprintf(query_text, 
            query_size, 
            "UPDATE agents SET input = '%s', subject = '%s' where agent_id = %d",
            scaped_input,
            new_subject,
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
                "Updated VM info for agent [%d] with input [%s] subject [%s]", 
                agent_id, new_input, new_subject);
        }

        engine_free(query_text, query_size);
        engine_free(scaped_input, input_size);
    }

    if(agent_info.input_content)engine_free(agent_info.input_content, strlen(agent_info.input_content)+1);

    return result;
}

/** ****************************************************************************

    @brief          Gets user ID and agent name for a given agent ID

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Output parameter where we store whole agent info and receive agent ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_agent_info(DbConnection_t* connection, AgentInfo_t* agent_info)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!agent_info) 
            return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        snprintf(query_text, 
            DB_MAX_SQL_QUERY_LEN, 
            "SELECT user_id, name, subject, input FROM agents WHERE agent_id = %d", agent_info->agent_id);

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
                (mysql_num_fields(db_result) != 4))
            {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "ERROR: Unable to get info for agent [%d] (invalid result)",
                    agent_info->agent_id);

                result = ENGINE_DB_QUERY_ERROR;
            } 
            else 
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row) 
                {
                    // store result - strcpy safely/same size
                    agent_info->user_id = atoi(row[0]);
                    sprintf(agent_info->agent_name, "%s", row[1]);
                    sprintf(agent_info->subject, "%s", row[2]?row[2]:"");
                    if(row[3]) {
                        agent_info->input_content = engine_malloc(strlen(row[3])+1);
                    } else {
                        agent_info->input_content = engine_malloc(1);
                    }
                    sprintf(agent_info->input_content, "%s", row[3]?row[3]:"");

                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "USER_ID [%d] NAME [%s] SUBJECT [%s] INPUT [%s] obtained for agent [%d]", 
                        agent_info->user_id, agent_info->agent_name, agent_info->subject, 
                        agent_info->input_content, agent_info->agent_id);
                }
                else 
                {
                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "ERROR: Unable to get info for agent [%d] (no row)", agent_info->agent_id);

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
ErrorCode_t db_get_user_info(DbConnection_t* connection, int user_id, char* email, char* name)
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
            "SELECT email, name FROM users WHERE user_id = %d", user_id);

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
    AgentInfo_t agent_info;

    if(result == ENGINE_OK)
    {
        // ignore null msg
        if(!email_info) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    { 
        // Get agent user ID first
        memset(&agent_info, 0, sizeof(agent_info));
        agent_info.agent_id = email_info->agent_id;

        result = db_get_agent_info(connection, &agent_info);
    }

    if(result == ENGINE_OK)
    { 
        // Get email info from agent info + user
        email_info->user_id = agent_info.user_id;
        strcpy(email_info->agent_name, agent_info.agent_name);
        strcpy(email_info->subject, agent_info.subject);
        email_info->input_content = agent_info.input_content;
    }

    if(result == ENGINE_OK)
    { 
        // Get now email and name
        result = db_get_user_info(connection,
                                  email_info->user_id, 
                                  email_info->user_email_addr,
                                  email_info->user_name);
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


/** ****************************************************************************

    @brief          Gets orbit info for a given object ID

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Output parameter where we store the orbit info obtained from DB
                    This object contains also current object ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_orbit_info(DbConnection_t* connection, ObjectOrbitInfo_t* object)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

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

    @brief          Gets earth orbit info

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  name Object name we are looking for
    @param[in|out]  Output parameter where we store the orbit info obtained from DB
                    This object contains also current object ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_object_info_by_name(DbConnection_t* connection, char* name, ObjectOrbitInfo_t* object)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

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

    @brief          Gets protocol info for a given protocol ID 
                    (received in IN/OUT protocol parameter)

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Output parameter where we store the protocol info obtained
                    This object contains also current protocol ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_prococol_info(DbConnection_t* connection, ProtocolInfo_t* protocol)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!protocol) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        snprintf(query_text, 
            DB_MAX_SQL_QUERY_LEN, 
            "SELECT * FROM protocols WHERE id = %d", protocol->protocol_id);

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
                (mysql_num_fields(db_result) != MAX_PROTOCOL_FIELDS))
            {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "ERROR: Unable to get protocols info for PROTOCOL_ID [%d] "
                    "(invalid result for query [%s])",
                    protocol->protocol_id,
                    query_text);

                result = ENGINE_DB_QUERY_ERROR;
            } 
            else 
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row) 
                {
                    // Pick the fields we need
                    sprintf(protocol->protocol_name, "%s", row[PROTOCOL_NAME_IDX]?row[PROTOCOL_NAME_IDX]:"");
                    protocol->parameters_num = row[PROTOCOL_PARAMETERS_IDX]?atoi(row[PROTOCOL_PARAMETERS_IDX]):0;
                    protocol->bulk_multiplier = row[PROTOCOL_BULK_MODIFIER_IDX]?atoi(row[PROTOCOL_BULK_MODIFIER_IDX]):-1;
                    sprintf(protocol->protocol_description, "%s", row[PROTOCOL_DESCRIPTION_IDX]?row[PROTOCOL_DESCRIPTION_IDX]:"");

                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "NAME [%s] DESCRIPTION [%s] BULK_MODIFIER [%d] obtained for PROTOCOL_ID [%d]", 
                        protocol->protocol_name, 
                        protocol->protocol_description, 
                        protocol->bulk_multiplier, 
                        protocol->protocol_id);
                }
                else 
                {
                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "ERROR: Unable to get protocol info for PROTOCOL_ID [%d] (no row)", 
                        protocol->protocol_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}


/** ****************************************************************************

    @brief          Inserts a new action entry

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Action info to be inserted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_action(DbConnection_t* connection, Action_t* action)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        // Sanity check
        if(!action)
            return ENGINE_INTERNAL_ERROR;
    }

    char* query_end = query_text;

    query_end += snprintf(query_end, 
        DB_MAX_SQL_QUERY_LEN, 
        "INSERT INTO actions (drone, protocol, multiplier, aborted) VALUES(%d, %d, %d, %d)",
        action->drone_id,
        action->protocol_id,
        action->process_multiplier,
        action->aborted);

    // run it 
    if (mysql_query(connection->hndl, query_text)) 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }
    else 
    {
        // Get auto-increment ID
        action->action_id = mysql_insert_id(connection->hndl);

        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Action [%d, %d, %d, %d, %d] inserted into DB",
            action->action_id,
            action->drone_id,
            action->protocol_id,
            action->process_multiplier,
            action->aborted);
    }


    return result;
}

/** ****************************************************************************

    @brief          Aborts an action using its ID

    @param[in|out]  Connection info, updated once disconnected

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_abort_action(DbConnection_t* connection, int action_id)
{  
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

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