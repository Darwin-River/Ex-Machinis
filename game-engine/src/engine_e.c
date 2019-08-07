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

#include "event.h"
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

/** ****************************************************************************

  @brief      Processing of outcome events callback

  @param[in]  Current event

  @return     void

*******************************************************************************/
void engine_process_outcome(Event_t* event)
{
    // pass event to module
    event_process_outcome(event);
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

  @brief      Runs engine logic loop

  @param[in]  void

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_run()
{
  ErrorCode_t result = ENGINE_OK;

    engine.status = ENGINE_RUNNING_STATUS;

    // Run events engine

    engine_trace(TRACE_LEVEL_ALWAYS, "Running events engine logic");

    while(engine.status == ENGINE_RUNNING_STATUS)
    {
        // reset VM
        engine.last_agent.vm = NULL;

        // start a transaction
        result = db_start_transaction(&engine.db_connection);

        // process outcome events passing callback
        if(result == ENGINE_OK)
        {
            result = db_get_outcome_events(engine_process_outcome);
        }

        // Purge old events from DB
        if(result == ENGINE_OK)
        {
            result = db_purge_old_events();
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

        // Wait till next engine execution
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

/** ****************************************************************************

  @brief      Gets the configured value for events expiration days

  @param[in]  None
  
  @return     Configured days value or default if not found

*******************************************************************************/
int engine_get_events_expiration_days()
{
    return atoi(engine.config.params[EVENT_EXPIRATION_DAYS]);
}

/** ****************************************************************************

  @brief      Gets the number of outcome events to process per round

  @param[in]  None
  
  @return     Configured batch size (number of events)

*******************************************************************************/
int engine_get_outcome_events_batch_size()
{
    return atoi(engine.config.params[OUTCOME_EVENT_BATCH_SIZE]);
}