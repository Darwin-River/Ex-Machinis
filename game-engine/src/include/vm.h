/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Virtual machine management module
 
******************************************************************************/

#ifndef __EM_VM_MODULE__
#define __EM_VM_MODULE__

/******************************* INCLUDES ************************************/

#include "common_types.h"

/******************************* DEFINES *************************************/



/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/



/******************************* STATIC FUNCTIONS ****************************/


/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Creates a new FORTH VM

  @param[in]  None

  @return     New VM object created or NULL when failed

*******************************************************************************/
VirtualMachine_t* vm_new();

/** ****************************************************************************

  @brief      Deallocates FORTH VM resources

  @param[in]  vm Virtual Machine object we want to remove

  @return     void

*******************************************************************************/
void vm_free(VirtualMachine_t* vm);

/** ****************************************************************************

  @brief      Creates a new FORTH VM using input bytes

  @param[in]  vm_bytes Bytes to be used to build a new VM
  @param[in]  size     Bytes number

  @return     New VM object created or NULL when failed

*******************************************************************************/
VirtualMachine_t* vm_from_bytes(char* vm_bytes, size_t size);

/** ****************************************************************************

  @brief      Runs engine logic loop

  @param[in]  vm      VM object
  @param[in]  command Command to be executed in current VM

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t vm_run_command(VirtualMachine_t* vm, Command_t* command);

/** ****************************************************************************

  @brief      Gets VM memory size

  @param[in]  vm  Current VM object

  @return     VM core size or -1 when error

*******************************************************************************/
size_t vm_get_size(VirtualMachine_t* vm);

/** ****************************************************************************

  @brief      Serializes a given VM objet into a new array of bytes
              (Important: memory returned by this function must be deallocated once used)

  @param[in]      vm       Current VM object
  @param[in|out]  vm_size  Output buffer where we store size of serialized buffer

  @return     Serialized buffer (dynamically allocated) or NULL when error

*******************************************************************************/
char* vm_to_bytes(VirtualMachine_t* vm, size_t* vm_size);

#endif // __EM_VM_MODULE__