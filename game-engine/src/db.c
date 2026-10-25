/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: DB interface module

******************************************************************************/

/******************************* INCLUDES ************************************/

#define _XOPEN_SOURCE
#include <time.h>
#include <string.h>
#include <stdlib.h>
#include <math.h>

#include "db.h"
#include "game_time.h"
#include "email.h"
#include "trace.h"
#include "engine.h"
#include "vm.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

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


/** Escapes a value for use inside quotes in SQL. Free the result with free(). */
static char* db_escape_alloc(DbConnection_t* connection, const char* value)
{
    size_t len = strlen(value);
    char* out = malloc(len * 2 + 1);
    if(out)
        mysql_real_escape_string(connection->hndl, out, value, len);
    return out;
}

/******************************* PUBLIC FUNCTIONS ****************************/

/** ***************************************************************************

  @brief      Converts a given date string into timestamp value

  @param[in]  date    Input date string
  @param[in]  format  Desired format string

  @return     void

******************************************************************************/
time_t db_date_to_timestamp(char* date, char* format)
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
        engine_trace(TRACE_LEVEL_DEBUG,
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

    engine_trace(TRACE_LEVEL_DEBUG,
        "Converting date into epoch [%s] using format [%s], result = [%ld], conversion_result = [%s]",
        date,
        format,
        epoch,
        conversion_result);

    return epoch;
}

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

	// A command runs once it has reached its drone (deliver_at, see
	// db_stamp_commands). A script paused by perform resumes only once its action is done (no
	// unprocessed events, or aborted): hold its resume back until then.
	// <launched> is held while the drone's script is still running. Other
	// commands, such as <abort> or new mail, are never held.
	snprintf(query_text,
        DB_MAX_SQL_QUERY_LEN,
        "SELECT c.id, c.code, c.agent_id, c.subject, c.email_content FROM commands c "
        "LEFT JOIN agents a ON a.agent_id = c.agent_id "
        "WHERE c.deliver_at IS NOT NULL AND c.deliver_at <= %lld "  // game time (#327)
        "AND NOT (c.code LIKE '%s%%' AND a.waiting_action IS NOT NULL "
        "  AND EXISTS (SELECT 1 FROM actions x WHERE x.id = a.waiting_action AND x.aborted = 0) "
        "  AND EXISTS (SELECT 1 FROM events e WHERE e.action = a.waiting_action AND e.outcome = 0)) "
        // <launched> waits until the drone's script has finished (no resume
        // queued): a released script loads in full before launched runs
        "AND NOT (c.code = '%s' AND EXISTS (SELECT 1 FROM commands r "
        "  WHERE r.agent_id = c.agent_id AND r.id <> c.id AND r.code LIKE '%s%%')) "
        "ORDER BY c.id LIMIT 1",
        game_time_now_seconds(),
        engine_get_vm_resume_command(),
        META_COMMAND_LAUNCHED,
        engine_get_vm_resume_command());

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
                if(row[1] && strlen(row[1]) > MAX_COMMAND_CODE_SIZE)
                    engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: command [%s] is longer than [%d] characters: cut",
                        row[0], MAX_COMMAND_CODE_SIZE);
                snprintf(command->code, sizeof(command->code), "%s", row[1] ? row[1] : "");
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
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        // Sanity check
        if(!command || !command->email_content)
            return ENGINE_INTERNAL_ERROR;
    }

    // Escaping can double each value, so size the buffer from the inputs
    size_t code_len = strlen(command->code);
    size_t subject_len = strlen(command->subject);
    size_t content_len = strlen(command->email_content);
    size_t query_size = 256 + 2 * (code_len + subject_len + content_len);
    char* query_text = engine_malloc(query_size);
    if(!query_text)
        return ENGINE_INTERNAL_ERROR;

    char* query_end = query_text;
#define QUERY_LEFT (query_size - (size_t)(query_end - query_text))

    query_end += snprintf(query_end,
        QUERY_LEFT,
        "INSERT INTO commands (code, agent_id, created_at, updated_at, subject, email_content) VALUES('");

    query_end += mysql_real_escape_string(connection->hndl, query_end, command->code, code_len);
    query_end += snprintf(query_end,
        QUERY_LEFT,
        "', %d, NOW(), NOW(), '",
        command->agent_id);

    query_end += mysql_real_escape_string(connection->hndl, query_end, command->subject, subject_len);
    query_end += snprintf(query_end,
        QUERY_LEFT,
        "', '");

    query_end += mysql_real_escape_string(connection->hndl, query_end, command->email_content, content_len);
    query_end += snprintf(query_end,
        QUERY_LEFT,
        "')");
#undef QUERY_LEFT

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

    engine_free(query_text, query_size);

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

                    // Convert VM bytes into VM object assigning the current agentId
                    agent->vm = vm_from_bytes(agent_id, row[0], vm_size);
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
    char* query_text = NULL;
    size_t query_size = 0;
    ErrorCode_t result = db_reconnect(connection);
    char* query_end = NULL;
    char* vm_data = NULL;
    size_t vm_size = 0;

    // When NULL vm = reset it, otherwise it is just an update
    if(vm != NULL) {
        // Serialize VM into bytes
        vm_data = vm_to_bytes(vm, &vm_size);

        if(!vm_data || !vm_size)
        {
            return ENGINE_FORTH_SERIALIZE_ERROR;
        }

        query_size = DB_MAX_SQL_QUERY_LEN + vm_size*2 + 1;
        query_text = engine_malloc(query_size);

        // Query must have enough room for whole VM bytes scaped
        // Add 3 parts of the query:
        // - Start of the statement
        // - Binary data
        // - End of the statement
        query_end = query_text;
        query_end += snprintf(query_end,
            DB_MAX_SQL_QUERY_LEN,
            "UPDATE agents SET VM = '");

        query_end += mysql_real_escape_string(connection->hndl, query_end, vm_data, vm_size);

        query_end += snprintf(query_end,
            query_size - (size_t)(query_end - query_text),
            "' WHERE AGENT_ID = %d",
            agent_id);
    } else {
        query_size = DB_MAX_SQL_QUERY_LEN + 1;
        query_text = engine_malloc(query_size);
        query_end = query_text;
        query_end += snprintf(query_end, DB_MAX_SQL_QUERY_LEN,
            "UPDATE agents SET VM = NULL WHERE AGENT_ID = %d", agent_id);
    }

    // Show query at reset
    if(!vm)
       engine_trace(TRACE_LEVEL_ALWAYS, "Executing query [%s]", query_text);

    // run it
    if (mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "Updated VM for agent [%d]", agent_id);
    }

    // Deallocate dynamic memory
    if(vm_data)
    {
        free(vm_data);
        vm_data = NULL;
    }

    if(query_text) {
        engine_free(query_text, query_size);
        query_text = NULL;
    }

    return result;
}

/** ****************************************************************************

    @brief          Appends to the output a paused script has produced so far
                    (agents.OUTPUT holds it until the script finishes)

*******************************************************************************/
ErrorCode_t db_append_agent_output(DbConnection_t* connection, int agent_id, const char* msg)
{
    ErrorCode_t result = db_reconnect(connection);
    if(!msg) return ENGINE_INTERNAL_ERROR;

    char* safe = db_escape_alloc(connection, msg);
    if(!safe) return ENGINE_INTERNAL_ERROR;
    size_t query_size = strlen(safe) + 128;
    char* query_text = engine_malloc(query_size);
    if(!query_text) { free(safe); return ENGINE_INTERNAL_ERROR; }

    snprintf(query_text, query_size,
        "UPDATE agents SET OUTPUT = CONCAT(IFNULL(OUTPUT, ''), '%s') where AGENT_ID = %d",
        safe, agent_id);
    if (mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }

    engine_free(query_text, query_size);
    free(safe);
    return result;
}

/** ****************************************************************************

    @brief          Returns the output a paused script collected (malloc'd,
                    free() it; "" when none) and clears it

*******************************************************************************/
char* db_take_agent_output(DbConnection_t* connection, int agent_id)
{
    char* out = NULL;
    char query_text[128];

    if(db_reconnect(connection) == ENGINE_OK) {
        snprintf(query_text, sizeof(query_text), "SELECT OUTPUT from agents where AGENT_ID = %d", agent_id);
        if (mysql_query(connection->hndl, query_text) == 0) {
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);
            if(db_result) {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row && row[0]) out = strdup(row[0]);
                mysql_free_result(db_result);
            }
        } else {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        }
        snprintf(query_text, sizeof(query_text), "UPDATE agents SET OUTPUT = '' where AGENT_ID = %d", agent_id);
        if (mysql_query(connection->hndl, query_text))
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
    }

    return out ? out : strdup("");
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
        sizeof(query_text),
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
        // Prepare query to update input and subject. Both come from the
        // player's email, so escape both; escaping can double the length.
        size_t input_size = strlen(new_input)*2 + 1;
        char* scaped_input = engine_malloc(input_size);
        size_t subject_size = strlen(new_subject)*2 + 1;
        char* scaped_subject = engine_malloc(subject_size);

        db_scape_string(connection, new_input, strlen(new_input), scaped_input, input_size);
        db_scape_string(connection, new_subject, strlen(new_subject), scaped_subject, subject_size);

        size_t query_size = input_size + subject_size + 128;
        char* query_text = engine_malloc(query_size);

        snprintf(query_text,
            query_size,
            "UPDATE agents SET input = '%s', subject = '%s' where agent_id = %d",
            scaped_input,
            scaped_subject,
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
        engine_free(scaped_subject, subject_size);
    }

    if(agent_info.input_content)engine_free(agent_info.input_content, strlen(agent_info.input_content)+1);

    return result;
}

/** ****************************************************************************

    @brief          Updates current agent object ID (when current location changes)

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose output we want to update
    @param[in]      New object ID to be set

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_agent_object(DbConnection_t* connection, int agent_id, int object_id)
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
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS,
            "Updated Object ID for agent [%d] with value [%d]", agent_id, object_id);
    }

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

                    engine_trace(TRACE_LEVEL_DEBUG,
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

                    engine_trace(TRACE_LEVEL_DEBUG,
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

    @param[in|out]  Output parameter where we store the protocol info obtained
                    This object contains also current protocol ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_prococol_info(ProtocolInfo_t* protocol)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

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
            "SELECT id, name, parameters, bulk_modifier, description, observable, reportable, multiplier, "
            "double_params FROM protocols WHERE id = %d", protocol->protocol_id);

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
                    protocol->observable = row[PROTOCOL_OBSERVABLE_IDX]?atoi(row[PROTOCOL_OBSERVABLE_IDX]):0;
                    protocol->reportable = row[PROTOCOL_REPORTABLE_IDX]?atoi(row[PROTOCOL_REPORTABLE_IDX]):0;
                    protocol->multiplier = row[PROTOCOL_MULTIPLIER_IDX]?atoi(row[PROTOCOL_MULTIPLIER_IDX]):0;
                    protocol->double_params = row[PROTOCOL_DOUBLE_PARAMS_IDX]?atoi(row[PROTOCOL_DOUBLE_PARAMS_IDX]):0;

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "NAME [%s] DESCRIPTION [%s] BULK_MODIFIER [%d] OBSERVABLE [%d] REPORTABLE [%d] MULTIPLIER [%d] "
                        "obtained for PROTOCOL_ID [%d]",
                        protocol->protocol_name,
                        protocol->protocol_description,
                        protocol->bulk_multiplier,
                        protocol->protocol_id,
                        protocol->observable,
                        protocol->reportable,
                        protocol->multiplier);
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

    @param[in|out]  Action info to be inserted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_action(Action_t* action)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

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
        QUERY_SPACE_LEFT,
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
            QUERY_SPACE_LEFT,
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

    @brief      Gets all the resource effects configured for a given protocol ID

    @param[in]      Protocol Info
    @param[in|out]  Output buffer where we store the effects found in DB for this protocol
    @param[in|out]  Output buffer size (number of effects returned)

    @return     Execution result

*******************************************************************************/
ErrorCode_t db_get_resource_effects
(
    ProtocolInfo_t* protocol,
    ResourceEffect_t** effects,
    int* effectsNum
)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection = engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);
    MYSQL_RES* db_result = NULL;
    int fieldsNum = 0;
    uint64_t rowsNum = 0;

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!protocol || !protocol || !effects || !effectsNum) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        // reset entries found
        *effectsNum = 0;

        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "SELECT * FROM resource_effects WHERE protocol = %d", protocol->protocol_id);

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
                "ERROR: Unable to get resource_effects info for PROTOCOL_ID [%d] "
                "(invalid result for query [%s])",
                protocol->protocol_id,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((fieldsNum=mysql_num_fields(db_result)) != MAX_RESOURCE_EFFECT_FIELDS)
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Invalid fields number from resource_effects for PROTOCOL_ID [%d] "
                "(expected [%d], obtained [%d], query [%s])",
                protocol->protocol_id,
                MAX_RESOURCE_EFFECT_FIELDS,
                fieldsNum,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((rowsNum=mysql_num_rows(db_result)) <= 0)
        {
            // None entry found is OK (keep default OK)
            *effectsNum = 0;
        }
    }

    if((result == ENGINE_OK) && rowsNum)
    {
        // Allocate output effects
        *effects = (ResourceEffect_t*)malloc(sizeof(ResourceEffect_t) * ((unsigned int)rowsNum));

        if(!*effects) {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to allocate [%d] output resource_effects for PROTOCOL_ID [%d]",
                rowsNum,
                protocol->protocol_id);

            rowsNum = 0; // reset to avoid for(;;)
        }

        *effectsNum = rowsNum;

        // iterate results and store them into output buffer
        for(int effectId=0; effectId < rowsNum; effectId++)
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row)
            {
                ResourceEffect_t *currentEffect = (*effects + effectId);
                // Pick effect fields
                currentEffect->resource_effect_id = row[RESOURCE_EFFECT_ID_IDX]?atoi(row[RESOURCE_EFFECT_ID_IDX]):0;
                currentEffect->drone_id = row[RESOURCE_EFFECT_DRONE_ID_IDX]?atoi(row[RESOURCE_EFFECT_DRONE_ID_IDX]):0;
                currentEffect->resource_id = row[RESOURCE_EFFECT_RESOURCE_ID_IDX]?atoi(row[RESOURCE_EFFECT_RESOURCE_ID_IDX]):0;
                currentEffect->protocol_id = row[RESOURCE_EFFECT_PROTOCOL_ID_IDX]?atoi(row[RESOURCE_EFFECT_PROTOCOL_ID_IDX]):0;
                currentEffect->event_type = row[RESOURCE_EFFECT_EVENT_TYPE_IDX]?atoi(row[RESOURCE_EFFECT_EVENT_TYPE_IDX]):0;
                currentEffect->local = row[RESOURCE_EFFECT_LOCAL_IDX]?atoi(row[RESOURCE_EFFECT_LOCAL_IDX]):0;
                currentEffect->installed = row[RESOURCE_EFFECT_INSTALLED_IDX]?atoi(row[RESOURCE_EFFECT_INSTALLED_IDX]):0;
                currentEffect->locked = row[RESOURCE_EFFECT_LOCKED_IDX]?atoi(row[RESOURCE_EFFECT_LOCKED_IDX]):0;
                currentEffect->deplete = row[RESOURCE_EFFECT_DEPLETE_IDX]?atoi(row[RESOURCE_EFFECT_DEPLETE_IDX]):0;
                currentEffect->abundancies = row[RESOURCE_EFFECT_ABUNDANCIES_IDX]?atoi(row[RESOURCE_EFFECT_ABUNDANCIES_IDX]):0;
                currentEffect->quantity = row[RESOURCE_EFFECT_QUANTITY_IDX]?atoi(row[RESOURCE_EFFECT_QUANTITY_IDX]):0;
                currentEffect->time = row[RESOURCE_EFFECT_TIME_IDX]?atoi(row[RESOURCE_EFFECT_TIME_IDX]):0;

                engine_trace(TRACE_LEVEL_ALWAYS,
                    "Resource effect found "
                    "ID [%d] DRONE [%d] RESOURCE_ID [%d] EVENT_TYPE [%d] "
                    "LOCAL [%d] INSTALLED [%d] LOCKED [%d] DEPLETE [%d] ABUNDANCIES [%d] QUANTITY [%d] TIME [%d] "
                    "for PROTOCOL_ID [%d]",
                    currentEffect->resource_effect_id,
                    currentEffect->drone_id,
                    currentEffect->resource_id,
                    currentEffect->event_type,
                    currentEffect->local,
                    currentEffect->installed,
                    currentEffect->locked,
                    currentEffect->deplete,
                    currentEffect->abundancies,
                    currentEffect->quantity,
                    currentEffect->time,
                    protocol->protocol_id);
            }
            else
            {
                // unexpected error - abort
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get row [%d] for query [%s] with [%d] rows",
                    effectId+1,
                    query_text,
                    rowsNum);

                result = ENGINE_DB_QUERY_ERROR;

                // Deallocate resources
                free(*effects);
                *effects = NULL;
                *effectsNum = 0;

                break; // stop for
            }
        }
    }

    if(db_result)
        mysql_free_result(db_result);

    return result;
}

/** ****************************************************************************

    @brief      Gets all the market effects configured for a given protocol ID

    @param[in]      Protocol Info
    @param[in|out]  Output buffer where we store the effects found in DB for this protocol
    @param[in|out]  Output buffer size (number of effects returned)

    @return     Execution result

*******************************************************************************/
ErrorCode_t db_get_market_effects
(
    ProtocolInfo_t* protocol,
    MarketEffect_t** effects,
    int* effectsNum
)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);
    MYSQL_RES* db_result = NULL;
    int fieldsNum = 0;
    uint64_t rowsNum = 0;

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!protocol || !protocol || !effects || !effectsNum) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        // reset entries found
        *effectsNum = 0;

        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "SELECT * FROM market_effects WHERE protocol = %d",
            protocol->protocol_id);

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
        rowsNum = mysql_num_rows(db_result);

        engine_trace(TRACE_LEVEL_ALWAYS, "DB result [%s], rows [%d]", db_result?"OK":"KO", mysql_num_rows(db_result));

        if(db_result == NULL)
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get market_effects info for PROTOCOL_ID [%d] "
                "(invalid result for query [%s])",
                protocol->protocol_id,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((fieldsNum=mysql_num_fields(db_result)) != MAX_MARKET_EFFECT_FIELDS)
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Invalid fields number from market_effects for PROTOCOL_ID [%d] "
                "(expected [%d], obtained [%d], query [%s])",
                protocol->protocol_id,
                MAX_MARKET_EFFECT_FIELDS,
                fieldsNum,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
    }

    if((result == ENGINE_OK) && effectsNum)
    {
        engine_trace(TRACE_LEVEL_ALWAYS,
                        "[%d] market_effects found for PROTOCOL_ID [%d]",
                        rowsNum,
                        protocol->protocol_id);

        // Allocate output effects
        *effects = malloc(sizeof(ResourceEffect_t) * rowsNum);

        if(!*effects) {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to allocate [%d] output market_effects for PROTOCOL_ID [%d]",
                rowsNum,
                protocol->protocol_id);
        }

        *effectsNum = rowsNum;

        // iterate results and store them into output buffer
        for(int effectId=0; effectId < rowsNum; effectId++)
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row)
            {
                MarketEffect_t *currentEffect = (*effects + effectId);
                // Pick effect fields
                currentEffect->market_effect_id = row[MARKET_EFFECT_ID_IDX]?atoi(row[MARKET_EFFECT_ID_IDX]):0;
                currentEffect->protocol_id = row[MARKET_EFFECT_PROTOCOL_ID_IDX]?atoi(row[MARKET_EFFECT_PROTOCOL_ID_IDX]):0;
                currentEffect->event_type = row[MARKET_EFFECT_EVENT_TYPE_IDX]?atoi(row[MARKET_EFFECT_EVENT_TYPE_IDX]):0;
                currentEffect->resource_id = row[MARKET_EFFECT_RESOURCE_ID_IDX]?atoi(row[MARKET_EFFECT_RESOURCE_ID_IDX]):0;
                currentEffect->upper_limit = row[MARKET_EFFECT_UPPER_LIMIT_IDX]?atoi(row[MARKET_EFFECT_UPPER_LIMIT_IDX]):0;
                currentEffect->quantity = row[MARKET_EFFECT_QUANTITY_IDX]?atoi(row[MARKET_EFFECT_QUANTITY_IDX]):0;
                currentEffect->price = row[MARKET_EFFECT_PRICE_IDX]?atoi(row[MARKET_EFFECT_PRICE_IDX]):0;
                currentEffect->time = row[MARKET_EFFECT_TIME_IDX]?atoi(row[MARKET_EFFECT_TIME_IDX]):0;

                engine_trace(TRACE_LEVEL_ALWAYS,
                    "Market effect found "
                    "ID [%d] EVENT_TYPE [%d] RESOURCE_ID [%d] "
                    "UPPER_LIMIT [%d] QUANTITY [%d] PRICE [%d] TIME [%d] "
                    "for PROTOCOL_ID [%d]",
                    currentEffect->market_effect_id,
                    currentEffect->event_type,
                    currentEffect->resource_id,
                    currentEffect->upper_limit,
                    currentEffect->quantity,
                    currentEffect->price,
                    currentEffect->time,
                    protocol->protocol_id);
            }
            else
            {
                // unexpected error - abort
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get row [%d] for query [%s] with [%d] rows",
                    effectId+1,
                    query_text,
                    effectsNum);

                result = ENGINE_DB_QUERY_ERROR;

                // Deallocate resources
                free(*effects);
                *effects = NULL;
                *effectsNum = 0;

                break; // stop for
            }
        }
    }

    if(db_result)
        mysql_free_result(db_result);

    return result;
}

/** ****************************************************************************

    @brief      Gets all the location effects configured for a given protocol ID

    @param[in]      Protocol Info
    @param[in|out]  Output buffer where we store the effects found in DB for this protocol
    @param[in|out]  Output buffer size (number of effects returned)

    @return     Execution result

*******************************************************************************/
ErrorCode_t db_get_location_effects
(
    ProtocolInfo_t* protocol,
    LocationEffect_t** effects,
    int* effectsNum
)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);
    MYSQL_RES* db_result = NULL;
    int fieldsNum = 0;
    int rowsNum = 0;

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!protocol || !protocol || !effects || !effectsNum) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        // reset entries found
        *effectsNum = 0;

        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "SELECT * FROM location_effects WHERE protocol = %d", protocol->protocol_id);

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

        engine_trace(TRACE_LEVEL_ALWAYS, "DB result [%s]", db_result?"OK":"KO");

        if(db_result == NULL)
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get location_effects info for PROTOCOL_ID [%d] "
                "(invalid result for query [%s])",
                protocol->protocol_id,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((fieldsNum=mysql_num_fields(db_result)) != MAX_LOCATION_EFFECT_FIELDS)
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Invalid fields number from location_effects for PROTOCOL_ID [%d] "
                "(expected [%d], obtained [%d], query [%s])",
                protocol->protocol_id,
                MAX_LOCATION_EFFECT_FIELDS,
                fieldsNum,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else if((rowsNum=mysql_num_rows(db_result)) <= 0)
        {
            // None entry found is OK (keep default OK)
            *effectsNum = 0;
        }
    }

    if((result == ENGINE_OK) && rowsNum)
    {
        engine_trace(TRACE_LEVEL_ALWAYS,
                        "[%d] location_effects found for PROTOCOL_ID [%d]",
                        rowsNum,
                        protocol->protocol_id);

        // Allocate output effects
        *effects = (LocationEffect_t*)malloc(sizeof(LocationEffect_t) * ((unsigned int)rowsNum));

        if(!*effects) {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to allocate [%d] output location_effects for PROTOCOL_ID [%d]",
                rowsNum,
                protocol->protocol_id);

            rowsNum = 0; // reset to avoid for(;;)
        }

        *effectsNum = rowsNum;

        // iterate results and store them into output buffer
        for(int effectId=0; effectId < rowsNum; effectId++)
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row)
            {
                LocationEffect_t *currentEffect = (*effects + effectId);
                // Pick effect fields
                currentEffect->location_effect_id = row[LOCATION_EFFECT_ID_IDX]?atoi(row[LOCATION_EFFECT_ID_IDX]):0;
                currentEffect->protocol_id = row[LOCATION_EFFECT_PROTOCOL_ID_IDX]?atoi(row[LOCATION_EFFECT_PROTOCOL_ID_IDX]):0;
                currentEffect->event_type = row[LOCATION_EFFECT_EVENT_TYPE_IDX]?atoi(row[LOCATION_EFFECT_EVENT_TYPE_IDX]):0;
                currentEffect->location = row[LOCATION_EFFECT_LOCATION_IDX]?atoi(row[LOCATION_EFFECT_LOCATION_IDX]):0;
                currentEffect->time = row[LOCATION_EFFECT_TIME_IDX]?atoi(row[LOCATION_EFFECT_TIME_IDX]):0;

                engine_trace(TRACE_LEVEL_ALWAYS,
                    "Location effect found "
                    "ID [%d] EVENT_TYPE [%d] LOCATION [%d] TIME [%d] "
                    "for PROTOCOL_ID [%d]",
                    currentEffect->location_effect_id,
                    currentEffect->protocol_id,
                    currentEffect->event_type,
                    currentEffect->location,
                    protocol->protocol_id);
            }
            else
            {
                // unexpected error - abort
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get row [%d] for query [%s] with [%d] rows",
                    effectId+1,
                    query_text,
                    effectsNum);

                result = ENGINE_DB_QUERY_ERROR;

                // Deallocate resources
                free(*effects);
                *effects = NULL;
                *effectsNum = 0;

                break; // stop for
            }
        }
    }

    if(db_result)
        mysql_free_result(db_result);

    return result;
}

/** ****************************************************************************

    @brief          Gets resource info for a given resource ID
                    (received in IN/OUT protocol parameter)

    @param[in|out]  Output parameter where we store the resource info obtained
                    This object contains (as input) current resource ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_resource_info(Resource_t* resource)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!resource) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "SELECT * FROM resources WHERE id = %d", resource->resource_id);

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
                (mysql_num_fields(db_result) != MAX_RESOURCE_FIELDS))
            {
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get resource info for RESOURCE_ID [%d] "
                    "(invalid result for query [%s])",
                    resource->resource_id,
                    query_text);

                result = ENGINE_DB_QUERY_ERROR;
            }
            else
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row)
                {
                    engine_trace(TRACE_LEVEL_ALWAYS, "Query [%s] was OK", query_text);

                    // Pick the fields we need
                    snprintf(resource->resource_name, MAX_RESOURCE_NAME_SIZE-1, "%s", row[RESOURCE_NAME_IDX]?row[RESOURCE_NAME_IDX]:"");
                    snprintf(resource->resource_description, MAX_RESOURCE_DESCRIPTION_SIZE-1, "%s", row[RESOURCE_DESCRIPTION_IDX]?row[RESOURCE_DESCRIPTION_IDX]:"");
                    resource->resource_mass = row[RESOURCE_MASS_IDX]?atoi(row[RESOURCE_MASS_IDX]):0;
                    resource->resource_capacity = row[RESOURCE_CAPACITY_IDX]?atoi(row[RESOURCE_CAPACITY_IDX]):0;
                    resource->resource_slot_size = row[RESOURCE_SLOT_SIZE_IDX]?atoi(row[RESOURCE_SLOT_SIZE_IDX]):0;

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "NAME [%s] DESCRIPTION [%s] MASS [%d] CAPACITY [%d] SLOT_SIZE [%d] obtained for RESOURCE_ID [%d]",
                        resource->resource_name,
                        resource->resource_description,
                        resource->resource_mass,
                        resource->resource_capacity,
                        resource->resource_slot_size,
                        resource->resource_id);
                }
                else
                {
                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "ERROR: Unable to get resource info for RESOURCE_ID [%d] (no row)",
                        resource->resource_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Gets event type info for a given event type ID
                    (received in IN/OUT protocol parameter)

    @param[in|out]  Output parameter where we store the event type info obtained
                    This object contains (as input) current event type ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_event_type_info(EventType_t* event_type)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!event_type) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "SELECT * FROM event_types WHERE id = %d", event_type->event_type_id);

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
                (mysql_num_fields(db_result) != MAX_EVENT_TYPE_FIELDS))
            {
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get event_type info for EVENT_TYPE_ID [%d] "
                    "(invalid result for query [%s])",
                    event_type->event_type_id,
                    query_text);

                result = ENGINE_DB_QUERY_ERROR;
            }
            else
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row)
                {
                    // Pick the fields we need
                    sprintf(event_type->event_type_name, "%s", row[EVENT_TYPE_NAME_IDX]?row[EVENT_TYPE_NAME_IDX]:"");

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "NAME [%s] obtained for EVENT_TYPE_ID [%d]",
                        event_type->event_type_name,
                        event_type->event_type_id);
                }
                else
                {
                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "ERROR: Unable to get resource info for EVENT_TYPE_ID [%d] (no row)",
                        event_type->event_type_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Inserts a new event entry

    @param[in|out]  Event info to be inserted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_event(Event_t* event)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    char new_quantity_text[MAX_QUERY_VALUE_BUF_LEN];
    char new_credits_text[MAX_QUERY_VALUE_BUF_LEN];
    char new_location_text[MAX_QUERY_VALUE_BUF_LEN];
    char new_transmission_text[MAX_QUERY_VALUE_BUF_LEN];
    char new_cargo_text[MAX_QUERY_VALUE_BUF_LEN];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        // Sanity check
        if(!event)
            return ENGINE_INTERNAL_ERROR;
    }

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
        game_time_now_seconds() + event->delay, // when it happens, in game time
        event->event_type,
        event->action_id,
        event->logged,
        event->outcome,
        event->drone_id,
        event->resource_id,
        event->installed,
        event->locked,
        event->new_quantity!=NULL_VALUE?db_int2str(event->new_quantity, new_quantity_text, MAX_QUERY_VALUE_BUF_LEN):"",
        event->new_quantity!=NULL_VALUE?", ":"",
        event->new_credits!=NULL_VALUE?db_int2str(event->new_credits, new_credits_text, MAX_QUERY_VALUE_BUF_LEN):"",
        event->new_credits!=NULL_VALUE?", ":"",
        event->new_location!=NULL_VALUE?db_int2str(event->new_location, new_location_text, MAX_QUERY_VALUE_BUF_LEN):"",
        event->new_location!=NULL_VALUE?", ":"",
        event->new_transmission!=NULL_VALUE?db_int2str(event->new_transmission, new_transmission_text, MAX_QUERY_VALUE_BUF_LEN):"",
        event->new_transmission!=NULL_VALUE?", ":"",
        event->new_cargo!=NULL_VALUE?db_int2str(event->new_cargo, new_cargo_text, MAX_QUERY_VALUE_BUF_LEN):"",
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
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
        result = ENGINE_DB_QUERY_ERROR;
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "Query [%s] executed", query_text);

        // Get auto-increment ID
        event->event_id = mysql_insert_id(connection->hndl);

        engine_trace(TRACE_LEVEL_ALWAYS,
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

    @brief          Gets last event for a given drone and resource

    @param[in|out]  Event info obtained (drone ID is filled)

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_previous_resource_event(Event_t* event)
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

    char* query_end = query_text;

    query_end += snprintf(query_end,
        QUERY_SPACE_LEFT,
        "SELECT * from events where drone = %d and resource = %d order by game_t desc, id desc limit 1",
        event->drone_id,
        event->resource_id);

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
            (mysql_num_fields(db_result) != MAX_EVENT_FIELDS))
        {
            engine_trace(TRACE_LEVEL_ALWAYS,
                "ERROR: Unable to get last drone event info for DRONE_ID [%d] RESOURCE_ID [%d] "
                "(invalid result for query [%s])",
                event->drone_id,
                event->resource_id,
                query_text);

            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            MYSQL_ROW row = mysql_fetch_row(db_result);
            if(row)
            {
                // Pick the fields we need
                event->event_id = row[EVENT_ID_IDX]?atoi(row[EVENT_ID_IDX]):0;
                event->event_type = row[EVENT_TYPE_IDX]?atoi(row[EVENT_TYPE_IDX]):0;
                event->action_id = row[EVENT_ACTION_IDX]?atoi(row[EVENT_ACTION_IDX]):0;
                event->logged = row[EVENT_LOGGED_IDX]?atoi(row[EVENT_LOGGED_IDX]):0;
                event->outcome = row[EVENT_OUTCOME_IDX]?atoi(row[EVENT_OUTCOME_IDX]):0;
                event->installed = row[EVENT_INSTALLED_IDX]?atoi(row[EVENT_INSTALLED_IDX]):0;
                event->locked = row[EVENT_LOCKED_IDX]?atoi(row[EVENT_LOCKED_IDX]):0;
                event->new_quantity = row[EVENT_NEW_QUANTITY_IDX]?atoi(row[EVENT_NEW_QUANTITY_IDX]):NULL_VALUE;
                event->new_credits = row[EVENT_NEW_CREDITS_IDX]?atoi(row[EVENT_NEW_CREDITS_IDX]):NULL_VALUE;
                event->new_location = atoi(row[EVENT_NEW_LOCATION_IDX]);
                event->new_transmission = row[EVENT_NEW_TRANSMISSION_IDX]?atoi(row[EVENT_NEW_TRANSMISSION_IDX]):-1;
                event->new_cargo = row[EVENT_NEW_CARGO_IDX]?atoi(row[EVENT_NEW_CARGO_IDX]):0;
                event->game_t = row[EVENT_GAME_T_IDX]?atoll(row[EVENT_GAME_T_IDX]):0;

                engine_trace(TRACE_LEVEL_ALWAYS,
                    "Event read from DB [%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d] "
                    "for drone_id [%d] resource_id [%d]",
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
                    event->timestamp,
                    event->drone_id,
                    event->resource_id);
            }
            else
            {
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get previous event info for DRONE_ID [%d] RESOURCE_ID [%d] (no row)",
                    event->drone_id,
                    event->resource_id);

                result = ENGINE_DB_QUERY_ERROR;
            }
        }

        mysql_free_result(db_result);
    }

    return result;
}

/** ****************************************************************************

    @brief          Deletes all events currently associated with a given action ID

    @param[in]      Input action ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_action_events(int action_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    if(result == ENGINE_OK)
    {
        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "DELETE FROM events WHERE action = %d",
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
                "All events for ACTION_ID [%d] deleted from DB",
                action_id);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Checks if a given agent name exists in DB

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Name to be used in update

    @return         Execution result (ENGINE_OK when found)

*******************************************************************************/
ErrorCode_t db_check_agent_name(DbConnection_t* connection, char* name)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // ignore null msg
    if(!name) return ENGINE_INTERNAL_ERROR;

    // Prepare query
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    char* safe_name = db_escape_alloc(connection, name);
    if(!safe_name) return ENGINE_INTERNAL_ERROR;

    snprintf(query_text,
        DB_MAX_SQL_QUERY_LEN,
        "SELECT name from agents where LOWER(NAME) = LOWER('%s')",
        safe_name);

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

        if(mysql_num_rows(db_result) > 0) {
            engine_trace(TRACE_LEVEL_ALWAYS, "AGENT name [%s] found in DB", name);
        } else {
            engine_trace(TRACE_LEVEL_ALWAYS, "AGENT name [%s] NOT found in DB", name);
            result = ENGINE_DB_NOT_FOUND_ERROR;
        }
    }

    free(safe_name);
    return result;
}

/** ****************************************************************************

    @brief          Checks if a given company name exists in DB

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Name to be used in update

    @return         Execution result (ENGINE_OK when found)

*******************************************************************************/
ErrorCode_t db_check_company_name(DbConnection_t* connection, char* company_name)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // ignore null msg
    if(!company_name) return ENGINE_INTERNAL_ERROR;

    // Prepare query
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    char* safe_company_name = db_escape_alloc(connection, company_name);
    if(!safe_company_name) return ENGINE_INTERNAL_ERROR;

    snprintf(query_text,
        DB_MAX_SQL_QUERY_LEN,
        "SELECT name from companies where LOWER(name) = LOWER('%s')",
        safe_company_name);

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

        if(mysql_num_rows(db_result) > 0) {
            engine_trace(TRACE_LEVEL_ALWAYS, "COMPANY name [%s] found in DB", company_name);
        } else {
            engine_trace(TRACE_LEVEL_ALWAYS, "COMPANY name [%s] NOT found in DB", company_name);
            result = ENGINE_DB_NOT_FOUND_ERROR;
        }
    }

    free(safe_company_name);
    return result;
}

/** ****************************************************************************

    @brief          Updates given agent name

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose output we want to update
    @param[in]      Name to be used in update

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_agent_name(DbConnection_t* connection, int agent_id, char* name)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // ignore null msg
    if(!name) return ENGINE_INTERNAL_ERROR;

    // Prepare query
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    char* safe_name = db_escape_alloc(connection, name);
    if(!safe_name) return ENGINE_INTERNAL_ERROR;

    snprintf(query_text,
        DB_MAX_SQL_QUERY_LEN,
        "UPDATE agents SET NAME = '%s' where AGENT_ID = %d",
        safe_name,
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
            "Updated AGENT[%d] name to [%s]", agent_id, name);
    }

    free(safe_name);
    return result;
}


/** ****************************************************************************

    @brief          Updates given agent name

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose output we want to update
    @param[in]      Company name to be used in update

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_agent_company(DbConnection_t* connection, int agent_id, char* company_name)
{
    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // ignore null msg
    if(!company_name) return ENGINE_INTERNAL_ERROR;

    // Prepare query
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    char* safe_company_name = db_escape_alloc(connection, company_name);
    if(!safe_company_name) return ENGINE_INTERNAL_ERROR;

    snprintf(query_text,
        DB_MAX_SQL_QUERY_LEN,
        "UPDATE companies SET name = '%s' where company_id = (SELECT company_id from agents where agent_id = %d)",
        safe_company_name,
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
            "Updated AGENT[%d] company to [%s]", agent_id, company_name);
    }

    free(safe_company_name);
    return result;
}


/** ****************************************************************************

    @brief          Gets query info using a given ID

    @param[in|out]  Output parameter where we store the query info obtained
                    This object contains (as input) current query ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_query_info(Queries_t* queryInfo)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection = engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!queryInfo) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        snprintf(query_text,
            DB_MAX_SQL_QUERY_LEN,
            "SELECT * FROM queries WHERE id = %d", queryInfo->id);

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
                (mysql_num_fields(db_result) != MAX_QUERIES_FIELDS))
            {
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to get query info for QUERY_ID [%d] "
                    "(invalid result for query [%s])",
                    queryInfo->id,
                    query_text);

                result = ENGINE_DB_QUERY_ERROR;
            }
            else
            {
                MYSQL_ROW row = mysql_fetch_row(db_result);
                if(row)
                {
                    // Pick the fields we need
                    sprintf(queryInfo->name, "%s", row[QUERIES_NAME_IDX]?row[QUERIES_NAME_IDX]:"");
                    sprintf(queryInfo->description, "%s", row[QUERIES_DESCRIPTION_IDX]?row[QUERIES_DESCRIPTION_IDX]:"");
                    queryInfo->parametersNum = row[QUERIES_PARAMETERS_IDX]?atoi(row[QUERIES_PARAMETERS_IDX]):0;
                    sprintf(queryInfo->script, "%s", row[QUERIES_SCRIPT_IDX]?row[QUERIES_SCRIPT_IDX]:"");

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "NAME [%s] DESCRIPTION [%s] PARAMETERS [%d] SCRIPT [%s] obtained for QUERY_ID [%d]",
                        queryInfo->name,
                        queryInfo->description,
                        queryInfo->parametersNum,
                        queryInfo->script,
                        queryInfo->id);
                }
                else
                {
                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "ERROR: Unable to get info for QUERY_ID [%d] (no row)",
                        queryInfo->id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}

/** Text columns: stored as counted strings */
static Bool_t db_vm_field_is_text(int type)
{
    switch(type) {
        case MYSQL_TYPE_STRING: case MYSQL_TYPE_VAR_STRING: case MYSQL_TYPE_VARCHAR:
        case MYSQL_TYPE_BLOB: case MYSQL_TYPE_TINY_BLOB: case MYSQL_TYPE_MEDIUM_BLOB:
        case MYSQL_TYPE_LONG_BLOB: case MYSQL_TYPE_ENUM: case MYSQL_TYPE_SET:
            return ENGINE_TRUE;
        default:
            return ENGINE_FALSE;
    }
}

/** Date columns: stored in 4 bytes (vm_write_datetime) */
static Bool_t db_vm_field_is_date(int type)
{
    return (type == MYSQL_TYPE_DATETIME || type == MYSQL_TYPE_TIMESTAMP ||
            type == MYSQL_TYPE_DATE) ? ENGINE_TRUE : ENGINE_FALSE;
}

/** Double-cell columns (#324 step 9): a number column whose name starts with
    d_ (money, volumes) is stored in two cells, high cell first, so 2@ reads it */
static Bool_t db_vm_field_is_double(const char* name)
{
    return (name && (name[0] == 'd' || name[0] == 'D') && name[1] == '_') ? ENGINE_TRUE : ENGINE_FALSE;
}

/** Bytes a query result field takes in VM memory (0: not stored) */
static int db_vm_field_size(int type, const char* value, Bool_t is_double)
{
    if(db_vm_field_is_text(type)) {
        size_t len = value ? strlen(value) : 0;
        if(len > 255) len = 255;          // the length byte limits it
        return (int)len + 1;
    }
    if(db_vm_field_is_date(type))
        return 4;
    switch(type) {
        case MYSQL_TYPE_TINY: case MYSQL_TYPE_SHORT: case MYSQL_TYPE_LONG:
        case MYSQL_TYPE_INT24: case MYSQL_TYPE_LONGLONG: case MYSQL_TYPE_DECIMAL:
        case MYSQL_TYPE_NEWDECIMAL: case MYSQL_TYPE_FLOAT: case MYSQL_TYPE_DOUBLE:
        case MYSQL_TYPE_YEAR: case MYSQL_TYPE_BIT:
            return is_double ? 4 : 2;
        default:
            engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: query field type [%d] not stored in VM memory", type);
            return 0;
    }
}

/** ****************************************************************************

    @brief      Runs a query against DB using info provided by VM.
                Stores results into the VM using the address and size supplied

    @param[in]  queryInfo Whole query info obtained from VM stack
    @param[in]  vm        Current VM

    @return     Execution result

*******************************************************************************/
ErrorCode_t db_run_vm_query(Queries_t* queryInfo, VirtualMachine_t* vm)
{
    DbConnection_t* connection =  engine_get_db_connection();
    Bool_t stop = ENGINE_FALSE;
    int rowsNum = 0;
    uint16_t value = 0;

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!queryInfo || !vm) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "Running VM query [%s]", queryInfo->finalQuery);

        // run the query
        if (mysql_query(connection->hndl, queryInfo->finalQuery))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", queryInfo->finalQuery);
            result = ENGINE_DB_QUERY_ERROR;
        }
        else
        {
            // retrieve the result and check that is an only row with a single field
            MYSQL_RES* db_result = mysql_store_result(connection->hndl);

            if(db_result == NULL)
            {
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Invalid result for query [%s]",
                    queryInfo->finalQuery);

                result = ENGINE_DB_QUERY_ERROR;
            }
            else if(!(rowsNum=mysql_num_rows(db_result))) {
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: No entries found for query [%s]",
                    queryInfo->finalQuery);

                result = ENGINE_DB_QUERY_ERROR;
            }
            else
            {
                int fieldsNum = mysql_num_fields(db_result);
                int* fieldTypes = (int*) engine_malloc(fieldsNum * sizeof(int));
                Bool_t* fieldDouble = (Bool_t*) engine_malloc(fieldsNum * sizeof(Bool_t));

                for(int fieldId=0; fieldId < fieldsNum; fieldId++) {
                    // check the field type and serialize it into VM memory
                    MYSQL_FIELD *field = mysql_fetch_field(db_result);
                    fieldTypes[fieldId] = (int) field->type;
                    fieldDouble[fieldId] = db_vm_field_is_double(field->name);

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "Query [%s] field[%d] type [%d]",
                        queryInfo->finalQuery, fieldId, fieldTypes[fieldId]);
                }

                engine_trace(TRACE_LEVEL_ALWAYS,
                    "[%d] entries found for query [%s] ([%d] fields per row)",
                    rowsNum,
                    queryInfo->finalQuery,
                    fieldsNum);

                // Rows are stored one after another, each field in turn:
                // numbers as 2-byte cells (4 bytes for d_ columns: doubles),
                // dates as 4 bytes, text as a counted
                // string (a length byte and the characters). Only whole rows
                // are stored: when the next row doesn't fit, it stops.
                int currentAddr = queryInfo->resultsArrayAddr;
                int remainingOffset = queryInfo->resultsArraySize;
                queryInfo->rowsStored = 0;

                for(int rowId=0; rowId < rowsNum && stop == ENGINE_FALSE; rowId++) {
                    MYSQL_ROW row = mysql_fetch_row(db_result);
                    if(!row) break;

                    // How many bytes this row needs
                    int rowSize = 0;
                    for(int fieldId=0; fieldId < fieldsNum; fieldId++)
                        rowSize += db_vm_field_size(fieldTypes[fieldId], row[fieldId], fieldDouble[fieldId]);

                    if(rowSize > remainingOffset) {
                        engine_trace(TRACE_LEVEL_ALWAYS,
                            "No room left for row [%d] ([%d] bytes, [%d] left): [%d] rows stored",
                            rowId, rowSize, remainingOffset, queryInfo->rowsStored);
                        stop = ENGINE_TRUE;
                        break;
                    }

                    for(int fieldId=0; fieldId < fieldsNum && result == ENGINE_OK; fieldId++) {
                        const char* text = row[fieldId] ? row[fieldId] : "";
                        int size = db_vm_field_size(fieldTypes[fieldId], row[fieldId], fieldDouble[fieldId]);

                        if(size == 0)
                            continue; // a type we don't store

                        if(db_vm_field_is_text(fieldTypes[fieldId])) {
                            char counted[256];
                            snprintf(counted, sizeof(counted), "%.*s", size - 1, text);
                            result = vm_write_string(vm, currentAddr, counted);
                        } else if(db_vm_field_is_date(fieldTypes[fieldId]) && row[fieldId]) {
                            result = vm_write_datetime(vm, currentAddr, row[fieldId]);
                        } else if(db_vm_field_is_date(fieldTypes[fieldId])) {
                            result = vm_write_integer(vm, currentAddr, 0);
                            if(result == ENGINE_OK) result = vm_write_integer(vm, currentAddr + 2, 0);
                        } else if(fieldDouble[fieldId]) {
                            // Money and volumes: a 32-bit double, high cell
                            // first (as 2! stores it), clamped to its range
                            double d = llround(strtod(text, NULL));
                            if(d > 2147483647.0) d = 2147483647.0;
                            if(d < -2147483648.0) d = -2147483648.0;
                            uint32_t bits = (uint32_t)(int32_t)(long long)d;
                            result = vm_write_integer(vm, currentAddr, (uint16_t)(bits >> 16));
                            if(result == ENGINE_OK)
                                result = vm_write_integer(vm, currentAddr + 2, (uint16_t)(bits & 0xFFFF));
                        } else {
                            // Any number (also COUNT, SUM, decimals): rounded
                            // to an integer in one 16-bit cell
                            value = (uint16_t)(long)llround(strtod(text, NULL));
                            result = vm_write_integer(vm, currentAddr, value);
                        }
                        currentAddr += size;
                        remainingOffset -= size;
                    }

                    if(result == ENGINE_OK)
                        queryInfo->rowsStored++;
                    else
                        stop = ENGINE_TRUE;
                }

                if(fieldTypes) engine_free(fieldTypes, fieldsNum * sizeof(int));
                if(fieldDouble) engine_free(fieldDouble, fieldsNum * sizeof(Bool_t));
            }

            if(db_result) mysql_free_result(db_result);
        }
    }

    return result;
}


/** ****************************************************************************

    @brief      Handy function to convert an integer into string and return string

    @param[in]  value  Integer value we want to convert
    @param[in]  buffer String buffer where we want to store the result
    @param[in]  szie   String buffer size

    @return     String result

*******************************************************************************/
char* db_int2str(int value, char* buffer, size_t size)
{
    if(value == NULL_VALUE) {
        sprintf(buffer, "NULL");
    } else if(buffer && size) {
        snprintf(buffer, size, "%d", value);
    }

    return buffer;
}


/** ****************************************************************************

    @brief          Gets object ID for a given drone ID

    @param[in]      drone_id   Input drone ID
    @param[out]     object_id  Output object ID obtained when success

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_drone_object_id(int drone_id, int *object_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!object_id) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        char* query_end = query_text;

        query_end += snprintf(query_end,
            QUERY_SPACE_LEFT,
            "SELECT object_id from agents where agent_id = %d",
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
                    "ERROR: Unable to get object_id for DRONE_ID [%d] "
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
                    *object_id = row[0]?atoi(row[0]):0;

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "Obtained object ID [%d] for DRONE_ID [%d]",
                        *object_id,
                        drone_id);
                }
                else
                {
                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "ERROR: Unable to get object ID for DRONE_ID [%d] (no row)",
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

    @brief          Gets resource abundancies depending on current location

    @param[in|out]  abundancies  Input/output object where we store results

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_abundancies(Abundancies_t *abundancies)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    DbConnection_t* connection =  engine_get_db_connection();

    // always check connection is alive
    ErrorCode_t result = db_reconnect(connection);

    // sanity check
    if(result == ENGINE_OK)
    {
        if(!abundancies) return ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        char* query_end = query_text;

        query_end += snprintf(query_end,
            QUERY_SPACE_LEFT,
            "SELECT multiplier from abundancies where location = %d and resource = %d order by id limit 1",
            abundancies->location_id,
            abundancies->resource_id);

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
                    "ERROR: Unable to get abundancies info for LOCATION [%d] RESOURCE [%d] "
                    "(invalid result for query [%s], rows [%d], fields [%d])",
                    abundancies->location_id,
                    abundancies->resource_id,
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
                    abundancies->multiplier = row[0]?atoi(row[0]):0;

                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "Obtained abundancies info: LOCATION [%d] RESOURCE [%d] MULTIPLIER [%d]",
                        abundancies->location_id,
                        abundancies->resource_id,
                        abundancies->multiplier);
                }
                else
                {
                    engine_trace(TRACE_LEVEL_ALWAYS,
                        "ERROR: Unable to get abundancies info for LOCATION [%d] RESOURCE [%d] (no row)",
                        abundancies->location_id,
                        abundancies->resource_id);

                    result = ENGINE_DB_QUERY_ERROR;
                }
            }

            mysql_free_result(db_result);
        }
    }

    return result;
}

/** ****************************************************************************

    @brief          Runs a query that returns one row of integers

    @return         ENGINE_OK with the row read, ENGINE_DB_NOT_FOUND_ERROR when
                    there is no row (NULL values read as 0, flagged in nulls)

*******************************************************************************/
static ErrorCode_t db_query_ints(DbConnection_t* connection, const char* query_text,
                                 int* values, int* nulls, int count)
{
    ErrorCode_t result = db_reconnect(connection);
    if(result != ENGINE_OK)
        return result;

    if(mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }

    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    MYSQL_ROW row = db_result ? mysql_fetch_row(db_result) : NULL;

    if(row)
    {
        for(int i = 0; i < count; i++) {
            values[i] = row[i] ? atoi(row[i]) : 0;
            if(nulls) nulls[i] = (row[i] == NULL);
        }
    }
    else
    {
        result = ENGINE_DB_NOT_FOUND_ERROR;
    }

    if(db_result) mysql_free_result(db_result);
    return result;
}

/** ****************************************************************************

    @brief          Checks a launch ([D K 12 perform]): K must be a ship kit (it
                    has a hull) and D one of the launching drone's owner's drones

    @param[out]     source_object  Where drone D is

    @return         ENGINE_OK, or ENGINE_DB_NOT_FOUND_ERROR when refused

*******************************************************************************/
ErrorCode_t db_get_launch_source(DbConnection_t* connection, int launcher_id, int source_id,
                                 int kit_id, int* source_object)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT d.object_id FROM agents l JOIN agents d ON d.company_id = l.company_id "
        "WHERE l.agent_id = %d AND d.agent_id = %d AND EXISTS (SELECT 1 FROM hulls WHERE id = %d)",
        launcher_id, source_id, kit_id);

    return db_query_ints(connection, query_text, source_object, NULL, 1);
}

/** ****************************************************************************

    @brief          Adds the instructions a drone's script used in a slice to
                    its running total (agents.instructions; instruction
                    metering, the basis for power use)

*******************************************************************************/
ErrorCode_t db_add_agent_instructions(DbConnection_t* connection, int agent_id, long long instructions)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    ErrorCode_t result = db_reconnect(connection);

    if(result != ENGINE_OK || instructions <= 0)
        return result;

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "UPDATE agents SET instructions = instructions + %lld WHERE agent_id = %d", instructions, agent_id);

    if(mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        result = ENGINE_DB_QUERY_ERROR;
    }

    return result;
}

/** ****************************************************************************

    @brief          Sets (or clears, with 0) the action a drone's script waits for

*******************************************************************************/
ErrorCode_t db_set_agent_waiting_action(DbConnection_t* connection, int agent_id, int action_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    ErrorCode_t result = db_reconnect(connection);

    if(result != ENGINE_OK)
        return result;

    if(action_id)
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
            "UPDATE agents SET waiting_action = %d WHERE agent_id = %d", action_id, agent_id);
    else
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
            "UPDATE agents SET waiting_action = NULL WHERE agent_id = %d", agent_id);

    if(mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        result = ENGINE_DB_QUERY_ERROR;
    }

    return result;
}

/** ****************************************************************************

    @brief          Gets the action a drone's script waits for (0 when none)

*******************************************************************************/
ErrorCode_t db_get_agent_waiting_action(DbConnection_t* connection, int agent_id, int* action_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];

    *action_id = 0;
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT waiting_action FROM agents WHERE agent_id = %d", agent_id);

    return db_query_ints(connection, query_text, action_id, NULL, 1);
}

/** ****************************************************************************

    @brief          Gets how an action went

    @param[in]      connection DB connection
    @param[in]      action_id  Action ID
    @param[out]     running    1 while the action has unprocessed events
    @param[out]     code       When done: 0 if it succeeded, else why it
                               failed (an event outcome; -9 when aborted)

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_action_result(DbConnection_t* connection, int action_id, int* running, int* code)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    int values[3] = { 0, 0, 0 };
    int nulls[3] = { 0, 0, 0 };

    *running = 0;
    *code = OUTCOME_ACTION_ABORTED;

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT a.aborted, a.outcome, "
        "(SELECT COUNT(*) FROM events e WHERE e.action = a.id AND e.outcome = 0) "
        "FROM actions a WHERE a.id = %d", action_id);

    ErrorCode_t result = db_query_ints(connection, query_text, values, nulls, 3);
    if(result != ENGINE_OK)
        return result;

    if(!nulls[1])
        *code = values[1];               // failed: the first failing outcome
    else if(values[0])
        *code = OUTCOME_ACTION_ABORTED;  // stopped another way
    else if(values[2])
        *running = 1;                    // events still to process
    else
        *code = PERFORM_OK;

    return ENGINE_OK;
}

/** ****************************************************************************

    @brief          Lists new commands that have no delivery time yet

    @param[out]     ids, objects  Command IDs and where their drones are
    @param[out]     immediate     1 for engine resumes (not signals from Earth)
    @param[in]      max           Size of the arrays
    @param[out]     count         Number found

*******************************************************************************/
ErrorCode_t db_get_unstamped_commands(DbConnection_t* connection, int* ids, int* objects,
                                      int* immediate, int max, int* count)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    ErrorCode_t result = db_reconnect(connection);

    *count = 0;
    if(result != ENGINE_OK)
        return result;

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT c.id, a.object_id, c.code LIKE '%s%%' FROM commands c "
        "JOIN agents a ON a.agent_id = c.agent_id WHERE c.deliver_at IS NULL ORDER BY c.id LIMIT %d",
        engine_get_vm_resume_command(), max);

    if(mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }

    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    MYSQL_ROW row;
    while(db_result && *count < max && (row = mysql_fetch_row(db_result)))
    {
        ids[*count] = row[0] ? atoi(row[0]) : 0;
        objects[*count] = row[1] ? atoi(row[1]) : 0;
        immediate[*count] = row[2] ? atoi(row[2]) : 0;
        (*count)++;
    }
    if(db_result) mysql_free_result(db_result);

    return result;
}

/** ****************************************************************************

    @brief          Sets when a command reaches its drone: delay game seconds
                    from now (deliver_at is in game time)

*******************************************************************************/
ErrorCode_t db_set_command_delivery(DbConnection_t* connection, int command_id, int delay)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    ErrorCode_t result = db_reconnect(connection);

    if(result != ENGINE_OK)
        return result;

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "UPDATE commands SET deliver_at = %lld WHERE id = %d", game_time_now_seconds() + delay, command_id);

    if(mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        result = ENGINE_DB_QUERY_ERROR;
    }

    return result;
}

/** ****************************************************************************

    @brief          Queues a drone's reply to reach Earth delay real seconds
                    from now

*******************************************************************************/
ErrorCode_t db_queue_outbox(DbConnection_t* connection, int delay, const char* user_email,
                            const char* subject, const char* agent_name, const char* body)
{
    ErrorCode_t result = db_reconnect(connection);
    if(result != ENGINE_OK)
        return result;

    char* e_email = db_escape_alloc(connection, user_email);
    char* e_subject = db_escape_alloc(connection, subject ? subject : "");
    char* e_agent = db_escape_alloc(connection, agent_name);
    char* e_body = db_escape_alloc(connection, body);
    char* query_text = NULL;

    if(e_email && e_subject && e_agent && e_body)
    {
        size_t size = strlen(e_email) + strlen(e_subject) + strlen(e_agent) + strlen(e_body) + 256;
        query_text = malloc(size);
        if(query_text)
        {
            snprintf(query_text, size,
                "INSERT INTO outbox (send_at, user_email, subject, agent_name, body) "
                "VALUES (UNIX_TIMESTAMP() + %d, '%s', '%s', '%s', '%s')",
                delay, e_email, e_subject, e_agent, e_body);

            if(mysql_query(connection->hndl, query_text))
            {
                engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to queue a reply: %s", mysql_error(connection->hndl));
                result = ENGINE_DB_QUERY_ERROR;
            }
        }
        else
        {
            result = ENGINE_MEMORY_ALLOC_ERROR;
        }
    }
    else
    {
        result = ENGINE_MEMORY_ALLOC_ERROR;
    }

    free(e_email); free(e_subject); free(e_agent); free(e_body); free(query_text);
    return result;
}

/** ****************************************************************************

    @brief          Sends the queued replies that have reached Earth, oldest
                    first, and removes them from the queue

    @param[in]      script  The email script

*******************************************************************************/
ErrorCode_t db_send_due_outbox(DbConnection_t* connection, const char* script)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    ErrorCode_t result = db_reconnect(connection);

    if(result != ENGINE_OK)
        return result;

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT id, user_email, subject, agent_name, body FROM outbox "
        "WHERE send_at <= UNIX_TIMESTAMP() ORDER BY send_at, id LIMIT 50");

    if(mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }

    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    MYSQL_ROW row;
    while(db_result && (row = mysql_fetch_row(db_result)))
    {
        int id = row[0] ? atoi(row[0]) : 0;
        int error = email_run_script(script, row[1] ? row[1] : "", row[2] ? row[2] : "",
                                     row[3] ? row[3] : "", row[4] ? row[4] : "");

        if(error)
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to send queued reply [%d] (error [%d])", id, error);

        // Sent (or failed for good): don't send it again
        snprintf(query_text, DB_MAX_SQL_QUERY_LEN, "DELETE FROM outbox WHERE id = %d", id);
        if(mysql_query(connection->hndl, query_text))
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed", query_text);
    }
    if(db_result) mysql_free_result(db_result);

    return result;
}

/** ****************************************************************************

    @brief          Gets the unread rest of a drone's paused script (malloc'd,
                    NULL when none)

*******************************************************************************/
char* db_get_agent_pending_input(DbConnection_t* connection, int agent_id)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    char* text = NULL;

    if(db_reconnect(connection) != ENGINE_OK)
        return NULL;

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN, "SELECT pending_input FROM agents WHERE agent_id = %d", agent_id);
    if(mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return NULL;
    }

    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    MYSQL_ROW row = db_result ? mysql_fetch_row(db_result) : NULL;
    if(row && row[0] && row[0][0])
        text = strdup(row[0]);
    if(db_result) mysql_free_result(db_result);

    return text;
}

/** ****************************************************************************

    @brief          Sets (or clears, with NULL) the unread rest of a drone's
                    paused script

*******************************************************************************/
ErrorCode_t db_set_agent_pending_input(DbConnection_t* connection, int agent_id, const char* text)
{
    ErrorCode_t result = db_reconnect(connection);
    if(result != ENGINE_OK)
        return result;

    char* query_text = NULL;
    char* safe = text ? db_escape_alloc(connection, text) : NULL;
    size_t size = (safe ? strlen(safe) : 0) + 128;

    query_text = malloc(size);
    if(!query_text || (text && !safe))
    {
        free(query_text); free(safe);
        return ENGINE_MEMORY_ALLOC_ERROR;
    }

    if(safe)
        snprintf(query_text, size, "UPDATE agents SET pending_input = '%s' WHERE agent_id = %d", safe, agent_id);
    else
        snprintf(query_text, size, "UPDATE agents SET pending_input = NULL WHERE agent_id = %d", agent_id);

    if(mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to save pending input: %s", mysql_error(connection->hndl));
        result = ENGINE_DB_QUERY_ERROR;
    }

    free(query_text); free(safe);
    return result;
}

/** ****************************************************************************

    @brief          The latest game time of some drones' events, processed or
                    still to come (not failed): a new action starts after it,
                    so each drone's events keep game-time order by ID

*******************************************************************************/
ErrorCode_t db_get_latest_processed_game_t(const int* drones, int count, long long* game_t)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    char list[256] = "";
    ErrorCode_t result = db_reconnect(engine_get_db_connection());

    *game_t = 0;
    if(result != ENGINE_OK || count <= 0)
        return result;

    for(int i = 0; i < count; i++) {
        size_t len = strlen(list);
        snprintf(list + len, sizeof(list) - len, "%s%d", i ? "," : "", drones[i]);
    }
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT IFNULL(MAX(game_t), 0) FROM events WHERE drone IN (%s) AND outcome >= %d", list, OUTCOME_NO_OUTCOME);

    DbConnection_t* connection = engine_get_db_connection();
    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    MYSQL_ROW row = db_result ? mysql_fetch_row(db_result) : NULL;
    if(row && row[0])
        *game_t = atoll(row[0]);
    if(db_result)
        mysql_free_result(db_result);
    return ENGINE_OK;
}

/** ****************************************************************************

    @brief          Takes units from a mining deposit (#324 step 11)

    A deposit with no state yet is full. The amount is refilled lazily, by
    refill_per_day for the game time since it was last worked out (a
    fraction of a unit carries over), up to its capacity.

*******************************************************************************/
ErrorCode_t db_take_deposit(int object_id, int resource, long long wanted, long long* taken)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);
    long long now = game_time_now_seconds();

    *taken = wanted;
    if(result != ENGINE_OK)
        return result;

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT d.capacity, d.refill_per_day, s.amount, s.refilled_t FROM deposits d "
        "LEFT JOIN deposit_state s ON s.object_id = d.object_id AND s.resource = d.resource "
        "WHERE d.object_id = %d AND d.resource = %d", object_id, resource);
    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    if(!row) {
        if(res) mysql_free_result(res);
        return ENGINE_OK;   // no deposit here: as much as the abundance gives
    }
    double capacity = row[0] ? atof(row[0]) : 0;
    double refill = row[1] ? atof(row[1]) : 0;
    double amount = row[2] ? atof(row[2]) : capacity;
    long long since = row[3] ? now - atoll(row[3]) : 0;
    mysql_free_result(res);

    if(since > 0)
        amount += refill * (double)since / 86400.0;
    if(amount > capacity)
        amount = capacity;
    if(amount < 0)
        amount = 0;

    long long available = (long long)amount;   // whole units
    *taken = wanted < available ? wanted : available;
    amount -= (double)*taken;

    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "INSERT INTO deposit_state (object_id, resource, amount, refilled_t) VALUES (%d, %d, %.6f, %lld) "
        "ON DUPLICATE KEY UPDATE amount = VALUES(amount), refilled_t = VALUES(refilled_t)",
        object_id, resource, amount, now);
    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }

    engine_trace(TRACE_LEVEL_ALWAYS, "Deposit of resource [%d] at [%d]: [%lld] of [%lld] wanted taken, [%.1f] left",
        resource, object_id, *taken, wanted, amount);
    return ENGINE_OK;
}
