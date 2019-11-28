/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Virtual Machine extensions management module
               This module deals with user defined callbacks that deal with 
               with words at C side.
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>
#include <assert.h>
#include <limits.h>

#include "vm_extension.h"
#include "engine.h"
#include "trace.h"
#include "vm.h"
#include "db.h"
#include "protocol.h"

/******************************* DEFINES *************************************/

// x macro to manage callbacks
#define CALLBACK_XMACRO\
  X("perform",  vm_ext_execute_cb, true)\
  X("query",  vm_ext_query_cb,  true)\
  X("report",   vm_ext_report_cb, true)\
  X("dummy",    vm_ext_dummy_cb,  true)\

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/

// local prototypes for extension callbacks
#define X(NAME, FUNCTION, USE) static int FUNCTION ( VmExtension_t * const v );
  CALLBACK_XMACRO
#undef X

/******************************* GLOBAL VARIABLES ****************************/

// Callbacks defined
static VmExtensionCb_t callbacks[] = {
#define X(NAME, FUNCTION, USE) { .name = NAME, .cb = FUNCTION, .use = USE },
  CALLBACK_XMACRO
#undef X
};

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief      Callback invoked by VM when we need to write a character

  @param[in]  ch Char received from VM
  @param[in]  file, handle needed to write to a source, if needed

  @return     ch on success, negative on failure

*******************************************************************************/
static inline size_t vm_ext_callbacks_num(void) { return sizeof(callbacks) / sizeof(callbacks[0]); }


static inline cell_t eset(VmExtension_t * const v, const cell_t error) { /**< set error register if not set */
  assert(v);
  if(!(v->error))
    v->error = error;
  return v->error;
}

static inline cell_t eget(VmExtension_t const * const v) { /**< get current error register */
  assert(v);
  return v->error;
}

static inline cell_t eclr(VmExtension_t * const v) { /**< clear error register and return value before clear */
  assert(v);
  const cell_t error = v->error;
  v->error = 0;
  return error;
}

static inline cell_t pop(VmExtension_t *v) {
  assert(v);
  if(eget(v))
    return 0;
  cell_t rv = 0;
  int e = 0;
  if((e = embed_pop(v->h, &rv)) < 0)
    eset(v, e);
  return rv;
}

static inline void push(VmExtension_t * const v, const cell_t value) {
  assert(v);
  if(eget(v))
    return;
  int e = 0;
  if((e = embed_push(v->h, value)) < 0)
    eset(v, e);
}

static inline void udpush(VmExtension_t * const v, const double_cell_t value) {
  push(v, value);
  push(v, value >> 16);
}

static inline double_cell_t udpop(VmExtension_t * const v) {
  const double_cell_t hi = pop(v);
  const double_cell_t lo = pop(v);
  const double_cell_t d  = (hi << 16) | lo;
  return d;
}

static inline sdc_t dpop(VmExtension_t * const v)                     { return udpop(v); }
static inline void  dpush(VmExtension_t * const v, const sdc_t value) { udpush(v, value); }

/** ****************************************************************************

  @brief      Callback invoked by VM when excute command is issued

  @param[in]  v Current VM extension object

  @return     Execution result

*******************************************************************************/
static int vm_ext_execute_cb(VmExtension_t * const v) 
{
    engine_trace(TRACE_LEVEL_ALWAYS, "Running execute callback"); 

    // pop just the protocol ID from stack
    // and a variable number of parameters depending on DB configuration
    cell_t protocolId;

    // Build the output message depending on result
    char executeOutMsg[LINE_MAX];

    protocolId = pop(v);

    if(!v->error) {
        // Execute protocol and output result (for debugging)
        protocol_execute((int)protocolId, v);
        snprintf(executeOutMsg, LINE_MAX, "%s", protocol_get_result_msg());
    } else {
        sprintf(executeOutMsg, "Unable to retrieve protocol info from stack");
    }

    embed_puts(v->h, executeOutMsg);

    return 0;
}

/** ****************************************************************************

  @brief      Callback invoked by VM when report command is issued

  @param[in]  v Current VM extension object

  @return     Execution result

*******************************************************************************/
static int vm_ext_report_cb(VmExtension_t * const v) 
{
  engine_trace(TRACE_LEVEL_ALWAYS, "Running report callback"); 

  // Send current buffer content by email
  vm_report((VirtualMachine_t*)v->h);

  // At report just do echo
  embed_puts(v->h, "report");

  return 0;
}

/** ****************************************************************************

  @brief      Callback invoked by VM when query command is issued

  @param[in]  v Current VM extension object

  @return     Execution result

*******************************************************************************/
static int vm_ext_query_cb(VmExtension_t * const v) 
{
    engine_trace(TRACE_LEVEL_ALWAYS, "Running query callback"); 

    char executeOutMsg[LINE_MAX];
    sprintf(executeOutMsg, "Command not supported yet");

    embed_puts(v->h, executeOutMsg);

    return 0;
}

/** ****************************************************************************

  @brief      Callback invoked by VM when report command is issued

  @param[in]  v Current VM extension object

  @return     Execution result

*******************************************************************************/
static int vm_ext_dummy_cb(VmExtension_t * const v) 
{
  engine_trace(TRACE_LEVEL_ALWAYS, "Running dummy callback");  

  // Simulate a VM response
  embed_puts(v->h, "Here goes dummy result: xxxx");

  return 0;
}

/** ****************************************************************************

  @brief      The VM has only one callback where we select (using the stack) the 
              function from callbacks vector

  @param[in]  h     Current VM
  @param[in]  param Current VM extension

  @return     Execution result

*******************************************************************************/
static int callback_selector(embed_t *h, void *param) 
{
  assert(h);
  assert(param);

  // Pick the extension object
  VmExtension_t *vm_ext = (VmExtension_t*)param;

  // Double check that VM is OK
  if(vm_ext->h != h)
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Corrupted VM extension instance"); 

  eclr(vm_ext);
  const cell_t func = pop(vm_ext);
  
  if(eget(vm_ext))
    return eclr(vm_ext);

  if(func >= vm_ext->callbacks_length)
    return -21;

  const VmExtensionCb_t *cb = &vm_ext->callbacks[func];
  
  if(!(cb->use))
    return -21;
  
  // Execute it
  return cb->cb(vm_ext);
}


/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Allocate a new VM extension object with the pre-defined callbacks

  @param[in]  None

  @return     Object created or NULL when failed

*******************************************************************************/
VmExtension_t* vm_extension_new(void) 
{
  VmExtension_t *v = engine_malloc(sizeof(*v));

  // Allocate extension
  if(!v)
    return NULL;

  v->callbacks_length = vm_ext_callbacks_num(),
  v->callbacks        = callbacks;
  v->o                = embed_opt_default_hosted();
  v->o.callback       = callback_selector;
  v->o.param          = v;

  return v;
}

/** ****************************************************************************

  @brief      Pops a value from a given VM and returns it

  @param[in]  vmExt      Current VM extension
  @param[out] outValue   Out buffer where we store value obtained

  @return     Execution result

*******************************************************************************/
ErrorCode_t vm_extension_pop(VmExtension_t* vmExt, int* outValue) 
{
  if(vmExt && outValue) {
    *outValue = pop(vmExt);
    if(!vmExt->error)
      return ENGINE_OK;
  }

  return ENGINE_FORTH_EVAL_ERROR;
}

/** ****************************************************************************

  @brief      Push a value into VM stack

  @param[in]  vmExt      Current VM extension
  @param[in]  outValue   Value to be pushed into stack

  @return     Execution result

*******************************************************************************/
ErrorCode_t vm_extension_push(VmExtension_t* vmExt, int inValue) 
{
  if(vmExt) {
    push(vmExt, (cell_t)inValue);
    if(!vmExt->error) {
      engine_trace(TRACE_LEVEL_ALWAYS, "Value [%d] pushed into stack", inValue); 
      return ENGINE_OK;
    }
  }

  return ENGINE_FORTH_EVAL_ERROR;
}