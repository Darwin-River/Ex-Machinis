/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Engine module
 
******************************************************************************/

#ifndef __EM_ENGINE_MODULE__
#define __EM_ENGINE_MODULE__

/******************************* INCLUDES ************************************/

#include <linux/limits.h>

#include <libconfig.h>
#include <mysql.h>

#include "common_types.h"

#include "trace.h"
#include "config.h"
#include "libforth.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/

//-----------------------------------------------------------------------------
//  Application configuration - Parameters IDs
//-----------------------------------------------------------------------------
enum
{
    // Application configuration
    APP_TRACE_LEVEL_ID,

    // Database configuration
    DB_HOST_ID,
    DB_PORT_ID,
    DB_USER_ID,
    DB_PASSWORD_ID,
    DB_NAME_ID,

    // Logic configuration
    DB_READ_TIME,

    // Max configured parameters
    MAX_CONFIG_PARAM
};

//-----------------------------------------------------------------------------
//  Engine command line options
//-----------------------------------------------------------------------------

typedef struct 
{
    char conf_file_path[PATH_MAX];

} EngineOptions_t;

//-----------------------------------------------------------------------------
//  Engine status
//-----------------------------------------------------------------------------

typedef enum 
{
    ENGINE_IDLE_STATUS,
    ENGINE_RUNNING_STATUS,
    ENGINE_STOPPING_STATUS,
    ENGINE_STOPPED_STATUS,

} EngineStatus_t;

//-----------------------------------------------------------------------------
//  Engine configuration paramaters
//-----------------------------------------------------------------------------
typedef struct 
{
    char *params[MAX_CONFIG_PARAM];

} EngineConfig_t;

//-----------------------------------------------------------------------------
//  Engine object
//-----------------------------------------------------------------------------

typedef struct 
{
    int argc;
    char** argv;

    char* plat_home;    // ENV variable

    EngineOptions_t options;

    config_t conf_hndl; // configuration handler
    EngineConfig_t config;

    TraceConf_t trace_conf; // traces configuration
    TraceHndl_t trace_hndl; // traces handler

    EngineStatus_t status;

    DbConnection_t db_connection;


    Command_t last_command;  // last command read from DB

    forth_t* last_vm;  // last agent VM

    ErrorCode_t error;

} Engine_t;


/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/



/******************************* STATIC FUNCTIONS ****************************/


/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Initializes engine modules using command line options received

  @param[in]  argc, argv Command line options

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_init(int argc, char** argv);

/** ****************************************************************************

  @brief      Runs engine logic loop

  @param[in]  void

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_run();


/** ****************************************************************************

  @brief      Stops engine execution

  @param[in]  void

  @return     void

*******************************************************************************/
void engine_stop();


/** ****************************************************************************

  @brief      Checks if engine is stopped

  @param[in]  void

  @return     Boolean result

*******************************************************************************/
Bool_t engine_is_stopped();

/** ****************************************************************************

  @brief      Gets last engine error

  @param[in]  void

  @return     Last error code

*******************************************************************************/
int engine_get_last_error();

/** ****************************************************************************

  @brief      Logs an engine trace in output log file

  @param[in]  level Trace level
  @param[in]  trace Trace format text
  @param[in]  ...   Variable list of arguments

  @return     void

*******************************************************************************/
void engine_trace(TraceLevel_t level, const char *trace, ... );

#endif // __EM_ENGINE_MODULE__