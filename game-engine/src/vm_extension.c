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

/******************************* DEFINES *************************************/

// x macro to manage callbacks
#define CALLBACK_XMACRO\
  X("execute",  vm_ext_execute_cb, true)\
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

  // Send current buffer content by email
  //vm_report((VirtualMachine_t*)v->h);

  // pop the latest 2 values present at stack: they will be the protocolId + processMultiplier
  cell_t protocolId = pop(v);
  cell_t processMultiplier = pop(v);

  // Build the output message depending on result
  char executeOutMsg[LINE_MAX];

  if(v->error) {   
    sprintf(executeOutMsg, "Execute command error");
  } else { 
    // Get from DB the rest of information for this protocol ID
    ProtocolInfo_t protocol;
    memset(&protocol, 0, sizeof(protocol));
    protocol.protocol_id = (int)protocolId;
    protocol.process_multiplier = (int)processMultiplier;
    
    ErrorCode_t result = db_get_prococol_info(engine_get_db_connection(), &protocol);  

    if(result == ENGINE_OK) {
      sprintf(executeOutMsg, 
        "Executing protocol  <%s> with <%d> parameters and process multiplier <%d>",
        protocol.protocol_name, protocol.parameters_num, protocol.process_multiplier);

      // Insert entry in actions table
      Action_t action;
      action.drone_id = engine_get_current_drone_id();
      action.protocol_id = protocol.protocol_id;
      action.process_multiplier = protocol.process_multiplier;
      action.aborted = 0;

      db_insert_action(engine_get_db_connection(), &action);

      if(protocol.parameters_num <= MAX_PROTOCOL_PARAMETERS_NUM) {
        // Store them at array
        for(int i=0; i < protocol.parameters_num; i++) {
          protocol.parameters[i] = pop(v);
          if(v->error) {
            sprintf(executeOutMsg, 
              "Unable to get <%d> parameters from stack for protocol_id <%s>",
              protocol.parameters_num, protocol.protocol_name);

            db_abort_action(engine_get_db_connection(), action.action_id);
            break;
          }  
        }

        if(!v->error) {
          // Go ahead
        }  

      } else {
        sprintf(executeOutMsg, 
          "Wrong number of protocol parameters <%d> configured for protocol_id <%d>",
          protocol.parameters_num, protocol.protocol_id);
      }

    } else {
      sprintf(executeOutMsg, 
        "Unable to find info at DB for protocol_id <%d>",
        protocolId);
    }
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