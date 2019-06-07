/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Protocol executions module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>
#include <limits.h>

#include "protocol.h"
#include "trace.h"
#include "db.h"
#include "engine.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

char protocolMsg[LINE_MAX];

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief      Reads protocol parameters from stack

  @param[in]  protocol  Whole protocol info
  @param[in]  vmExt     Current VM extension

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_read_parameters(ProtocolInfo_t *protocol, VmExtension_t* vmExt) 
{
  ErrorCode_t result = ENGINE_OK;

  if(protocol && vmExt) {
    // Store them at array
    for(int i=0; i < protocol->parameters_num; i++) {
      result = vm_extension_pop(vmExt, &protocol->parameters[i]);
      if(result != ENGINE_OK) {
        engine_trace(TRACE_LEVEL_ALWAYS, 
          "ERROR: Unable to retrieve [%d] parameters from stack for protocol ID [%d]",
          protocol->parameters_num,
          protocol->protocol_id); 
        break; // stop here - stack error
      }  else {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Parameter[%d]=[%d], protocol ID [%d]",
            i,
            protocol->parameters[i],
            protocol->protocol_id); 
      }
    }

    if(result == ENGINE_OK) {
      engine_trace(TRACE_LEVEL_ALWAYS, 
            "[%d] parameters succesfully read from stack for protocol ID [%d]",
            protocol->parameters_num,
            protocol->protocol_id); 
    }

  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to read protocol parameters (NULL protocol)"); 
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Inserts a new action in DB using current information

  @param[in]  protocol  Whole protocol info
  @param[out] action    Action information inserted

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_generate_action(ProtocolInfo_t *protocol, Action_t *action) 
{
  ErrorCode_t result = ENGINE_OK;

  if(protocol && action) {
    memset(action, 0, sizeof(Action_t));
    action->drone_id = engine_get_current_drone_id();
    action->protocol_id = protocol->protocol_id;
    action->process_multiplier = protocol->process_multiplier;
    action->aborted = 0;

    result = db_insert_action(action);

  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to insert action in DB (NULL parameters)"); 
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Process a single resource effect

  @param[in]  protocol  Whole protocol info

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_process_resource_effect(ResourceEffect_t *effect) 
{
  ErrorCode_t result = ENGINE_OK;

  if(effect) {
    
  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process protocol resource effect (NULL effect)"); 
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Process a single market effect

  @param[in]  protocol  Whole protocol info

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_process_market_effect(MarketEffect_t *effect) 
{
  ErrorCode_t result = ENGINE_OK;

  if(effect) {
    
  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process protocol market effect (NULL effect)"); 
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Process a single location effect

  @param[in]  protocol  Whole protocol info

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_process_location_effect(MarketEffect_t *effect) 
{
  ErrorCode_t result = ENGINE_OK;

  if(effect) {
    
  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process protocol location effect (NULL effect)"); 
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Process resource effects for current protocol

  @param[in]  protocol  Whole protocol info
  @param[in]  action_id Current action ID

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_process_resource_effects(ProtocolInfo_t *protocol, int action_id) 
{
  ErrorCode_t result = ENGINE_OK;

  if(protocol) {
    ResourceEffect_t* resourceEffects = NULL;
    int effectsNum = 0;

    db_get_resource_effects(protocol, &resourceEffects, &effectsNum);

    for(int effectId=0; effectId < effectsNum; effectId++) {
      result = protocol_process_resource_effect(&resourceEffects[effectId]);

      if(result != ENGINE_OK) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process resource effect [%d] for protocol [%s]",
          resourceEffects[effectId].resource_effect_id,
          protocol->protocol_name);

        // Stop when any error is detected for this protocol   
        break;
      } else {
        // Insert event for current action (pass it as parameter)
      }
    }
  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process protocol resource effects (NULL protocol)"); 
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Process market effects for current protocol

  @param[in]  protocol  Whole protocol info
  @param[in]  action_id Current action ID

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_process_market_effects(ProtocolInfo_t *protocol, int action_id) 
{
  ErrorCode_t result = ENGINE_OK;

  if(protocol) {
    //MarketEffect_t* marketEffects = NULL;
    //int effectsNum = 0;
    //db_get_market_effects(&protocol, &marketEffects, &effectsNum);
  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process protocol market effects (NULL protocol)"); 
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Process location effects for current protocol

  @param[in]  protocol  Whole protocol info
  @param[in]  action_id Current action ID

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_process_location_effects(ProtocolInfo_t *protocol, int action_id) 
{
  ErrorCode_t result = ENGINE_OK;

  if(protocol) {
    //LocationEffect_t* locationEffects = NULL;
    //int effectsNum = 0;
    //db_get_location_effects(&protocol, &locationEffects, &effectsNum);
  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process protocol location effects (NULL protocol)"); 
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Process effects for current protocol

  @param[in]  protocol  Whole protocol info
  @param[in]  action_id Current action ID

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_process_effects(ProtocolInfo_t *protocol, int action_id) 
{
  ErrorCode_t result = ENGINE_OK;

  if(protocol) {
    result = protocol_process_resource_effects(protocol, action_id);

    if(result == ENGINE_OK)
      result = protocol_process_market_effects(protocol, action_id);

    if(result == ENGINE_OK)
      result = protocol_process_location_effects(protocol, action_id);

  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process protocol effects (NULL protocol)"); 
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Process internal protocols with special C++ routines

  @param[in]  protocol  Whole protocol info

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_process_internal(ProtocolInfo_t *protocol) 
{
  return ENGINE_OK;
}

/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Execute protocol ID with given processMultiplier

  @param[in]  protocolId  Protocol ID
  @param[in]  multiplier  Process multiplier
  @param[in]  vmExt       VM extension handler to be used if we need to communicate with forth

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_execute(int protocolId, int multiplier, VmExtension_t* vmExt) 
{
  // Get from DB the rest of information for this protocol ID
  Bool_t actionCreated = ENGINE_FALSE;
  ProtocolInfo_t protocol;
  Action_t action;

  // Reset result msg
  protocolMsg[0] = 0;

  memset(&protocol, 0, sizeof(protocol));
  protocol.protocol_id = protocolId;
  protocol.process_multiplier = multiplier;

  engine_trace(TRACE_LEVEL_ALWAYS, 
    "Executing protocol [%d] with [%d] parameters and process multiplier [%d]",
    protocol.protocol_id, 
    protocol.parameters_num, 
    protocol.process_multiplier);
  
  ErrorCode_t result = db_get_prococol_info(&protocol);

  if(result == ENGINE_OK) {
    // Insert entry in actions table
    result = protocol_generate_action(&protocol, &action);
  }

  if(result == ENGINE_OK) {
    actionCreated = ENGINE_TRUE;
    // Retrieve protocol parameters
    result = protocol_read_parameters(&protocol, vmExt); 
  }

  if(result == ENGINE_OK) {
    // Check if it is an internal protocol (treated with custom C++ routines) or not
    if(protocol.internal) {
      result = protocol_process_internal(&protocol);
    } else {
      // Check operation effects
      result = protocol_process_effects(&protocol, action.action_id);
    }
  }   

  // Abort action when something failed
  if((result != ENGINE_OK) && (actionCreated == ENGINE_TRUE)) {
    sprintf(protocolMsg, "Protocol \"%s\" failed", protocol.protocol_name);
    // delete any event for current action
    // 
    // abort action
    db_abort_action(action.action_id);
  } else {
    sprintf(protocolMsg, "Protocol \"%s\" succesfully executed", protocol.protocol_name);
  }

  return result;
}

/** ****************************************************************************

  @brief      Gets protocol execution message with result information

  @param[in]  void

  @return     Current result message

*******************************************************************************/
const char* protocol_get_result_msg() 
{
  return protocolMsg;
}