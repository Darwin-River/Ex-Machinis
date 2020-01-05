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
  X("query",    vm_ext_query_cb,  true)\
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

  @brief      Gets the new value for a given tag

  @param[in]  v         Current VM extension object
  @param[in]  tag       Tag whose new value we need to calculate
  @param[in]  queryInfo Current query info

  @return     New value calculated using input tag or NULL when error

*******************************************************************************/
char* vm_get_new_tag_value(VmExtension_t* v, const char *tag, Queries_t* queryInfo) 
{ 
    char *result = NULL;
    int value = -1;

    // input pointers checked by calling function 

    // [value_1] will be replaced by the first query specific parameter that is pulled from the stack and rendered as a decimal integer. 
    // [value_2], [value_3], and [value_4] will do the same with the second, third, and fourth query-specific parameters that are pulled from the stack.
    // [string_1] will be replaced by the string that is pointed to by the first query-specific parameter on the stack. 
    // The ASCII string will be pulled from the drone’s memory location pointed to by the user supplied address. 
    // [string_2], [string_3], and [string_4] will do the same for the second, third, and fourth user-supplied values found on the stack.
    // [drone_id] will be replaced by the integer ID of the drone that is performing the query.
    // [time_delay] will be replaced by the distance in light minutes that the drone is from Earth.

    if (!strcmp(tag, QUERY_TAG_DRONE_ID)) {
        // Get drone Id
        value = engine_get_current_drone_id();
        engine_trace(TRACE_LEVEL_ALWAYS, "Tag %s, drone ID [%d]", tag, value);

        // allocate an int value
        result = (char*) engine_malloc(MAX_QUERY_VALUE_BUF_LEN);
        sprintf(result, "%d", value);
    } else if (!strcmp(tag, QUERY_TAG_TIME_DELAY)) {
        // Get ligth minutes distance
        char drone_position[MAX_OBJECT_NAME_SIZE];
        double distance;
        AgentInfo_t agent_info;
        agent_info.agent_id = engine_get_current_drone_id();
        ErrorCode_t error = db_get_agent_engine_info(engine_get_db_connection(), agent_info.agent_id, &agent_info);
        if(error != ENGINE_OK) {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Tag %s could not be replaced, unable to get agent info", tag); 
            return NULL;
        }
        error = engine_get_drone_position(agent_info.object_id, drone_position, &distance);
        if(error != ENGINE_OK) {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Tag %s could not be replaced, unable to get drone position", tag); 
            return NULL;
        }
        engine_trace(TRACE_LEVEL_ALWAYS, "Tag %s distance value [%f]", tag, distance);
        result = (char*) engine_malloc(MAX_QUERY_VALUE_BUF_LEN);
        sprintf(result, "%f", distance);

    } else if (queryInfo->parametersNum > queryInfo->nextParamId) {
        // Get value from stack parameters
        value = queryInfo->parameterValues[queryInfo->nextParamId++];

        if(!strcmp(tag, QUERY_TAG_VALUE_1) || !strcmp(tag, QUERY_TAG_VALUE_2) || 
           !strcmp(tag, QUERY_TAG_VALUE_3) || !strcmp(tag, QUERY_TAG_VALUE_4))    {

            // we use directly the value got from stack
            engine_trace(TRACE_LEVEL_ALWAYS, "Tag %s stack value [%d]", tag, value);

            // allocate an int value
            result = (char*) engine_malloc(MAX_QUERY_VALUE_BUF_LEN);
            sprintf(result, "%d", value);
   
        } else if (!strcmp(tag, QUERY_TAG_STRING_1) || !strcmp(tag, QUERY_TAG_STRING_2) || 
                   !strcmp(tag, QUERY_TAG_STRING_3) || !strcmp(tag, QUERY_TAG_STRING_4))    {

            // the value got from stack is an address, get the string present there
            engine_trace(TRACE_LEVEL_ALWAYS, "Tag %s stack value address [%d]", tag, value);

            // Pick the string from the VM address obtained
            VirtualMachine_t* vm = v->h;
            cell_t len = embed_read_cell(vm, value);
            result = (char*) engine_malloc(len+1);
            embed_memcpy(vm, (unsigned char*)result, value+1, len);
            result[len] = 0;

            engine_trace(TRACE_LEVEL_ALWAYS, "VM string read [%s] len [%d]", result, len);

        } else {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unexpected/unsupported tag: %s", tag); 
            return NULL;
        } 
    } else {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "ERROR: Tag %s could not be replaced, not enough parameters [%d]", 
            tag,
            queryInfo->parametersNum); 

        return NULL;
    }

    return result;
}

/** ****************************************************************************

  @brief      Replace a given tag by the suitable value

  @param[in]  v           Current VM extension object
  @param[in]  queryInfo   Current query info
  @param[in]  tag         Tag to be replaced

  @return     Execution result (OK/KO)

*******************************************************************************/
ErrorCode_t vm_replace_tag(VmExtension_t* v, Queries_t* queryInfo, const char *tag) 
{ 
    char *s = queryInfo->finalQuery;
    char *result = NULL; 
    size_t inputLen = strlen(queryInfo->finalQuery);
    size_t tagLen = strlen(tag); 
    int tagCnt = 0;

    engine_trace(TRACE_LEVEL_ALWAYS, "Replacing tag [%s] at query script [%s]", tag, queryInfo->finalQuery); 

    // We need to build the SQL command using the query script + user supplied info

    // Get new value depending on input tag
    char* newValue = vm_get_new_tag_value(v, tag, queryInfo);
    if(!newValue) {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "ERROR: Unable to get new value for tag [%s] at query script [%s]", tag, s); 

        return ENGINE_LOGIC_ERROR;
    }

    engine_trace(TRACE_LEVEL_ALWAYS, "Tag %s will be replaced with value [%s]", tag, newValue); 

    size_t newValueLen = strlen(newValue);
  
    // Counting the number of times that input tag occurs in the string 
    for (int i = 0; s[i] != '\0'; i++) { 
        if (strstr(&s[i], tag) == &s[i]) { 
            tagCnt++; 
            // Jumping to index after the tag 
            i += tagLen - 1; 
        } 
    } 
  
    // Making new string of enough length 
    result = (char *)engine_malloc(inputLen + (tagCnt * (newValueLen - tagLen)) + 1); 
  
    int i = 0; 
    while (*s) 
    { 
        // compare the substring with the result 
        if (strstr(s, tag) == s) 
        { 
            strcpy(&result[i], newValue); 
            i += newValueLen; 
            s += tagLen; 
        } 
        else
            result[i++] = *s++; 
    } 
    result[i] = '\0'; 

    engine_trace(TRACE_LEVEL_ALWAYS, "Replacing tag [%s] at query script [%s], result [%s]", tag, queryInfo->finalQuery, result); 
  
    // update the string after replacement
    engine_free((void*)queryInfo->finalQuery, strlen(queryInfo->finalQuery)+1);
    queryInfo->finalQuery = result;

    // deallocate new value obtained
    engine_free((void*)newValue, MAX_QUERY_VALUE_BUF_LEN);
    newValue = NULL;

    return ENGINE_OK;
}

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

  @brief      Query processing routine

  @param[in]     v            Current VM extension object
  @param[in]     queryInfo    Current query info
  @param[in|out] queryOutMsg  Buffer where we store output result msg
  @param[in]     outMsgSize   Out buffer size

  @return     None

*******************************************************************************/
static void vm_ext_process_query
(
    VmExtension_t* v, 
    Queries_t* queryInfo, 
    char* queryOutMsg, 
    size_t outMsgSize
)
{
    // input pointers checked at calling function

    // clone original script to replace tags dynamically
    queryInfo->finalQuery = strdup(queryInfo->script);

    engine_trace(TRACE_LEVEL_ALWAYS, "Processing query script [%s]", queryInfo->finalQuery); 

    // Parse the script and replace the special tags by the suitable values read from VM memory
    char tags[MAX_QUERY_TAGS_NUM][MAX_QUERY_TAG_LEN] = 
    { 
        QUERY_TAG_VALUE_1,
        QUERY_TAG_VALUE_2,
        QUERY_TAG_VALUE_3,
        QUERY_TAG_VALUE_4,
        QUERY_TAG_STRING_1,
        QUERY_TAG_STRING_2,
        QUERY_TAG_STRING_3,
        QUERY_TAG_STRING_4,
        QUERY_TAG_DRONE_ID,
        QUERY_TAG_TIME_DELAY
    };

    for(int i=0; i < MAX_QUERY_TAGS_NUM; i++) {
        if(strstr(queryInfo->finalQuery, tags[i])) {
            if(vm_replace_tag(v, queryInfo, tags[i]) != ENGINE_OK) {
                engine_free(queryInfo->finalQuery, strlen(queryInfo->finalQuery)+1);

                sprintf(queryOutMsg, 
                    "Unable to replace tag [%s] at query script [%s]", 
                    tags[i],
                    queryInfo->script);

                engine_trace(TRACE_LEVEL_ALWAYS, queryOutMsg); 
                return;
            } else {
                engine_trace(TRACE_LEVEL_ALWAYS, "Query script after replacing tag %s is [%s]", 
                    tags[i], queryInfo->finalQuery);
            }
        }
    }

    engine_trace(TRACE_LEVEL_ALWAYS, "Query script after tags replacement [%s]", queryInfo->finalQuery);

    // Execute query at DB and fill the output array parameters with information retrieved
    if(db_run_vm_query(queryInfo) != ENGINE_OK) {
        sprintf(queryOutMsg, 
                "Query failed or could not retrive results [%s]", 
                queryInfo->finalQuery);

        engine_trace(TRACE_LEVEL_ALWAYS, queryOutMsg);
    }
    
    free(queryInfo->finalQuery);
}

/** ****************************************************************************

  @brief      Callback invoked by VM when query command is issued

  @param[in]  v Current VM extension object

  @return     Execution result

*******************************************************************************/
static int vm_ext_query_cb(VmExtension_t * const v) 
{
    Queries_t queryInfo;
    char queryOutMsg[LINE_MAX];
    Bool_t success = ENGINE_TRUE;

    memset(&queryInfo, 0, sizeof(Queries_t));

    engine_trace(TRACE_LEVEL_ALWAYS, "Running query callback"); 

    // First param (mandatory) is query ID
    queryInfo.id = pop(v);

    if(!v->error) {
        if(db_get_query_info(&queryInfo) == ENGINE_OK) {
            queryInfo.resultsArrayAddr = pop(v);

            if(v->error) {
                success = ENGINE_FALSE;

                sprintf(queryOutMsg, 
                    "Unable to get results array address from stack for query ID [%d]", 
                    queryInfo.id);

                engine_trace(TRACE_LEVEL_ALWAYS, queryOutMsg); 
            }
        } else {
            sprintf(queryOutMsg, "Query ID [%d] not found in DB", queryInfo.id);
            engine_trace(TRACE_LEVEL_ALWAYS, queryOutMsg);
            success = ENGINE_FALSE; 
        }

        if(success == ENGINE_TRUE) {
            queryInfo.resultsArraySize = pop(v);
            if(v->error) {
                success = ENGINE_FALSE;

                sprintf(queryOutMsg, 
                    "Unable to get results array size from stack for query ID [%d]", 
                    queryInfo.id);

                engine_trace(TRACE_LEVEL_ALWAYS, queryOutMsg); 
            }
        }

        if(success == ENGINE_TRUE && queryInfo.parametersNum) {
            // Allocate output parameters dynamically
            queryInfo.parameterValues = (int*) engine_malloc(sizeof(int) * queryInfo.parametersNum);

            if(!queryInfo.parameterValues) {
                sprintf(queryOutMsg, 
                    "Unable to allocate [%d] parameters for query ID [%d]",
                    queryInfo.parametersNum,
                    queryInfo.id);

                engine_trace(TRACE_LEVEL_ALWAYS, queryOutMsg); 
                success = ENGINE_FALSE;
            }
        }

        if(success == ENGINE_TRUE) {
            // Get parameters from stack
            for(int i=0; i < queryInfo.parametersNum; i++) {
                int parameter = pop(v);

                if(v->error) {
                    sprintf(queryOutMsg, 
                        "Unable to retrieve [%d] parameters from stack for query ID [%d] ([%d] read)",
                        queryInfo.parametersNum,
                        queryInfo.id,
                        i);

                    engine_trace(TRACE_LEVEL_ALWAYS, queryOutMsg); 
                    success = ENGINE_FALSE;

                    break; // stop for(;;)
                } else {
                    queryInfo.parameterValues[i] = parameter;
                    engine_trace(TRACE_LEVEL_ALWAYS, 
                        "Parameter[%d]=[%d] set for query ID [%d]",
                        i, parameter, queryInfo.id); 
                }
            }
        }

        if(success == ENGINE_TRUE) {
           sprintf(queryOutMsg, 
                    "Query ID [%d] with [%d] parameters succesfully processed, results at [%d], size [%d]",
                    queryInfo.id,
                    queryInfo.parametersNum,
                    queryInfo.resultsArrayAddr,
                    queryInfo.resultsArraySize);

            engine_trace(TRACE_LEVEL_ALWAYS, queryOutMsg); 

            // Process the query itself
            vm_ext_process_query(v, &queryInfo, queryOutMsg, LINE_MAX);

            // Deallocate
            if(queryInfo.parameterValues) {
                engine_free(queryInfo.parameterValues, sizeof(int) * queryInfo.parametersNum);
                queryInfo.parameterValues = NULL;
            }
        }

    } else {
        sprintf(queryOutMsg, "Unable to retrieve query id info from stack");
        engine_trace(TRACE_LEVEL_ALWAYS, queryOutMsg);
    }

    embed_puts(v->h, queryOutMsg);

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