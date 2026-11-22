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
#include "db_station.h"
#include "db_compute.h"
#include "db_shares.h"
#include "db_wreck.h"
#include "game_time.h"
#include "db.h"
#include "db_e.h"
#include "event_heap.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/

// env variable
static const char* PLAT_HOME = "PLAT_HOME";

Engine_t ee_engine;

static void ee_engine_startup_work(void);

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief      Callback invoked when supported signal is received
              We do cleanup and exit properly

  @param[in]  signal_id  ID of signal received

  @return     void

*******************************************************************************/
void ee_signals_handler(int signal_id)
{
    ee_engine_trace(TRACE_LEVEL_ALWAYS, "Signal [%d] received", signal_id);
    ee_engine_stop();
}

/** ****************************************************************************

  @brief      Initializes engine traces module

  @param[in]  void

  @return     Execution result 

*******************************************************************************/
ErrorCode_t ee_engine_init_traces()
{
	ErrorCode_t result = ENGINE_OK;

    // Define traces configuration
    ee_engine.trace_conf.level = TRACE_LEVEL_DEBUG;
    snprintf(ee_engine.trace_conf.log_file_path, 
    	PATH_MAX, 
    	"%s/log/%s.log",
    	ee_engine.plat_home,
    	ee_engine.exe_name);


    result = trace_init(&ee_engine.trace_conf, &ee_engine.trace_hndl);
	
	return result;
}

/** ****************************************************************************

  @brief      Initializes configuration module

  @param[in]  None

  @return     void

*******************************************************************************/
ErrorCode_t ee_engine_init_config()
{
	ErrorCode_t result = ENGINE_OK;

	// set config file name
	snprintf(ee_engine.options.conf_file_path, 
    	PATH_MAX, 
    	"%s/conf/%s.conf",
    	ee_engine.plat_home,
    	ee_engine.exe_name);

    // load config
    result = ee_config_engine_read(&ee_engine.conf_hndl, 
    	ee_engine.options.conf_file_path, 
    	ee_engine.config.params);

    return result;
}

/** ****************************************************************************

  @brief      Initializes DB connection module

  @param[in]  None

  @return     void

*******************************************************************************/
ErrorCode_t ee_engine_init_db()
{
	ErrorCode_t result = ENGINE_OK;

    // initialize DB parameters
    ee_engine.db_connection.hndl = NULL;
    ee_engine.db_connection.host = ee_engine.config.params[DB_HOST_ID];
    ee_engine.db_connection.port = atoi(ee_engine.config.params[DB_PORT_ID]);
    ee_engine.db_connection.user = ee_engine.config.params[DB_USER_ID];
    ee_engine.db_connection.password = ee_engine.config.params[DB_PASSWORD_ID];
    ee_engine.db_connection.db_name = ee_engine.config.params[DB_NAME_ID];
    ee_engine.db_connection.lock = NULL;
    ee_engine.db_connection.checked_at = 0;

	// db
	result = ee_db_init(&ee_engine.db_connection);

	// Only one events-engine per database
	if(result == ENGINE_OK) result = db_claim_engine(&ee_engine.db_connection, "events-engine");

	if(result == ENGINE_OK) ee_engine_startup_work();

	return result;
}

/** ****************************************************************************

  @brief      What the events engine does once it holds its lock, before its
              first pass (bin/events-engine, or the game engine's settlement)

  @param[in]  None

  @return     void

*******************************************************************************/
static void ee_engine_startup_work(void)
{
	ErrorCode_t result = ENGINE_OK;

	// The game clock (its scale is set by the game engine)
	game_time_load();

	// Tools left locked by actions aborted before this start (#748: an
	// outage locked round 71's Makers' Smelters, and they failed every make
	// until their companies dissolved)
	if(result == ENGINE_OK) {
		int freed = db_free_stuck_tools();
		if(freed > 0) ee_engine_trace(TRACE_LEVEL_ALWAYS, "Freed [%d] stuck tool holdings", freed);
	}
	// The locks in force, kept in memory for the decrement check (#814)
	if(result == ENGINE_OK) db_tool_locks_load();
}

/** ****************************************************************************

  @brief      Stops engine submodules

  @param[in]  None

  @return     void

*******************************************************************************/
void ee_engine_stop_all()
{
	// DB
    ee_db_stop(&ee_engine.db_connection);

    // CONFIG
    ee_config_engine_stop(&ee_engine.conf_hndl);

	ee_engine.status = ENGINE_STOPPED_STATUS;

	ee_engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------");
    ee_engine_trace(TRACE_LEVEL_ALWAYS, "Engine stopped (PID [%d])", getpid());
    ee_engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------");

    // TRACES
    trace_stop(ee_engine.trace_hndl);
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
ErrorCode_t ee_engine_init(int argc, char** argv)
{
	// Initialize engine
	memset(&ee_engine, 0, sizeof(ee_engine));

	ee_engine.status = ENGINE_IDLE_STATUS;

	ee_engine.argc = argc;
	ee_engine.argv = argv;

	ee_engine.error = ENGINE_OK;

    // Get the basename
    ee_engine.exe_name = argv[0];
    char* pos = strrchr(argv[0], '/');
    if(pos)
    {
        ee_engine.exe_name = ++pos;
    }

	// Check PLAT_HOME - mandatory
	ee_engine.plat_home = getenv(PLAT_HOME);

	if(!ee_engine.plat_home)
	{
		ee_engine.error = ENGINE_PLAT_HOME_ERROR;
	}

    if(ee_engine.error == ENGINE_OK)
    {
		signal(SIGINT, ee_signals_handler);
    	signal(SIGTERM, ee_signals_handler);

    	// Define exit callback
    	atexit(ee_engine_stop);
    }

    // Initialize traces
    if(ee_engine.error == ENGINE_OK)
    {
    	ee_engine.error = ee_engine_init_traces();
    }

    ee_engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------------");
    ee_engine_trace(TRACE_LEVEL_ALWAYS, 
        "Engine started (NAME [%s] PID [%d])", ee_engine.exe_name, getpid());
    ee_engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------------");

    // Initialize config
    if(ee_engine.error == ENGINE_OK)
    {
    	ee_engine.error = ee_engine_init_config();
    }

    // update trace level using conf
    trace_set_level(ee_engine.trace_hndl, 
    atoi(ee_engine.config.params[APP_TRACE_LEVEL_ID]));

    // Initialize DB connection
    if(ee_engine.error == ENGINE_OK)
    {
    	ee_engine.error = ee_engine_init_db();
    }

    // stop-all when error
    if(ee_engine.error != ENGINE_OK)
    {
    	ee_engine_stop_all();
    }

    return ee_engine.error;
}

/** ****************************************************************************

  @brief      Gets the events engine ready to run its passes

  @param[in]  void

  @return     void

*******************************************************************************/
void ee_init()
{
    ee_engine.status = ENGINE_RUNNING_STATUS;

    // Run events engine

    ee_engine_trace(TRACE_LEVEL_ALWAYS, "Running events engine logic");
}

/** ****************************************************************************

  @brief      Runs one pass of the events engine: a batch of due events, then
              the stations, the share exchange, the processor tax and the
              events archive when they are due. It never sleeps.

  @param[out] found  The number of due events the batch read

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t ee_pass(int* found)
{
    ErrorCode_t result = ENGINE_OK;

    // reset VM
    ee_engine.last_agent.vm = NULL;
    *found = 0;

    // Read the game clock (the game engine may have changed its scale)
    game_time_load();

    // start a transaction
    result = ee_db_start_transaction(&ee_engine.db_connection);

    // process outcome events passing callback
    if(result == ENGINE_OK)
    {
        event_batch_start();
        result = db_get_outcome_events(engine_process_outcome, found);
        // A passing database error (#711): roll the batch back and try again
        if(result == ENGINE_OK && event_batch_retry())
            result = ENGINE_DB_QUERY_ERROR;
    }

    // Observations and places, written once for the batch (#448)
    if((result == ENGINE_OK) || (result == ENGINE_DB_NOT_FOUND_ERROR))
    {
        ErrorCode_t written = db_flush_batch_writes();
        if(written != ENGINE_OK) result = written;
    }

    // commit or rollback current transaction
    if((result == ENGINE_OK) || (result == ENGINE_DB_NOT_FOUND_ERROR))
    {
        result = ee_db_commit_transaction(&ee_engine.db_connection);
        // EVENT_HEAP: the batch's events still pending go back in the heap
        db_event_heap_batch_done(result == ENGINE_OK);
    }
    else
    {
        result = ee_db_rollback_transaction(&ee_engine.db_connection);
        db_event_heap_batch_done(0);   // EVENT_HEAP: all of the batch goes back
        // Give the database a moment before the retry (#711)
        if(event_batch_retry()) sleep(1);
    }

    // House drop stations (#324 step 10): clear the batches that have
    // ended. All of it in one transaction, so a batch is paid once or
    // not at all (a restart picks up what wasn't committed)
    if(ee_db_start_transaction(&ee_engine.db_connection) == ENGINE_OK)
    {
        if(db_station_clear_due() == ENGINE_OK)
            ee_db_commit_transaction(&ee_engine.db_connection);
        else
            ee_db_rollback_transaction(&ee_engine.db_connection);
    }

    // The share exchange at Earth (#469): once a game hour, in one
    // transaction, so a batch clears whole or not at all
    if(ee_db_start_transaction(&ee_engine.db_connection) == ENGINE_OK)
    {
        if(db_shares_clear_due() == ENGINE_OK)
            ee_db_commit_transaction(&ee_engine.db_connection);
        else
            ee_db_rollback_transaction(&ee_engine.db_connection);
    }

    // The processor tax (#544): once a game hour, each company pays for
    // its drones' thinking, in one transaction
    if(ee_db_start_transaction(&ee_engine.db_connection) == ENGINE_OK)
    {
        if(db_compute_bill_due() == ENGINE_OK)
            ee_db_commit_transaction(&ee_engine.db_connection);
        else
            ee_db_rollback_transaction(&ee_engine.db_connection);
    }

    // Once a real minute: old settled events move to events_archive, so
    // the engines' table stays small (#694). Old actions go on the
    // scheduler's keeper (#944)
    db_archive_due();

    return result;
}

/** ****************************************************************************

  @brief      Runs engine logic loop

  @param[in]  void

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t ee_run()
{
    ee_init();

    // The heap of due events needs settlement and the turns in one program
    // (#694 stage 4 step 3): this events engine reads them from MySQL
    if(event_heap_flag_on())
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "NOTICE: EVENT_HEAP=1 needs EVENTS_IN_ENGINE=1: this events engine reads due events from MySQL");

    while(ee_engine.status == ENGINE_RUNNING_STATUS)
    {
        int found = 0;
        ee_pass(&found);

        // Wait till next engine execution, unless that batch was full: then
        // more events are due, so go on at once (#324 step 20; one batch per
        // ENGINE_CHECK_TIME held the engine to a couple of events a second)
        if(found < engine_get_outcome_events_batch_size())
            sleep(atoi(ee_engine.config.params[ENGINE_CHECK_TIME]));
    }

    // deallocate resources/Stop modules
    ee_engine_stop_all();
    
    return ee_engine.error;
}

/** ****************************************************************************

  @brief      Stops engine execution

  @param[in]  void

  @return     void

*******************************************************************************/
void ee_engine_stop()
{
    if((ee_engine.status != ENGINE_STOPPING_STATUS) && 
        (ee_engine.status != ENGINE_STOPPED_STATUS))
    {
        ee_engine.status = ENGINE_STOPPING_STATUS;

        ee_engine_trace(TRACE_LEVEL_ALWAYS, "Engine is stopping...");
    }
}

/** ****************************************************************************

  @brief      Checks if engine is stopped

  @param[in]  void

  @return     Boolean result

*******************************************************************************/
Bool_t ee_engine_is_stopped()
{
    if(ee_engine.status != ENGINE_RUNNING_STATUS)
        return ENGINE_TRUE;

    return ENGINE_FALSE;
}

/** ****************************************************************************

  @brief      Gets last engine error

  @param[in]  void

  @return     Last error code

*******************************************************************************/
int ee_engine_get_last_error()
{
    return ee_engine.error;
}

/** ****************************************************************************

  @brief      Logs an engine trace in output log file

  @param[in]  level Trace level
  @param[in]  trace Trace format text
  @param[in]  ...   Variable list of arguments

  @return     void

*******************************************************************************/
void ee_engine_trace(TraceLevel_t level, const char *trace, ... )
{
    va_list valist;

    va_start(valist, trace);
    ee_engine_vtrace(level, trace, valist);
    va_end(valist);
}

/** ****************************************************************************

  @brief      ee_engine_trace with its arguments as a va_list

*******************************************************************************/
void ee_engine_vtrace(TraceLevel_t level, const char *trace, va_list valist)
{
    // Inside the game engine its lines share the game engine's log, so they
    // say they are the events engine's (#694 stage 4)
    trace_write_prefixed(ee_engine.trace_hndl, level, ee_engine.embedded ? "[events] " : "", trace, valist);
}

/** ****************************************************************************

  @brief      Allocation wrapper to control memory used by the engine

  @param[in]  size Size we want to allocate
  
  @return     Pointer to memory allocated or NULL when failed

*******************************************************************************/
void* ee_engine_malloc(size_t size)
{
    void* memory = malloc(size);

    if(memory)
    {
        ee_engine.memory_in_use += size; 

        ee_engine_trace(TRACE_LEVEL_ALWAYS, 
            "Allocated [%ld] bytes (Total memory: [%ld])",
            size,
            ee_engine.memory_in_use);
    }

    return memory;
}

/** ****************************************************************************

  @brief      Free wrapper to control memory used by the engine

  @param[in]  Pointer to memory we want to deallocate
  
  @return     void

*******************************************************************************/
void ee_engine_free(void* memory, size_t size)
{
    if(memory)
    {
        free(memory);

        ee_engine.memory_in_use -= size; 

        ee_engine_trace(TRACE_LEVEL_ALWAYS, 
            "Deallocated [%ld] bytes (Total memory in use: [%ld])",
            size,
            ee_engine.memory_in_use);
    }
}

/** ****************************************************************************

  @brief      Gets the command defined (at config) as VM resume command
              It is an special command that is not executed at the VM, it just 
              indicates that we need to resume VM to continue executing pending commands

  @param[in]  None
  
  @return     Command string

*******************************************************************************/
const char* ee_engine_get_vm_resume_command()
{
    return (const char*)""; // not used at events engine
}

/** ****************************************************************************

  @brief      Gets current engine DB connection

  @param[in]  None

  @return     Current DB connection info

*******************************************************************************/
DbConnection_t* ee_engine_get_db_connection()
{
    // Inside the game engine the connection is opened, and the lock taken,
    // by ee_embed_claim, which never waits for the lock (#694 stage 4)
    if(!ee_engine.db_connection.hndl)
    {
        if(ee_engine.embedded)
            ee_db_init(&ee_engine.db_connection);
        else
            ee_engine_init_db();
    }

    return &ee_engine.db_connection;
}

/** ****************************************************************************

  @brief      Gets the configured value for events expiration days

  @param[in]  None
  
  @return     Configured days value or default if not found

*******************************************************************************/
int engine_get_events_expiration_days()
{
    return atoi(ee_engine.config.params[EVENT_EXPIRATION_DAYS]);
}

/** ****************************************************************************

  @brief      Gets the configured value for actions expiration days

  @param[in]  None
  
  @return     Configured days value or default if not found

*******************************************************************************/
int engine_get_actions_expiration_days()
{
    return atoi(ee_engine.config.params[ACTION_EXPIRATION_DAYS]);
}

/** ****************************************************************************

  @brief      Gets the number of outcome events to process per round

  @param[in]  None
  
  @return     Configured batch size (number of events)

*******************************************************************************/
int engine_get_outcome_events_batch_size()
{
    return atoi(ee_engine.config.params[OUTCOME_EVENT_BATCH_SIZE]);
}

/******************************* INSIDE ANOTHER PROGRAM **********************/

/*
 * The events engine inside the game engine (#694 stage 4 step 2,
 * EVENTS_IN_ENGINE=1; see ee_embed.h and settle.c). Its state is this file's
 * ee_engine, as in bin/events-engine: its own configuration
 * (conf/events-engine.conf and the environment) and its own database
 * connection, so settlement and turns never share a transaction. Its log
 * lines go to the game engine's log, prefixed "[events] ".
 */

ErrorCode_t ee_embed_init(const char* plat_home, TraceHndl_t trace)
{
    memset(&ee_engine, 0, sizeof(ee_engine));
    ee_engine.status = ENGINE_IDLE_STATUS;
    ee_engine.embedded = 1;
    ee_engine.exe_name = "events-engine";   // conf/events-engine.conf
    ee_engine.plat_home = (char*)plat_home;
    ee_engine.trace_hndl = trace;
    ee_engine.error = plat_home ? ENGINE_OK : ENGINE_PLAT_HOME_ERROR;

    if(ee_engine.error == ENGINE_OK)
        ee_engine.error = ee_engine_init_config();

    if(ee_engine.error == ENGINE_OK)
    {
        ee_engine.db_connection.hndl = NULL;
        ee_engine.db_connection.host = ee_engine.config.params[DB_HOST_ID];
        ee_engine.db_connection.port = atoi(ee_engine.config.params[DB_PORT_ID]);
        ee_engine.db_connection.user = ee_engine.config.params[DB_USER_ID];
        ee_engine.db_connection.password = ee_engine.config.params[DB_PASSWORD_ID];
        ee_engine.db_connection.db_name = ee_engine.config.params[DB_NAME_ID];
        ee_engine.db_connection.lock = NULL;
        ee_engine.db_connection.checked_at = 0;
    }

    return ee_engine.error;
}

ErrorCode_t ee_embed_claim(void)
{
    if(ee_engine.error != ENGINE_OK)
        return ee_engine.error;
    if(ee_engine.db_connection.lock)
        return ENGINE_OK;

    if(!ee_engine.db_connection.hndl && ee_db_init(&ee_engine.db_connection) != ENGINE_OK)
        return ENGINE_DB_CONNECT_ERROR;

    // No waiting: the game engine has turns to run. A bin/events-engine
    // (an old one still stopping, or one without EVENTS_IN_ENGINE=1) may
    // hold it; settle.c tries again a few seconds later
    if(db_try_claim_engine(&ee_engine.db_connection, "events-engine", 0) != ENGINE_OK)
        return ENGINE_DB_QUERY_ERROR;

    ee_engine_startup_work();
    ee_init();
    return ENGINE_OK;
}

int ee_embed_claimed(void)
{
    return ee_engine.embedded && ee_engine.db_connection.lock != NULL;
}

DbConnection_t* ee_embed_connection(void)
{
    return &ee_engine.db_connection;
}

int ee_embed_owns(const DbConnection_t* connection)
{
    return ee_engine.embedded && connection == &ee_engine.db_connection;
}

void ee_embed_stop(void)
{
    if(ee_engine.embedded)
    {
        // Closing the connection gives up the lock
        ee_db_stop(&ee_engine.db_connection);
        ee_engine.db_connection.lock = NULL;
        ee_config_engine_stop(&ee_engine.conf_hndl);
        ee_engine.embedded = 0;
    }
}

int ee_rest(int argc, char** argv)
{
    memset(&ee_engine, 0, sizeof(ee_engine));
    ee_engine.exe_name = argv[0];
    char* pos = strrchr(argv[0], '/');
    if(pos)
        ee_engine.exe_name = ++pos;
    ee_engine.plat_home = getenv(PLAT_HOME);
    if(!ee_engine.plat_home)
        return ENGINE_PLAT_HOME_ERROR;

    signal(SIGINT, ee_signals_handler);
    signal(SIGTERM, ee_signals_handler);
    if(ee_engine_init_traces() != ENGINE_OK)
        return ENGINE_LOG_FILE_ERROR;

    ee_engine.status = ENGINE_RUNNING_STATUS;
    ee_engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------------");
    ee_engine_trace(TRACE_LEVEL_ALWAYS,
        "NOTICE: EVENTS_IN_ENGINE=1: settlement runs in the game engine, so this events "
        "engine rests (NAME [%s] PID [%d])", ee_engine.exe_name, getpid());
    ee_engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------------");

    // Never exits by itself: Docker would restart it again and again. A
    // signal ends the sleep and stops it
    while(ee_engine.status == ENGINE_RUNNING_STATUS)
        sleep(60);

    ee_engine_trace(TRACE_LEVEL_ALWAYS, "Engine stopped (PID [%d])", getpid());
    trace_stop(ee_engine.trace_hndl);
    return ENGINE_OK;
}