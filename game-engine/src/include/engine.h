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

/******************************* DEFINES *************************************/

#define ENGINE_MAX_BUF_SIZE   2048

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
    DB_READ_TIME_ID,
    MAX_CYCLE_SECONDS,
    VM_RESUME_COMMAND,

    // Email configuration
    SEND_EMAIL_SCRIPT_ID,
    SEND_EMAIL_TEMPLATE_ID,
    AGENTS_EMAIL_DOMAIN_ID,

    // FORTH image configuration
    FORTH_IMAGE_PATH_ID,

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
    char* exe_name;     // basename of the executable

    EngineOptions_t options;

    config_t conf_hndl; // configuration handler
    EngineConfig_t config;

    TraceConf_t trace_conf; // traces configuration
    TraceHndl_t trace_hndl; // traces handler

    EngineStatus_t status;

    DbConnection_t db_connection;

    Command_t last_command;  // last command read from DB

    AgentInfo_t  last_agent; // keep track of last agent processed

    ErrorCode_t error;

    long long memory_in_use;   // keep track of the memory currently allocated (in bytes)

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


/** ****************************************************************************

  @brief      Initializes a log line only with date and level, this function must be 
              called before using trace_append

  @param[in]  level  Trace level
  
  @return     void

*******************************************************************************/
void engine_trace_header(TraceLevel_t level);


/** ****************************************************************************

  @brief      Appends more text to current log line, no new line is added

  @param[in]  level Trace level
  @param[in]  trace Trace format text
  @param[in]  ...   Variable list of arguments

  @return     void

*******************************************************************************/
void engine_trace_append(TraceLevel_t level, const char *trace, ... );

/** ****************************************************************************

  @brief      Function used to send VM output to users

  @param[in]  msg      Output msg

  @return     void

*******************************************************************************/
void engine_vm_output_cb(const char* msg);

/** ****************************************************************************

  @brief      Allocation wrapper to control memory used by the engine

  @param[in]  size Size we want to allocate
  
  @return     Pointer to memory allocated or NULL when failed

*******************************************************************************/
void* engine_malloc(size_t size);

/** ****************************************************************************

  @brief      Free wrapper to control memory used by the engine

  @param[in]  Pointer to memory we want to deallocate
  
  @return     void

*******************************************************************************/
void engine_free(void* memory, size_t size);

/** ****************************************************************************

  @brief      Gets path of the forth image used as template to create VM

  @param[in]  None
  
  @return     Path string

*******************************************************************************/
const char* engine_get_forth_image_path();

/** ****************************************************************************

  @brief      Gets the max number of second each VM can spend processing commands

  @param[in]  None
  
  @return     Seconds value configured

*******************************************************************************/
const int engine_get_max_cycle_seconds();

/** ****************************************************************************

  @brief      Gets the command defined (at config) as VM resume command
              It is an special command that is not executed at the VM, it just 
              indicates that we need to resume VM to continue executing pending commands

  @param[in]  None
  
  @return     Command string

*******************************************************************************/
const char* engine_get_vm_resume_command();

/** ****************************************************************************

  @brief      Gets current engine DB connection

  @param[in]  None

  @return     Current DB connection info

*******************************************************************************/
DbConnection_t* engine_get_db_connection();

/** ****************************************************************************

  @brief      Gets current drone ID (drone processing commands)

  @param[in]  None

  @return     Drone ID

*******************************************************************************/
int engine_get_current_drone_id();

#endif // __EM_ENGINE_MODULE__