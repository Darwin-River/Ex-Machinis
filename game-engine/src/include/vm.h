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

  @param[in]  Agent ID for this VM

  @return     New VM object created or NULL when failed

*******************************************************************************/
VirtualMachine_t* vm_new(int agent_id);

/** ****************************************************************************

  @brief      Deallocates FORTH VM resources

  @param[in]  vm Virtual Machine object we want to remove

  @return     void

*******************************************************************************/
void vm_free(VirtualMachine_t* vm);

/** ****************************************************************************

  @brief      Creates a new FORTH VM using input bytes

  @param[in]  agent_id Current agent ID
  @param[in]  vm_bytes Bytes to be used to build a new VM
  @param[in]  size     Bytes number

  @return     New VM object created or NULL when failed

*******************************************************************************/
VirtualMachine_t* vm_from_bytes(int agent_id, char* vm_bytes, size_t size);

/** ****************************************************************************

  @brief      Runs engine logic loop

  @param[in]  vm         VM object
  @param[in]  command    Command to be executed in current VM
  @param[out] out_buffer Output buffer
  @param[int] out_size   Output buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t vm_run_command(VirtualMachine_t* vm, Command_t* command, char* out_buffer, size_t out_size);

/** ****************************************************************************

  @brief      Serializes a given VM objet into a new array of bytes
              (Important: memory returned by this function must be deallocated once used)

  @param[in]      vm       Current VM object
  @param[in|out]  vm_size  Output buffer where we store size of serialized buffer

  @return     Serialized buffer (dynamically allocated) or NULL when error

*******************************************************************************/
char* vm_to_bytes(VirtualMachine_t* vm, size_t* vm_size);

/** ****************************************************************************

  @brief      When invoked, sends email with current VM output buffer content

  @param[in]  vm       Current VM object

  @return     Serialized buffer (dynamically allocated) or NULL when error

*******************************************************************************/
void vm_report(VirtualMachine_t* vm);

/** ****************************************************************************

  @brief      Function to check if last VM execution was completed or yield

  @param[in]  None

  @return     0/1 value

*******************************************************************************/
int vm_is_yield();

/** ****************************************************************************

  @brief      Aborts command execution at current VM

  @param[in]  vm       Current VM object

  @return     void

*******************************************************************************/
void vm_abort(VirtualMachine_t* vm);

/** ****************************************************************************

  @brief      Resets VM (stops command execution and destroys it to create a new one)

  @param[in]  vm       Current VM object

  @return     void

*******************************************************************************/
void vm_reset(VirtualMachine_t* vm);

/** ****************************************************************************

  @brief      Reads byte from VM memory at given address (offset given in bytes)

  @param[in]      vm        Current VM object
  @param[in]      addr      Offset (in bytes) we read from
  @param[in|out]  outValue  Output byte where we store the value obtained

  @return     Execution result

*******************************************************************************/
ErrorCode_t vm_read_byte(VirtualMachine_t* vm, uint16_t addr, unsigned char* outValue);

/** ****************************************************************************

  @brief      Writes byte into VM memory at given address (offset given in bytes)

  @param[in]  vm     Current VM object
  @param[in]  addr   Offset (in bytes) we write into
  @param[in]  value  Byte value to write into VM memory

  @return     Execution result

*******************************************************************************/
ErrorCode_t vm_write_byte(VirtualMachine_t* vm, uint16_t addr, unsigned char value);

#endif // __EM_VM_MODULE__