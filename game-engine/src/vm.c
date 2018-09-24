/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Virtual Machine management module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>
#include <assert.h>
#include <time.h>

#include "vm.h"
#include "engine.h"
#include "trace.h"
#include "vm_extension.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/

// we store in global buffer the last command output and use pointer to next char
char* g_output_buffer = NULL;
char* g_last_command_output = NULL;
size_t g_current_size = 0;

// To yield a VM we control time elapsed since we started it, and limit this time
time_t g_vm_start_time = 0;

// Last VM is yield
int g_vm_yield = 0;

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

  if(g_output_buffer)
  {
    int next_pos = strlen(g_output_buffer);
    g_last_command_output = (g_output_buffer + next_pos);
    *g_last_command_output = ch;
  }

  return ch;
}

/** ****************************************************************************

  @brief      Callback invoked by VM to check if a given VM must stop or continue

  @param[in]  Yield parameter

  @return     0 continue, 1 yield

*******************************************************************************/
int vm_yield_cb(void* param) 
{
  time_t now = time(NULL);
  int max_time = engine_get_max_cycle_seconds();

  if((now - g_vm_start_time) >= max_time) {
    engine_trace(TRACE_LEVEL_ALWAYS, 
      "WARNING: VM must yield, elapsed more than [%d] seconds(%ld - %ld)", 
      max_time, now, g_vm_start_time);

    g_vm_yield = 1;

    return 1;
  }

  return 0;
}

/** ****************************************************************************

  @brief      Adds all user defined callbacks to the VM (defining new words with 'embed_eval')

  @param[in]  vm       Current VM
  @param[in]  optimize Flag to optimize or not the word definition in memory 
  @param[in]  cb       Array of callbacks to be added
  @param[in]  number   Callbacks array size

  @return     Execution result

*******************************************************************************/
static int vm_add_callbacks(VirtualMachine_t* const vm, const bool optimize,  VmExtensionCb_t *cb, const size_t number) 
{
  // input pointers checked by calling function
  //assert(vm && cb);

  const char *optimizer = optimize ? "-2 cells allot ' vm chars ," : "";
  static const char *preamble = "only forth definitions system +order\n";
  int r = 0;

  if((r = embed_eval(vm, preamble)) < 0) {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: evaluation of preamble (%s) returned %d", preamble, r); 
    return r;
  }

  for(size_t i = 0; i < number; i++) {
    char line[80] = { 0 };
    if(!cb[i].use)
      continue;
    r = snprintf(line, sizeof(line), ": %s %u vm ; %s\n", cb[i].name, (unsigned)i, optimizer);
    assert(strlen(line) < sizeof(line) - 1);
    if(r < 0) {
      engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: format error in snprintf (returned %d)", r); 
      return -1;
    }
    if((r = embed_eval(vm, line)) < 0) {
      engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: evaluation of statement (%s) returned %d", line, r); 
      return r;
    }

    engine_trace(TRACE_LEVEL_ALWAYS, "Callback (%s) added to VM", cb[i].name); 
  }
  embed_reset(vm);
  return 0;
}

/******************************* PUBLIC FUNCTIONS *****************************/

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
        vm_engine_options.yield = vm_yield_cb;
        vm_engine_options.options |= EMBED_VM_QUITE_ON;
        //embed_opt_set(vm, &vm_engine_options);

        // We need here to add VM extensions, the very first time we create an VM
        VmExtension_t* vm_ext = vm_extension_new();

        if(!vm_ext) 
        {
          engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: unable to allocate VM extension"); 
          vm_free(vm);
          vm = NULL;
        }
        else
        {
          engine_trace(TRACE_LEVEL_ALWAYS, 
            "VM extension created at [%lp]", 
            vm_ext);

          // Join vm and its ext
          vm_ext->h = vm;

          // Use options defined by extension
          vm_engine_options.callback = vm_ext->o.callback;
          vm_engine_options.param = vm_ext;
          embed_opt_set(vm, &vm_engine_options);

          // control time each VM spends, we need to initialize yield to run some commands
          g_vm_start_time = time(NULL);
          g_vm_yield = 0;

          // Add all callbacks
          if(vm_add_callbacks(vm, true, vm_ext->callbacks, vm_ext->callbacks_length) < 0) 
          {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: unable to add VM callbacks"); 
            vm_free(vm);
            vm = NULL;
          }
        }
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
    int error = 0;

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

        if(vm)
        {         
          // Define output options to retrieve the buffer
          embed_opt_t vm_default_options = *embed_opt_get(vm);
          embed_opt_t vm_engine_options = vm_default_options;
          vm_engine_options.put = vm_putc_cb;
          vm_engine_options.yield = vm_yield_cb;
          vm_engine_options.options |= EMBED_VM_QUITE_ON;

          // We need here to add VM extensions again
          VmExtension_t* vm_ext = vm_extension_new();

          if(!vm_ext) 
          {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: unable to allocate VM extension"); 
            vm_free(vm);
            vm = NULL;
          }
          else
          {
            engine_trace(TRACE_LEVEL_ALWAYS, 
              "VM extension created at [%lp]", 
              vm_ext);

            // Join vm and its ext
            vm_ext->h = vm;

            // Use options defined by extension
            vm_engine_options.callback = vm_ext->o.callback;
            vm_engine_options.param = vm_ext;
            embed_opt_set(vm, &vm_engine_options);

            // control time each VM spends, we need to initialize yield to run some commands
            g_vm_start_time = time(NULL);
            g_vm_yield = 0;

            // Add all callbacks
            if(vm_add_callbacks(vm, true, vm_ext->callbacks, vm_ext->callbacks_length) < 0) 
            {
              engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: unable to add VM callbacks"); 
              vm_free(vm);
              vm = NULL;
            }
          }
        }

        if(vm && !error) 
        {
            // Load VM core from memory now
            error = embed_load_buffer(vm, (const uint8_t *)vm_bytes, size);

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
        // Check if it is an empty = 'abort' command
        if(strlen((const char*)command->code)) 
        {    
            engine_trace(TRACE_LEVEL_ALWAYS, "Running command: [%s]", command->code);

            g_output_buffer = out_buffer;
            g_last_command_output = g_output_buffer;
            g_current_size = out_size;
            memset(g_output_buffer, 0, g_current_size); 
             
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
            // ABORT COMMAND
            engine_trace(TRACE_LEVEL_ALWAYS, "ABORT command received");

            // Notify by email
            engine_vm_output_cb("Script aborted");

            // reset VM stuff
            embed_reset((forth_t*)vm);
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

            // Deallocate the extension if any
            if(vm->o.param)
            {
                engine_free(vm->o.param, sizeof(VmExtension_t));
                vm->o.param = NULL;
            }
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

  @brief      When invoked, sends email with current VM output buffer content

  @param[in]  vm       Current VM object

  @return     Serialized buffer (dynamically allocated) or NULL when error

*******************************************************************************/
void vm_report(VirtualMachine_t* vm)
{
    if(vm)
    {
        // invoke the engine to deliver the email with current content
        engine_vm_output_cb(g_output_buffer);

        // clean buffer and point to the beginning
        memset(g_output_buffer, 0, g_current_size); 
    }
}

/** ****************************************************************************

  @brief      Function to check if last VM execution was completed or yield

  @param[in]  None

  @return     0/1 value

*******************************************************************************/
int vm_is_yield() 
{
  return g_vm_yield;
}
