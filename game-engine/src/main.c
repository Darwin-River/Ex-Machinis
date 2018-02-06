/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Main module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <stdio.h>
#include <stdlib.h>
#include <signal.h>
#include <sys/types.h>
#include <unistd.h>

#include "trace.h"
#include "engine.h"
#include "db.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief      Stops engine modules

  @param[in]  None

  @return     void

*******************************************************************************/
void stop_modules()
{
	// signals

	// traces

	// config

	// db
	db_stop();

	//trace_stop();
}

/** ****************************************************************************

  @brief      Callback invoked when supported signal is received
              We do cleanup and exit properly

  @param[in]  signal_id  ID of signal received

  @return     void

*******************************************************************************/
void signals_handler(int signal_id)
{
	trace_write(1, "Signal [%d] received", signal_id);

    engine_stop();
}

/** ****************************************************************************

  @brief      Initializes engine modules

  @param[in]  None

  @return     void

*******************************************************************************/
void init_modules()
{
	// Check that PLAT_HOME is defined 

	// signals - Capture basic signals
    signal(SIGINT, signals_handler);
    signal(SIGTERM, signals_handler);

    // Define exit callback
    atexit(stop_modules);

    // Define traces configuration
    TraceConf_t conf;
    conf.level = 3;
    snprintf(conf.log_file_path, PATH_MAX, "./test.log");

	// traces
	if(trace_init(&conf) != ENGINE_OK)
	{
		stop_modules();
	}

	// config

	// db
	db_init();

	// engine logic
	
	// engine logic
    trace_write(1, "Engine initialized with PID=[%d]", getpid());

    engine_run();
}

/******************************* MAIN ****************************************/


/** ****************************************************************************

  @brief      Engine entry point

  @param[in]  argc, argv 

  @return     Execution result (EXIT_SUCCESS/EXIT_FAILURE)

*******************************************************************************/
int main() 
{
	// initialize modules
    init_modules();

    stop_modules();

    return EXIT_SUCCESS;
}

