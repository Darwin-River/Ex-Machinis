/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Logs management module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>
#include <stdio.h>
#include <time.h>
#include <sys/time.h>
#include <stdlib.h>


#include "trace.h"

/******************************* DEFINES *************************************/

// Max sizes for traces and dates
#define TRACE_MAXLENGTH          PATH_MAX
#define TRACE_DATE_MAXLENGTH     50

/******************************* TYPES ***************************************/

//-----------------------------------------------------------------------------
//  Traces management info
//-----------------------------------------------------------------------------
typedef struct 
{
    TraceConf_t conf;
    FILE* fp;  // log file pointer

} TraceInfo_t;

/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/

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

ErrorCode_t trace_init(TraceConf_t* conf, TraceHndl_t* out_hndl)
{
	ErrorCode_t error = ENGINE_OK;

	TraceInfo_t* trace_info = malloc(sizeof(TraceInfo_t));

	memset(trace_info, 0, sizeof(TraceInfo_t));

	error = trace_check_conf(conf);

	// Store and validate conf
	if(error == ENGINE_OK)
	{
		// Save config
		memcpy(&trace_info->conf, conf, sizeof(TraceConf_t));

		// Open file 
		trace_info->fp = fopen(trace_info->conf.log_file_path, "a");

		if(!trace_info->fp)
		{
			error = ENGINE_LOG_FILE_ERROR;
		}
		else
		{
			*out_hndl = trace_info;
		}
	}

	return error;
}

/*******************************************************************************/

void trace_stop(TraceHndl_t hdnl)
{
	TraceInfo_t* trace_info = (TraceInfo_t*)hdnl;

	if(trace_info && trace_info->fp)
	{
		fclose(trace_info->fp);
		trace_info->fp = NULL;
	}
}

/*******************************************************************************/


void trace_write(TraceHndl_t hdnl, TraceLevel_t level, const char *trace, va_list valist)
{
	TraceInfo_t* trace_info = (TraceInfo_t*)hdnl;

	if(trace_info && trace_info->fp && (level <= trace_info->conf.level))
	{
		static char trace_msg[TRACE_MAXLENGTH+1];

	    // res msg buffer
		memset(trace_msg, 0, TRACE_MAXLENGTH+1);

	    vsnprintf(trace_msg, TRACE_MAXLENGTH, trace, valist);

	    time_t now;
		struct tm* lnow;
		char date[TRACE_DATE_MAXLENGTH+1];

		// date and time
		now = time(NULL);
		lnow = localtime(&now);
		// Get also milliseconds
		struct timeval currentTime;
		gettimeofday(&currentTime, NULL);
		long milliseconds = currentTime.tv_usec / 1000;

		strftime(date, TRACE_DATE_MAXLENGTH, "%d/%m/%Y,%H:%M:%S", lnow);

		// write line
		fprintf(trace_info->fp, 
			"* %s.%03ld [%d] %s\n", 
			date, milliseconds, level, trace_msg);

	    fflush(trace_info->fp);
	}
}

void trace_header(TraceHndl_t hdnl, TraceLevel_t level)
{
	TraceInfo_t* trace_info = (TraceInfo_t*)hdnl;

	if(trace_info && trace_info->fp && (level <= trace_info->conf.level))
	{
	    time_t now;
		struct tm* lnow;
		char date[TRACE_DATE_MAXLENGTH+1];

		// date and time
		now = time(NULL);
		lnow = localtime(&now);
		// Get also milliseconds
		struct timeval currentTime;
		gettimeofday(&currentTime, NULL);
		long milliseconds = currentTime.tv_usec / 1000;

		strftime(date, TRACE_DATE_MAXLENGTH, "%d/%m/%Y,%H:%M:%S", lnow);

		// write line
		fprintf(trace_info->fp, 
			"* %s.%03ld [%d] ", 
			date, milliseconds, level);

	    fflush(trace_info->fp);
	}
}

void trace_append(TraceHndl_t hdnl, TraceLevel_t level, const char *trace, va_list valist)
{
	TraceInfo_t* trace_info = (TraceInfo_t*)hdnl;

	if(trace_info && trace_info->fp && (level <= trace_info->conf.level))
	{
		static char trace_msg[TRACE_MAXLENGTH+1];

	    // res msg buffer
		memset(trace_msg, 0, TRACE_MAXLENGTH+1);

	    vsnprintf(trace_msg, TRACE_MAXLENGTH, trace, valist);

		// write parital text in current line
		fprintf(trace_info->fp, "%s", trace_msg);
	    fflush(trace_info->fp);
	}
}

void trace_set_level(TraceHndl_t hdnl, TraceLevel_t level)
{
	TraceInfo_t* trace_info = (TraceInfo_t*)hdnl;

	if(trace_info)
	{
		trace_info->conf.level = level;
	}
}

