/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Traces management module
 
******************************************************************************/

#ifndef __EM_TRACE_MODULE__
#define __EM_TRACE_MODULE__

/******************************* INCLUDES ************************************/

#include <linux/limits.h>
#include <stdio.h>

#include "common_types.h"

/******************************* DEFINES *************************************/


/******************************* TYPES ***************************************/

//-----------------------------------------------------------------------------
//  Traces configuration object
//-----------------------------------------------------------------------------
typedef struct 
{
    int level;
    char log_file_path[PATH_MAX+1];
    Bool_t use_stdout;
    Bool_t add_header;
    Bool_t daily;

} TraceConf_t;

//-----------------------------------------------------------------------------
//  Traces handler
//-----------------------------------------------------------------------------
typedef struct 
{
    TraceConf_t conf;
    FILE* fp;  // log file pointer

} TraceHndl_t;

/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/



/******************************* STATIC FUNCTIONS ****************************/


/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Traces/logs initialization

  @param[in]  conf  Configuration to be used at logs

  @return     void

*******************************************************************************/
ErrorCode_t trace_init(TraceConf_t* conf);

/** ****************************************************************************

  @brief      Traces/logs module stop

  @param[in]  void

  @return     void

*******************************************************************************/
void trace_stop();


/** ****************************************************************************

  @brief      Write trace into file

  @param[in]  level Trace level
  @param[in]  trace Formatted msg
  @param[in]  ...   Variable list of arguments to be printed

  @return     void

*******************************************************************************/
void trace_write(int level, const char *trace, ... );

#endif // __EM_TRACE_MODULE__