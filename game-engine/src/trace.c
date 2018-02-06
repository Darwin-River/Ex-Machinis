/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Logs management module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>
#include <stdio.h>
#include <time.h>
#include <stdarg.h>

#include "trace.h"

/******************************* DEFINES *************************************/

// Max sizes for traces and dates
#define TRACE_MAXLENGTH          PATH_MAX
#define TRACE_DATE_MAXLENGTH     50

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/

// instance to manage traces
TraceHndl_t traceHndl;

/******************************* LOCAL FUNCTIONS *****************************/

ErrorCode_t trace_check_conf(TraceConf_t* conf)
{
	ErrorCode_t error = ENGINE_OK;

	// input pointers checked by calling function

	if(conf->log_file_path[0] == 0) 
	{
		error = ENGINE_LOG_CONF_ERROR;
	}

	return error;
}

/******************************* PUBLIC FUNCTIONS ****************************/

ErrorCode_t trace_init(TraceConf_t* conf)
{
	ErrorCode_t error = ENGINE_OK;

	memset(&traceHndl, 0, sizeof(TraceHndl_t));

	error = trace_check_conf(conf);

	// Store and validate conf
	if(error == ENGINE_OK)
	{
		// Save config
		memcpy(&traceHndl.conf, conf, sizeof(TraceConf_t));

		// Open file 
		traceHndl.fp = fopen(traceHndl.conf.log_file_path, "a");

		if(!traceHndl.fp)
		{
			error = ENGINE_LOG_FILE_ERROR;
		}
		// else { opened }
	}

	return error;
}

/*******************************************************************************/

void trace_stop()
{
	if(traceHndl.fp)
	{
		fclose(traceHndl.fp);
		traceHndl.fp = NULL;
	}
}

/*******************************************************************************/


void trace_write(int level, const char *trace, ... )
{
	static va_list valist;
	static char trace_msg[TRACE_MAXLENGTH+1];

    // res msg buffer
	memset(trace_msg, 0x00, TRACE_MAXLENGTH+1);

	va_start(valist, trace);
    vsnprintf(trace_msg, TRACE_MAXLENGTH, trace, valist);
    va_end(valist);

    time_t now;
	struct tm* lnow;
	char date[TRACE_DATE_MAXLENGTH+1];

	// date and time
	now = time(NULL);
	lnow = localtime(&now);
	strftime(date, TRACE_DATE_MAXLENGTH, "%d/%m/%Y,%H:%M:%S", lnow);

	// write line
	fprintf(traceHndl.fp, "* %s [%d] %s\n", date, level, trace_msg);
    fflush(traceHndl.fp);
}