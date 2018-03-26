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

  @brief      Creates a new FORTH VM

  @param[in]  Agent ID for this VM

  @return     New VM object created or NULL when failed

*******************************************************************************/
VirtualMachine_t* vm_new(int agent_id)
{
    VirtualMachine_t* vm = NULL;

    vm = (VirtualMachine_t*)forth_init(DEFAULT_CORE_SIZE, stdin, stdout, NULL);

    if(vm)
    {
        forth_set_debug_level((forth_t*)vm, FORTH_DEBUG_ALL);
        forth_set_args((forth_t*)vm, agent_id, NULL); // we store agent ID in register ARGC

        engine_trace(TRACE_LEVEL_ALWAYS, "New VM created with size [%ld] for agent [%d]",
            vm_get_size(vm), agent_id); 
    }

    return vm;
}

/** ****************************************************************************

  @brief      Deallocates FORTH VM resources

  @param[in]  vm Virtual Machine object we want to remove

  @return     void

*******************************************************************************/
void vm_free(VirtualMachine_t* vm)
{
    if(vm)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "VM deallocated"); 

        forth_free((forth_t*)vm);
        vm = NULL;
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: Unable to free NULL VM"); 
    }
}

/** ****************************************************************************

  @brief      Creates a new FORTH VM using input bytes

  @param[in]  vm_bytes Bytes to be used to build a new VM
  @param[in]  size     Bytes number

  @return     New VM object created or NULL when failed

*******************************************************************************/
VirtualMachine_t* vm_from_bytes(char* vm_bytes, size_t size)
{
    VirtualMachine_t* vm = NULL;

    if(vm_bytes)
    {
        vm = forth_load_core_memory(vm_bytes, size);

        if(vm && (forth_is_invalid((forth_t*)vm) == 0))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "VM created from [%ld] bytes of memory for agent [%ld]", 
                size,
                forth_get_agent_id((forth_t*)vm)); 
        }
    }

    return vm;
}

/** ****************************************************************************

  @brief      Runs engine logic loop

  @param[in]  vm      VM object
  @param[in]  command Command to be executed in current VM

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t vm_run_command(VirtualMachine_t* vm, Command_t* command)
{
    ErrorCode_t result = ENGINE_OK;

    if(vm && command && (forth_is_invalid((forth_t*)vm) == 0))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "Running command: [%s]", command->code);

        int forth_result = forth_eval((forth_t*)vm, (const char*)command->code);

        if(forth_result < 0)
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "ERROR: Command evaluation failed for [%s]", command->code);

            result = ENGINE_FORTH_EVAL_ERROR;
        }
        else
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "Command succesfully evaluated: [%s]", command->code);
        }
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Could not evaluate command (NULL input)");
        result = ENGINE_INTERNAL_ERROR;
    }

    return result;
}

/** ****************************************************************************

  @brief      Gets VM memory size

  @param[in]  vm  Current VM object

  @return     VM core size or -1 when error

*******************************************************************************/
size_t vm_get_size(VirtualMachine_t* vm)
{
    if(vm && (forth_is_invalid((forth_t*)vm) == 0))
    {
        // Cast to low level object and get its core size

        forth_t* f = (forth_t*) vm;
        size_t vm_size = forth_get_core_size(f);

        engine_trace(TRACE_LEVEL_ALWAYS, 
            "VM size is [%ld] bytes", vm_size);

        return vm_size;
    }

    return -1;
}

/** ****************************************************************************

  @brief      Serializes a given VM objet into a new array of bytes
              (Important: memory returned by this function must be deallocated once used)

  @param[in]      vm       Current VM object
  @param[in|out]  vm_size  Output buffer where we store size of serialized buffer

  @return     Serialized buffer (dynamically allocated) or NULL when error

*******************************************************************************/
char* vm_to_bytes(VirtualMachine_t* vm, size_t* vm_size)
{
    char* vm_bytes = NULL;

    if(vm && vm_size && (forth_is_invalid((forth_t*)vm) == 0))
    {
        vm_bytes = forth_save_core_memory((forth_t*) vm, vm_size);

        if(vm_bytes && *vm_size)
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "VM succesfully serialized into [%ld] bytes", *vm_size);
        }
        else
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "ERROR: Could not serialize VM (serialization failed)");
        }
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Could not serialize VM (NULL input)");
    }

    return vm_bytes;
}

/** ****************************************************************************

  @brief      Function invoked to retrieve current VM output

  @param[in]  vm  Current VM object

  @return     Current VM output or NULL when failed

*******************************************************************************/
const char* vm_get_command_output(VirtualMachine_t* vm)
{
    const char* output = NULL;

    if(vm)
    {
        output = forth_get_vm_output((forth_t*)vm);
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL VM, could not retrieve output"); 
    }

    return output;
}
