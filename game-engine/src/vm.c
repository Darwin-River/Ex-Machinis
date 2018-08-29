/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Virtual Machine management module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>

#include "vm.h"
#include "engine.h"
#include "trace.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/

// we store in global variable the last command output
char* g_last_command_output = NULL;

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief      Callback invoked by VM when we need to write a character

  @param[in]  ch Char received from VM
  @param[in]  file, handle needed to write to a source, if needed

  @return     ch on success, negative on failure

*******************************************************************************/
int vm_putc_cb(int ch, void *file) 
{
  engine_trace(TRACE_LEVEL_ALWAYS, "Char [%c] received from VM output", ch);

  int next_pos = strlen(g_last_command_output);
  g_last_command_output += next_pos;

  *g_last_command_output = ch;

  return ch;
}

/** ****************************************************************************

  @brief      Creates a new FORTH VM

  @param[in]  Agent ID for this VM

  @return     New VM object created or NULL when failed

*******************************************************************************/
VirtualMachine_t* vm_new(int agent_id)
{
    VirtualMachine_t* vm = NULL;

    // Allocate new embedded VM
    vm = (VirtualMachine_t*)embed_new();

    if(vm)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "New VM created with size [%ld] and core size [%ld] for agent [%d]",
        	sizeof(*vm), sizeof(*(vm->m)), agent_id); 

        embed_opt_t vm_default_options = *embed_opt_get(vm);
        embed_opt_t vm_engine_options = vm_default_options;
        vm_engine_options.put = vm_putc_cb;
        vm_engine_options.options = 0;
        embed_opt_set(vm, &vm_engine_options);
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

        embed_free((forth_t*)vm);
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

#ifdef USE_OLD_EMBED
    if(vm_bytes)
    {
        vm = embed_load_from_memory((unsigned char*)vm_bytes, size);

        if(vm) {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "VM created from [%ld] bytes of memory for agent", 
                size);             
        } else {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "ERROR: Unable to create VM from [%ld] bytes of memory for agent", 
                size); 
        }
    }    
#else  // USE latest embed version
    if(vm_bytes)
    {
        // Create a new VM and load its core from DB
        vm = embed_new();

        // Define output options to retrieve the buffer
        embed_opt_t vm_default_options = *embed_opt_get(vm);
        embed_opt_t vm_engine_options = vm_default_options;
        vm_engine_options.put = vm_putc_cb;
        vm_engine_options.options = 0;
        embed_opt_set(vm, &vm_engine_options);

        if(vm) {
            int error = embed_load_buffer(vm, (const uint8_t *)vm_bytes, size);

            if(error) {
            	engine_trace(TRACE_LEVEL_ALWAYS,
                	"VM created from [%ld] bytes of memory for agent",
                	size);
            } else {
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to create VM from [%ld] bytes of memory for agent",
                    size);
            }
        }
    }
#endif  

    return vm;
}

/** ****************************************************************************

  @brief      Runs engine logic loop

  @param[in]  vm         VM object
  @param[in]  command    Command to be executed in current VM
  @param[out] out_buffer Output buffer
  @param[int] out_size   Output buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t vm_run_command(VirtualMachine_t* vm, Command_t* command, char* out_buffer, size_t out_size)
{
    ErrorCode_t result = ENGINE_OK;

    if(vm && command)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "Running command: [%s]", command->code);

        g_last_command_output = out_buffer;
        memset(g_last_command_output, 0, out_size); 
         
        int forth_result = embed_eval((forth_t*)vm, (const char*)command->code);

        if(forth_result < 0)
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "ERROR: Command evaluation failed for [%s] result [%d]", command->code, forth_result);

            result = ENGINE_FORTH_EVAL_ERROR;
        }
        else
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "Command succesfully evaluated: [%s] result [%s]", command->code, out_buffer);
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

  @brief      Serializes a given VM objet into a new array of bytes
              (Important: memory returned by this function must be deallocated once used)

  @param[in]      vm       Current VM object
  @param[in|out]  vm_size  Output buffer where we store size of serialized buffer

  @return     Serialized buffer (dynamically allocated) or NULL when error

*******************************************************************************/
char* vm_to_bytes(VirtualMachine_t* vm, size_t* vm_size)
{
    char* vm_bytes = NULL;

    if(vm && vm_size)
    {
        vm_bytes = (char*)embed_save_into_memory((forth_t*) vm, vm_size);

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
