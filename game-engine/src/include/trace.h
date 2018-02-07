/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Traces management module
 
******************************************************************************/

#ifndef __EM_TRACE_MODULE__
#define __EM_TRACE_MODULE__

/******************************* INCLUDES ************************************/

#include <linux/limits.h>
#include <stdio.h>
#include <stdarg.h>

#include "common_types.h"

/******************************* DEFINES *************************************/


/******************************* TYPES ***************************************/

//-----------------------------------------------------------------------------
//  Traces levels
//-----------------------------------------------------------------------------
typedef enum 
{
    TRACE_LEVEL_ALWAYS,
    TRACE_LEVEL_LOW,
    TRACE_LEVEL_MEDIUM,
    TRACE_LEVEL_DEBUG
    
} TraceLevel_t;

//-----------------------------------------------------------------------------
//  Traces configuration object
//-----------------------------------------------------------------------------
typedef struct 
{
    TraceLevel_t level;
    char log_file_path[PATH_MAX+1];
    Bool_t use_stdout;
    Bool_t add_header;
    Bool_t daily;

} TraceConf_t;

// Encapsulate handler
typedef void* TraceHndl_t;

/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/



/******************************* STATIC FUNCTIONS ****************************/


/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Traces/logs initialization

  @param[in]  conf      Configuration to be used at logs
  @param[out] out_hndl  Out traces handler when OK

  @return     Execution result

*******************************************************************************/
ErrorCode_t trace_init(TraceConf_t* conf, TraceHndl_t* out_hndl);

/** ****************************************************************************

  @brief      Traces/logs module stop

  @param[in]  hdnl   Traces handler

  @return     void

*******************************************************************************/
void trace_stop(TraceHndl_t hdnl);


/** ****************************************************************************

  @brief      Write trace into file

  @param[in]  hdnl   Traces handler
  @param[in]  level  Trace level
  @param[in]  trace  Formatted msg
  @param[in]  valist Variable list of arguments to be printed

  @return     void

*******************************************************************************/
void trace_write(TraceHndl_t hdnl, TraceLevel_t level, const char *trace, va_list valist);

#endif // __EM_TRACE_MODULE__