/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Virtual machine management module
 
******************************************************************************/

#ifndef __EM_VM_MODULE__
#define __EM_VM_MODULE__

/******************************* INCLUDES ************************************/

#include "common_types.h"

#include "libforth.h"

/******************************* DEFINES *************************************/



/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/



/******************************* STATIC FUNCTIONS ****************************/


/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Runs engine logic loop

  @param[in]  vm      VM object
  @param[in]  command Command to be executed in current VM

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t vm_run_command(forth_t* vm, Command_t* command);

#endif // __EM_VM_MODULE__