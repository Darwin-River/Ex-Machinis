/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Engine module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

 #include <unistd.h>

#include "engine.h"
#include "trace.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/

Engine_t engine;

/******************************* LOCAL FUNCTIONS *****************************/


/******************************* PUBLIC FUNCTIONS ****************************/

ErrorCode_t engine_run()
{
	engine.stop = ENGINE_FALSE;
	engine.stopped = ENGINE_FALSE;

	// dummy logic - simulate a continous loop with delay
	while(engine.stop != ENGINE_TRUE)
	{
		trace_write(1, "Running engine logic");

		sleep(1);
	}

	engine.stopped = ENGINE_TRUE;

	trace_write(1, "Engine stopped with PID=[%d]", getpid());

	return ENGINE_OK;
}

void engine_stop()
{
	engine.stop = ENGINE_TRUE;

	trace_write(1, "Engine is stopping...");
}

Bool_t engine_is_stopped()
{
	return engine.stopped;
}