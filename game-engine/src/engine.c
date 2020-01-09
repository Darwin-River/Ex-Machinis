/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Engine module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <stdio.h>
#include <stdlib.h>
#include <signal.h>
#include <sys/types.h>
#include <unistd.h>
#include <stdarg.h>
#include <string.h>

#include "engine.h"
#include "config.h"
#include "db.h"
#include "vm.h"
#include "email.h"
#include "orbits.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/

// env variable
const char* PLAT_HOME = "PLAT_HOME";

Engine_t engine;

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief      Callback invoked when supported signal is received
              We do cleanup and exit properly

  @param[in]  signal_id  ID of signal received

  @return     void

*******************************************************************************/
void signals_handler(int signal_id)
{
    engine_trace(TRACE_LEVEL_ALWAYS, "Signal [%d] received", signal_id);
    engine_stop();
}

/** ****************************************************************************

  @brief      Initializes engine traces module

  @param[in]  void

  @return     Execution result 

*******************************************************************************/
ErrorCode_t engine_init_traces()
{
	ErrorCode_t result = ENGINE_OK;

    // Define traces configuration
    engine.trace_conf.level = TRACE_LEVEL_DEBUG;
    snprintf(engine.trace_conf.log_file_path, 
    	PATH_MAX, 
    	"%s/log/%s.log",
    	engine.plat_home,
    	engine.exe_name);


    result = trace_init(&engine.trace_conf, &engine.trace_hndl);
	
	return result;
}

/** ****************************************************************************

  @brief      Initializes configuration module

  @param[in]  None

  @return     void

*******************************************************************************/
ErrorCode_t engine_init_config()
{
	ErrorCode_t result = ENGINE_OK;

	// set config file name
	snprintf(engine.options.conf_file_path, 
    	PATH_MAX, 
    	"%s/conf/%s.conf",
    	engine.plat_home,
    	engine.exe_name);

    // load config
    result = config_engine_read(&engine.conf_hndl, 
    	engine.options.conf_file_path, 
    	engine.config.params);

    return result;
}


/** ****************************************************************************

  @brief      Initializes configuration module

  @param[in]  None

  @return     void

*******************************************************************************/
ErrorCode_t engine_init_db()
{
	ErrorCode_t result = ENGINE_OK;

    // initialize DB parameters
    engine.db_connection.hndl = NULL;
    engine.db_connection.host = engine.config.params[DB_HOST_ID];
    engine.db_connection.port = atoi(engine.config.params[DB_PORT_ID]);
    engine.db_connection.user = engine.config.params[DB_USER_ID];
    engine.db_connection.password = engine.config.params[DB_PASSWORD_ID];
    engine.db_connection.db_name = engine.config.params[DB_NAME_ID];

	// db
	result = db_init(&engine.db_connection);

	return result;
}

/** ****************************************************************************

  @brief      Stops engine submodules

  @param[in]  None

  @return     void

*******************************************************************************/
void engine_stop_all()
{
	// DB
    db_stop(&engine.db_connection);

    // CONFIG
    config_engine_stop(&engine.conf_hndl);

	engine.status = ENGINE_STOPPED_STATUS;

	engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------");
    engine_trace(TRACE_LEVEL_ALWAYS, "Engine stopped (PID [%d])", getpid());
    engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------");

    // TRACES
    trace_stop(engine.trace_hndl);
}

/** ****************************************************************************

  @brief      Inserts a new command into DB (commands table)

  @param[in]  command String to be inserted

  @return     void

*******************************************************************************/
void engine_insert_db_command(char* command)
{
    if(command)
    {
        Command_t new_command;
        memcpy(&new_command, &engine.last_command, sizeof(new_command));
        sprintf(new_command.code, "%s", command);
        size_t size = strlen(engine.last_command.email_content) + 1;
        new_command.email_content = engine_malloc(size);
        sprintf(new_command.email_content, "%s", engine.last_command.email_content);
        db_insert_command(&engine.db_connection, &new_command);
        engine_free(new_command.email_content, size);
        new_command.email_content = NULL;
    }
}

/** ****************************************************************************

  @brief      Checks if current VM is busy and can not process more commands

  @param[in]  None

  @return     boolean result (true when busy)

*******************************************************************************/
Bool_t engine_is_current_vm_busy()
{
    const char* resume_comand = engine_get_vm_resume_command();

    // Check that current command is not a resume command and also if there are resume commands at DB
    if(strncmp(engine.last_command.code, resume_comand, strlen(resume_comand)))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Command [%s] is NOT a [%s], check if VM is running other commands",
            engine.last_command.code,
            resume_comand);

        // check if we have any pending command
        return db_agent_has_running_command(&engine.db_connection, engine.last_command.agent_id);
    }

    return ENGINE_FALSE;
}

/** ****************************************************************************

  @brief          Gets the position of a given object ID (body and distance from Earth)

  @param[in]      object_id     Current object ID (drone object ID)
  @param[out]     position      Output buffer where we store current position
  @param[out]     distance      Output buffer where we store current distance from Earth

  @return         Error code

*******************************************************************************/
ErrorCode_t engine_get_drone_position(int object_id, char* position, double* distance)
{
    ErrorCode_t result = ENGINE_INTERNAL_ERROR;

    if(position && distance) {
        ObjectOrbitInfo_t drone;
        ObjectOrbitInfo_t object;
        ObjectOrbitInfo_t sun;
        ObjectOrbitInfo_t earth;
        CartesianInfo_t final_position;
        int objects_num = 0;
        Bool_t done = ENGINE_FALSE;

        result = ENGINE_OK; // default OK

        // Obtain the Sun info from DB 
        memset(&sun, 0, sizeof(ObjectOrbitInfo_t));

        if(db_get_object_info_by_name(&engine.db_connection, "Sun", &sun) != ENGINE_OK) {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to obtain SUN position");
            result = ENGINE_DB_QUERY_ERROR;
        }

        if(result == ENGINE_OK) {
            engine_trace(TRACE_LEVEL_ALWAYS,  "[%s] has OBJ_ID=[%d]", sun.object_name, sun.object_id);

            // Obtain the drone position from this object ID
            memset(&object, 0, sizeof(ObjectOrbitInfo_t));
            memset(&final_position, 0, sizeof(CartesianInfo_t));
            object.object_id = object_id;

            do {
                // Get current object info
                if(db_get_orbit_info(&engine.db_connection, &object) != ENGINE_OK) {
                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "ERROR: Unable to obtain position for drone with OBJ_ID=[%d]",
                        object.object_id);

                    result = ENGINE_DB_QUERY_ERROR;
                } else {
                    // Save drone position at first search (safe = same size)
                    if(objects_num++ == 0) {
                        memcpy(&drone, &object, sizeof(ObjectOrbitInfo_t));
                        sprintf(position, "%s", drone.object_name);
                    }

                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "OBJ_ID=[%d] CENTRAL_BODY_OBJ_ID=[%d] SUN_OBJECT_ID=[%d]",
                        object.object_id, object.central_body_object_id, sun.object_id);

                    // Use current object info to calculate position
                    CartesianInfo_t position;
                    orbits_get_object_position(&object, &position);

                    // Add coordinates to current values
                    CartesianInfo_t current_total_position;
                    current_total_position.x = final_position.x;
                    current_total_position.y = final_position.y;
                    current_total_position.z = final_position.z;

                    orbits_add_coordinates(&position, &current_total_position, &final_position);

                    // Check if our central body is the sun to stop
                    if(object.central_body_object_id == sun.object_id) {
                        done = ENGINE_TRUE;
                    } else {
                        // update object ID to do next search
                        object.object_id = object.central_body_object_id;
                    }
                }

            } while(done == ENGINE_FALSE);
            
        }

        if(result == ENGINE_OK) {
            engine_trace(TRACE_LEVEL_ALWAYS,  "OBJ_ID=[%d] is at [%s]", drone.object_id, drone.object_name);

            // Obtain Earth info
            memset(&earth, 0, sizeof(ObjectOrbitInfo_t));

            if(db_get_object_info_by_name(&engine.db_connection, "Earth", &earth) != ENGINE_OK) {
                engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to obtain EARTH position");
                result = ENGINE_DB_QUERY_ERROR;
            }
        } 

        if(result == ENGINE_OK) {
            engine_trace(TRACE_LEVEL_ALWAYS,  "OBJ_ID=[%d] is at [%s]", earth.object_id, earth.object_name);

            // Get distance between Earth <-> drone
            *distance = orbits_get_distance_from_object(&earth, &final_position);
        } 

        
    }

    return result;
}

/** ****************************************************************************

  @brief      Processes a VM command (command to be executed on the VM)

  @param[in|out]  out_buf       Input/output buffer where we will store email response
  @param[in]      out_buf_size  Buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_process_vm_command(char* out_buf, size_t out_buf_size)
{
    ErrorCode_t result = ENGINE_OK;

    engine_trace(TRACE_LEVEL_ALWAYS, "Processing VM command [%s] at agent [%d]", 
        engine.last_command.code, engine.last_command.agent_id);

    // When busy, wait
    if(engine_is_current_vm_busy() == ENGINE_TRUE)
    {
        engine_vm_output_cb("Another command is running, retry later");
        out_buf[0] = 0; // do not output more emails 
        return ENGINE_OK;
    }

    // VM command & not busy -> execute it and take the suitable actions
    result = vm_run_command(engine.last_agent.vm, &engine.last_command, out_buf, out_buf_size);

    AgentInfo_t info;
    // Update drone info (just in case, we executed a location change)
    if(result == ENGINE_OK)
    {
        // Get current values
        result = db_get_agent_engine_info(&engine.db_connection, 
            engine.last_command.agent_id,
            &info);
    }

    if(result == ENGINE_OK)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Drone [%d] moving from objectId [%d] to [%d]",
            engine.last_command.agent_id,
            engine.last_agent.object_id,
            info.object_id);

        // Update object ID to use it when calculating distances and sending email
        engine.last_agent.object_id = info.object_id;
    }

    return result;
}

/** ****************************************************************************

  @brief      Processes rename meta command

  @param[in|out]  out_buf       Input/output buffer where we will store email response
  @param[in]      out_buf_size  Buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_process_meta_rename(char* out_buf, size_t out_buf_size)
{
    ErrorCode_t result = ENGINE_OK;
    ErrorCode_t db_result = ENGINE_OK;

    // input params checked at calling function

    // Process rename
    // Get the new name
    char *start = strchr(engine.last_command.code, '>');
    start++;
    char *end = strchr(start, '<');
    char *name = (char*) engine_malloc(end - start + 1);
    snprintf(name, (end - start + 1), "%s", start);
    engine_trace(TRACE_LEVEL_ALWAYS, "Changing agent [%d] name to [%s]", engine.last_command.agent_id, name);

    // validate new name: can not contain @ and must be unique
    if(strchr(name, '@')) {
        snprintf(out_buf, out_buf_size, "Command error: invalid char @ at agent name [%s]", name);
    } else if(strcspn (name, " \t\r\n") != strlen(name)) {
        snprintf(out_buf, out_buf_size, "Command error: agent name can not contain spaces/tabs/newlines [%s]", name);
    } else if ((db_result=db_check_agent_name(&engine.db_connection, name)) == ENGINE_OK) {
        snprintf(out_buf, out_buf_size, "Command error: agent name [%s] already exists", name);
    } else if (db_result == ENGINE_DB_NOT_FOUND_ERROR) {
        // valid name -> update it
        result = db_update_agent_name(&engine.db_connection, engine.last_command.agent_id, name);
        if(result == ENGINE_OK) {
            snprintf(out_buf, out_buf_size, "Agent name changed to [%s]", name);
        }
        // Update agent name to keep the emails chain
        AgentInfo_t info;
        if(result == ENGINE_OK)
        {
            // Get current values
            result = db_get_agent_engine_info(&engine.db_connection, 
                engine.last_command.agent_id,
                &info);
        }

        if(result == ENGINE_OK)
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "Agent name updated [%s] -> [%s]",
                engine.last_agent.agent_name,
                info.agent_name);

            // Update name to answer using this new one
            sprintf(engine.last_agent.agent_name, "%s", info.agent_name);
        }
    }

    if(name) engine_free(name, (strlen(name)+1));

    return result;
}

/** ****************************************************************************

  @brief      Processes rebrand meta command

  @param[in|out]  out_buf       Input/output buffer where we will store email response
  @param[in]      out_buf_size  Buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_process_meta_rebrand(char* out_buf, size_t out_buf_size)
{
    ErrorCode_t result = ENGINE_OK;

    // Process rebrand
    // Get the new company name
    char *start = strchr(engine.last_command.code, '>');
    start++;
    char *end = strchr(start, '<');
    char *company_name = (char*) engine_malloc(end - start + 1);
    snprintf(company_name, (end - start + 1), "%s", start);
    engine_trace(TRACE_LEVEL_ALWAYS, "Changing agent [%d] company to [%s]", engine.last_command.agent_id, company_name);

    ErrorCode_t db_result = db_check_company_name(&engine.db_connection, company_name);

    if (db_result == ENGINE_OK) {
        snprintf(out_buf, out_buf_size, "Command error: company name [%s] already exists", company_name);
        result = ENGINE_LOGIC_ERROR;
    } else if(db_result == ENGINE_DB_NOT_FOUND_ERROR) {
        result = db_update_agent_company(&engine.db_connection, engine.last_command.agent_id, company_name);
        if(result == ENGINE_OK) {
            snprintf(out_buf, out_buf_size, "Agent company name changed to [%s]", company_name);
        }
    }
    engine_free(company_name, (strlen(company_name)+1));

    return result;
}

/** ****************************************************************************

  @brief      Processes a meta command (command NOT execute on the VM)

  @param[in|out]  out_buf       Input/output buffer where we will store email response
  @param[in]      out_buf_size  Buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_process_meta_command(char* out_buf, size_t out_buf_size)
{
    ErrorCode_t result = ENGINE_OK;

    // input params checked at calling function

    engine_trace(TRACE_LEVEL_ALWAYS, "Processing meta command [%s] at agent [%d]", 
        engine.last_command.code, engine.last_command.agent_id);

    // Identify the meta-command
    if(strcasecmp(engine.last_command.code, META_COMMAND_ABORT) == 0) {
        engine_trace(TRACE_LEVEL_ALWAYS, "Running abort command at agent [%d]", engine.last_command.agent_id);
        // abort and delete any pending resume
        vm_abort(engine.last_agent.vm);
        result = db_delete_resume_commands(&engine.db_connection, engine.last_command.agent_id);
    } else if (strstr(engine.last_command.code, META_COMMAND_RESET)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "Running reset command at agent [%d]", engine.last_command.agent_id);
        // reset and start with a fresh VM
        vm_reset(engine.last_agent.vm);
        if(engine.last_agent.vm) vm_free(engine.last_agent.vm);
        engine.last_agent.vm = NULL;
        result = db_save_agent_vm(&engine.db_connection, engine.last_command.agent_id, engine.last_agent.vm);
    } else if (strstr(engine.last_command.code, META_COMMAND_RENAME)) {
        result = engine_process_meta_rename(out_buf, out_buf_size);
    } else if (strstr(engine.last_command.code, META_COMMAND_REBRAND)) {
        result = engine_process_meta_rebrand(out_buf, out_buf_size);
    }

    return result;
}

/** ****************************************************************************

  @brief      Processes a command read from DB and generates output for email

  @param[in|out]  out_buf       Input/output buffer where we will store email response
  @param[in]      out_buf_size  Buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_process_command(char* out_buf, size_t out_buf_size)
{
    ErrorCode_t result;

    if(!out_buf || (out_buf_size == 0)) {
        engine_trace(TRACE_LEVEL_ALWAYS,  "ERROR: Unable to process command (invalid buffer)");
        return ENGINE_INTERNAL_ERROR;
    }

    // We need to identify if it is a VM command or just a meta-command
    if(engine.last_command.code[0] == '<') {
        // meta command
        result = engine_process_meta_command(out_buf, out_buf_size);
    } else {
        // VM command
        result = engine_process_vm_command(out_buf, out_buf_size);
    }

    // When processing fails, indicate it at email
    if(result != ENGINE_OK && !out_buf[0])
    {
        engine_vm_output_cb("Command error");
    }

    return result;
}

/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Initializes engine modules using command line options received

  @param[in]  argc, argv Command line options

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_init(int argc, char** argv)
{
	// Initialize engine
	memset(&engine, 0, sizeof(engine));

	engine.status = ENGINE_IDLE_STATUS;

	engine.argc = argc;
	engine.argv = argv;

	engine.error = ENGINE_OK;

    // Get the basename
    engine.exe_name = argv[0];
    char* pos = strrchr(argv[0], '/');
    if(pos)
    {
        engine.exe_name = ++pos;
    }

	// Check PLAT_HOME - mandatory
	engine.plat_home = getenv(PLAT_HOME);

	if(!engine.plat_home)
	{
		engine.error = ENGINE_PLAT_HOME_ERROR;
	}

    if(engine.error == ENGINE_OK)
    {
		signal(SIGINT, signals_handler);
    	signal(SIGTERM, signals_handler);

    	// Define exit callback
    	atexit(engine_stop);
    }

    // Initialize traces
    if(engine.error == ENGINE_OK)
    {
    	engine.error = engine_init_traces();
    }

    engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------------");
    engine_trace(TRACE_LEVEL_ALWAYS, 
        "Engine started (NAME [%s] PID [%d])", engine.exe_name, getpid());
    engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------------");

    // Initialize config
    if(engine.error == ENGINE_OK)
    {
    	engine.error = engine_init_config();
    }

    // update trace level using conf
    trace_set_level(engine.trace_hndl, 
        atoi(engine.config.params[APP_TRACE_LEVEL_ID]));

    // Initialize DB connection
    if(engine.error == ENGINE_OK)
    {
    	engine.error = engine_init_db();
    }

    // stop-all when error
    if(engine.error != ENGINE_OK)
    {
    	engine_stop_all();
    }

	return engine.error;
}

/** ****************************************************************************

  @brief      For a given command read from DB we apply max lines limitation
              and store the rest in the DB again.

  @param[in]  void

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_apply_max_cycle_lines()
{
    ErrorCode_t result = ENGINE_OK;

    // Get configured limit
    int max_lines = 10000; //atoi(engine.config.params[MAX_LINES_PER_CYCLE_ID]);

    engine_trace(TRACE_LEVEL_ALWAYS, 
        "Applying [%d] lines limit to command [%s]",
        max_lines,
        engine.last_command.code);

    // Extract both the code to be executed and the remaining
    char new_code[MAX_COMMAND_CODE_SIZE+1];
    char remaining_code[MAX_COMMAND_CODE_SIZE+1];

    new_code[0] = 0;
    remaining_code[0] = 0;


    // go line by line
    char* buffer = strdup(engine.last_command.code);
    char* line = strtok(buffer, "\r\n");
    int lines_read = 0;

    char* new_pos = new_code;
    char* remaining_pos = remaining_code;

    while(line) {
        lines_read++;
        if(lines_read <= max_lines) {
            snprintf(new_pos, MAX_COMMAND_CODE_SIZE, "%s\n", line);
            new_pos += (strlen(line) + 1);
        } else {         
            snprintf(remaining_pos, MAX_COMMAND_CODE_SIZE, "%s\n", line);
            remaining_pos += (strlen(line) + 1);
        }

        // Get next one
        line  = strtok(NULL, "\n");
    }

    engine_trace(TRACE_LEVEL_ALWAYS, 
        "New code [%s] remaining [%s]",
        new_code,
        remaining_code);

    // Make new code = current and store remaining as new command
    sprintf(engine.last_command.code, "%s", new_code);

    if(remaining_code[0] != 0) {
        Command_t new_command;
        memcpy(&new_command, &engine.last_command, sizeof(new_command));
        sprintf(new_command.code, "%s", remaining_code);
        new_command.email_content = strdup(engine.last_command.email_content);

        result = db_insert_command(&engine.db_connection, &new_command);

        free(new_command.email_content);
    }
    
    free(buffer);

    return result;
}


/** ****************************************************************************

  @brief      Runs engine logic loop

  @param[in]  void

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_run()
{
  ErrorCode_t result = ENGINE_OK;

	engine.status = ENGINE_RUNNING_STATUS;

    // Checks periodically for commands, executes them and stores VM status

	while(engine.status == ENGINE_RUNNING_STATUS)
	{
        // reset VM
        engine.last_agent.vm = NULL;

		engine_trace(TRACE_LEVEL_ALWAYS, "Running engine logic");

        // start a transaction
        result = db_start_transaction(&engine.db_connection);

        if(result == ENGINE_OK)
        {
            // get next command
            result = db_get_next_command(&engine.db_connection, &engine.last_command);
        }

        if(result == ENGINE_OK)
        {
            // apply configured max lines per cycle
            result = engine_apply_max_cycle_lines();

            if(result != ENGINE_OK)
            {
                engine_vm_output_cb("Internal error");
            }
        }


        if(result == ENGINE_OK)
        {
            // Get whole info for agent_id
            result = db_get_agent_engine_info(&engine.db_connection, 
                engine.last_command.agent_id,
                &engine.last_agent);
        }

        if(result == ENGINE_OK)
        {
            // save last agent input & subject command info
            result = db_update_agent_input(&engine.db_connection, 
                engine.last_command.agent_id, 
                &engine.last_command);
        }            

        char out_buffer[ENGINE_MAX_BUF_SIZE+1];
        if(result == ENGINE_OK)
        {
            // Process input command and retrieve result text in out_buffer
            memset(out_buffer, 0, ENGINE_MAX_BUF_SIZE+1);
            engine_process_command(out_buffer, ENGINE_MAX_BUF_SIZE);
        } 

        if(result == ENGINE_OK)
        {
            if(vm_is_yield()) 
            {
                // update out buffer if any 
                if(out_buffer[0] != 0) 
                    db_update_agent_output(&engine.db_connection, 
                        engine.last_command.agent_id, (char*)out_buffer);

                // When we yield - insert special command to indicate that we need to resume later
                engine_insert_db_command(engine.config.params[VM_RESUME_COMMAND]);
            }
            else if(out_buffer[0] != 0) 
            {
                engine_vm_output_cb(out_buffer);
            }
        }           

        if(result == ENGINE_OK)
        {
            // Save VM in DB
            result = db_save_agent_vm(&engine.db_connection, 
                engine.last_command.agent_id, 
                engine.last_agent.vm);
        }

        // Delete command always to avoid spam when error
        if(engine.last_command.code[0]) {
            result = db_delete_command(&engine.db_connection, &engine.last_command);
        }

        // commit or rollback current transaction
        if((result == ENGINE_OK) || (result == ENGINE_DB_NOT_FOUND_ERROR))
        {
            result = db_commit_transaction(&engine.db_connection);
        }
        else
        {
            result = db_rollback_transaction(&engine.db_connection);
        }

        // always deallocate VM and command resources
        if(engine.last_agent.vm) vm_free(engine.last_agent.vm);
        if(engine.last_command.email_content) {
            engine_free(engine.last_command.email_content, strlen(engine.last_command.email_content) + 1);
            engine.last_command.email_content = NULL;
        }

		sleep(atoi(engine.config.params[DB_READ_TIME_ID]));
	}

	// deallocate resources/Stop modules
    engine_stop_all();
	
	return engine.error;
}

/** ****************************************************************************

  @brief      Stops engine execution

  @param[in]  void

  @return     void

*******************************************************************************/
void engine_stop()
{
	if((engine.status != ENGINE_STOPPING_STATUS) && 
		(engine.status != ENGINE_STOPPED_STATUS))
	{
		engine.status = ENGINE_STOPPING_STATUS;

		engine_trace(TRACE_LEVEL_ALWAYS, "Engine is stopping...");
	}
}

/** ****************************************************************************

  @brief      Checks if engine is stopped

  @param[in]  void

  @return     Boolean result

*******************************************************************************/
Bool_t engine_is_stopped()
{
	if(engine.status != ENGINE_RUNNING_STATUS)
		return ENGINE_TRUE;

	return ENGINE_FALSE;
}

/** ****************************************************************************

  @brief      Gets last engine error

  @param[in]  void

  @return     Last error code

*******************************************************************************/
int engine_get_last_error()
{
	return engine.error;
}

/** ****************************************************************************

  @brief      Logs an engine trace in output log file

  @param[in]  level Trace level
  @param[in]  trace Trace format text
  @param[in]  ...   Variable list of arguments

  @return     void

*******************************************************************************/
void engine_trace(TraceLevel_t level, const char *trace, ... )
{
	static va_list valist;
	
	va_start(valist, trace);
	trace_write(engine.trace_hndl, level, trace, valist);
	va_end(valist);
}

/** ****************************************************************************

  @brief      Initializes a log line only with date and level, this function must be 
              called before using trace_append

  @param[in]  level  Trace level
  
  @return     void

*******************************************************************************/
void engine_trace_header(TraceLevel_t level)
{
    trace_header(engine.trace_hndl, level);
}

/** ****************************************************************************

  @brief      Appends more text to current log line, no new line is added

  @param[in]  level Trace level
  @param[in]  trace Trace format text
  @param[in]  ...   Variable list of arguments

  @return     void

*******************************************************************************/
void engine_trace_append(TraceLevel_t level, const char *trace, ... )
{
    static va_list valist;
    
    va_start(valist, trace);
    trace_append(engine.trace_hndl, level, trace, valist);
    va_end(valist);
}

/** ****************************************************************************

  @brief      Function used to send VM output to users

  @param[in]  msg      Output msg

  @return     void

*******************************************************************************/
void engine_vm_output_cb(const char* msg)
{
    int agent_id = engine.last_command.agent_id;

    if(msg)
    {
        // Apply a workaround to remove the annoying "query redefined msg"
        char* pos = strstr(msg, "query redefined");
        if(pos) *pos = 0;

        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Output VM msg [%s] read for agent [%d]",
            msg,
            agent_id); 

        db_update_agent_output(&engine.db_connection, agent_id, (char*)msg);

        // Get agent email info and send it by email
        EmailInfo_t email_info;
        memset(&email_info, 0, sizeof(email_info));

        // Fill the info we have so far
        email_info.agent_id = agent_id;
        // email format
        snprintf(email_info.email_template, 
            MAX_COMMAND_CODE_SIZE, 
            "%s", 
            engine.config.params[SEND_EMAIL_TEMPLATE_ID]);


        // Get current position
        engine_get_drone_position(engine.last_agent.object_id, email_info.drone_position, &email_info.distance);

        snprintf(email_info.message, MAX_COMMAND_CODE_SIZE, "%s", msg); // it is quoted later at email module
        snprintf(email_info.email_script, PATH_MAX, "%s", engine.config.params[SEND_EMAIL_SCRIPT_ID]);

        if(db_get_agent_email_info(&engine.db_connection, &email_info) == ENGINE_OK)
        {
            // agent email
            snprintf(email_info.agent_email, 
                MAX_EMAIL_ADDRESS_SIZE, 
                "%s@%s", 
                email_info.agent_name,
                engine.config.params[AGENTS_EMAIL_DOMAIN_ID]); 

            // send email
            email_send(&email_info);
        }

        if(email_info.input_content) {
            engine_free(email_info.input_content, strlen(email_info.input_content)+1);
            engine.last_command.email_content = NULL;
        }
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "WARNING: NULL msg received from VM for agent [%d]",
            agent_id); 
    }
}

/** ****************************************************************************

  @brief      Allocation wrapper to control memory used by the engine

  @param[in]  size Size we want to allocate
  
  @return     Pointer to memory allocated or NULL when failed

*******************************************************************************/
void* engine_malloc(size_t size)
{
    void* memory = malloc(size);

    if(memory)
    {
        engine.memory_in_use += size; 

        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Allocated [%ld] bytes (Total memory: [%ld])",
            size,
            engine.memory_in_use);
    }

    return memory;
}

/** ****************************************************************************

  @brief      Free wrapper to control memory used by the engine

  @param[in]  Pointer to memory we want to deallocate
  
  @return     void

*******************************************************************************/
void engine_free(void* memory, size_t size)
{
    if(memory)
    {
        free(memory);

        engine.memory_in_use -= size; 

        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Deallocated [%ld] bytes (Total memory in use: [%ld])",
            size,
            engine.memory_in_use);
    }
}

/** ****************************************************************************

  @brief      Gets path of the forth image used as template to create VM

  @param[in]  None
  
  @return     Path string

*******************************************************************************/
const char* engine_get_forth_image_path()
{
    return (const char*)engine.config.params[FORTH_IMAGE_PATH_ID];
}

/** ****************************************************************************

  @brief      Gets the max number of second each VM can spend processing commands

  @param[in]  None
  
  @return     Seconds value configured

*******************************************************************************/
const int engine_get_max_cycle_seconds()
{
    return atoi((const char*)engine.config.params[MAX_CYCLE_SECONDS]);
}

/** ****************************************************************************

  @brief      Gets the command defined (at config) as VM resume command
              It is an special command that is not executed at the VM, it just 
              indicates that we need to resume VM to continue executing pending commands

  @param[in]  None
  
  @return     Command string

*******************************************************************************/
const char* engine_get_vm_resume_command()
{
    return (const char*)engine.config.params[VM_RESUME_COMMAND];
}

/** ****************************************************************************

  @brief      Gets current engine DB connection

  @param[in]  None

  @return     Current DB connection info

*******************************************************************************/
DbConnection_t* engine_get_db_connection()
{
    if(!engine.db_connection.hndl)
        engine_init_db();

    return &engine.db_connection;
}

/** ****************************************************************************

  @brief      Gets current drone ID (drone processing commands)

  @param[in]  None

  @return     Drone ID

*******************************************************************************/
int engine_get_current_drone_id()
{
    return engine.last_command.agent_id;
}


/** ****************************************************************************

  @brief      Gets the flag (0/1) that controls VM dump into disk

  @param[in]  None
  
  @return     Flag value configured

*******************************************************************************/
const int engine_get_dump_vm_flag()
{
    return atoi((const char*)engine.config.params[FORTH_DUMP_VM]);
}

/** ****************************************************************************

  @brief      Gets the configured path to dump VM into memory

  @param[in]  None
  
  @return     Path value configured

*******************************************************************************/
const char* engine_get_dump_vm_path()
{
    return (const char*)engine.config.params[FORTH_VM_OUT_PATH];
}

