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

#include "engine_e.h"
#include "db.h"

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

  @brief      Initializes DB connection module

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

#ifdef ENABLED

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

#endif

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

#ifdef ENABLED
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
            // Check the type of command to see if we can go ahead or the user must retry later
            // ABORT commands are processed always (empty strings)
            if(engine.last_command.code[0] && engine_is_current_vm_busy() == ENGINE_TRUE)
            {
                engine_vm_output_cb("Another command is running, retry later");
                out_buffer[0] = 0; // do not output more emails 
            }
            else
            {
                // Execute the last code in current VM
                memset(out_buffer, 0, ENGINE_MAX_BUF_SIZE+1);
                result = vm_run_command(engine.last_agent.vm, &engine.last_command, out_buffer, ENGINE_MAX_BUF_SIZE);

                if(result != ENGINE_OK)
                {
                    engine_vm_output_cb("Command error");
                }

                // When it is an abort, clean pending commands at DB 
                if(engine.last_command.code[0] == 0)
                { 
                    // clear any pending resume command
                    db_delete_resume_commands(&engine.db_connection, engine.last_command.agent_id);
                }
            }
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
        result = db_delete_command(&engine.db_connection, &engine.last_command);

#endif 

        // commit or rollback current transaction
        if((result == ENGINE_OK) || (result == ENGINE_DB_NOT_FOUND_ERROR))
        {
            result = db_commit_transaction(&engine.db_connection);
        }
        else
        {
            result = db_rollback_transaction(&engine.db_connection);
        }

#ifdef ENABLED
        // always deallocate VM and command resources
        if(engine.last_agent.vm) vm_free(engine.last_agent.vm);
        if(engine.last_command.email_content)
            engine_free(engine.last_command.email_content, strlen(engine.last_command.email_content) + 1);

#endif // ENABLED        

        sleep(atoi(engine.config.params[ENGINE_CHECK_TIME]));
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

  @brief      Gets the command defined (at config) as VM resume command
              It is an special command that is not executed at the VM, it just 
              indicates that we need to resume VM to continue executing pending commands

  @param[in]  None
  
  @return     Command string

*******************************************************************************/
const char* engine_get_vm_resume_command()
{
    return (const char*)""; // not used at events engine
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


#ifdef ENABLED

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

  @brief      Gets current drone ID (drone processing commands)

  @param[in]  None

  @return     Drone ID

*******************************************************************************/
int engine_get_current_drone_id()
{
    return engine.last_agent.object_id;
}

#endif // ENABLED

