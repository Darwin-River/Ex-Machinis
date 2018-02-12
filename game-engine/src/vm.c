/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Virtual Machine management module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include "vm.h"
#include "engine.h"
#include "trace.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief      Runs engine logic loop

  @param[in]  vm      VM object
  @param[in]  command Command to be executed in current VM

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t vm_run_command(forth_t* vm, Command_t* command)
{
    ErrorCode_t result = ENGINE_OK;

    engine_trace(TRACE_LEVEL_ALWAYS, "Running command: [%s]", command->code);

    return result;
}
