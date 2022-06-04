/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Protocol executions module

******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>
#include <limits.h>
#include <time.h>

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

  @brief          Validates a given resource ID

  @param[in|out]  resource   Whole resource info (we pass ID and retrieve the rest of fields)

  @return         Validation result

*******************************************************************************/
ErrorCode_t protocol_validate_resource_id(Resource_t *resource)
{
  ErrorCode_t result = ENGINE_OK;

  if(resource) {
    // Check it is at DB
    result = db_get_resource_info(resource);
  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to get resource info (NULL resource)");
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief          Validates a given drone ID agains our current drone

  @param[in|out]  drone_id   Drone ID read from DB

  @return         Validation result

*******************************************************************************/
ErrorCode_t protocol_validate_drone_id(int drone_id)
{
  // Does not care or is our current value => OK
  if((drone_id == RESOURCE_EFFECT_NULL_DRONE_ID) ||
     (drone_id == engine_get_current_drone_id())) {

    return ENGINE_OK;
  }

  return ENGINE_INTERNAL_ERROR;
}

/** ****************************************************************************

  @brief          Validates a given event type ID

  @param[in|out]  event_type   Whole event_type info (we pass ID and retrieve the rest of fields)

  @return         Validation result

*******************************************************************************/
ErrorCode_t protocol_validate_event_type(EventType_t *event_type)
{
  ErrorCode_t result = ENGINE_OK;

  if(event_type) {
    // Check it is at DB
    result = db_get_event_type_info(event_type);
  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to get event_type info (NULL event_type)");
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Validates resource effect info read from DB

  @param[in]     effect    Whole effect info
  @param[in|out] resource  Output resource info obtained when doing validation (resource id provided at input)

  @return     Validation result

*******************************************************************************/
ErrorCode_t protocol_validate_resource_effect(ResourceEffect_t *effect, Resource_t *resource)
{
  ErrorCode_t result = ENGINE_OK;

  if(effect) {
    EventType_t event_type;

    // Check resource
    if(resource->resource_id >= 0)
      result = protocol_validate_resource_id(resource);
    // Check droneID
    if(result == ENGINE_OK && (effect->drone_id >= 0)) {
      result = protocol_validate_drone_id(effect->drone_id);
    }
    // Check event type
    if(result == ENGINE_OK) {
      event_type.event_type_id = effect->event_type;
      result = protocol_validate_event_type(&event_type);
    }
    // Check local
    // Check local installed | locked | deplete | quantity | time ???
    if(result == ENGINE_OK) {
        engine_trace(TRACE_LEVEL_ALWAYS, "Valid resource effect: ID [%d]", effect->resource_effect_id);
    }
  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to validate resource effect (NULL effect)");
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Validates market effect info read from DB

  @param[in]     effect    Whole effect info
  @param[in|out] resource  Output resource info obtained when doing validation (resource id provided at input)

  @return     Validation result

*******************************************************************************/
ErrorCode_t protocol_validate_market_effect(MarketEffect_t *effect, Resource_t *resource)
{
  ErrorCode_t result = ENGINE_OK;

  if(effect) {
    EventType_t event_type;

    // Check resource
    if(resource->resource_id != -1)
      result = protocol_validate_resource_id(resource);

    // Check event type
    if(result == ENGINE_OK) {
      event_type.event_type_id = effect->event_type;
      result = protocol_validate_event_type(&event_type);
    }
  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to validate market effect (NULL effect)");
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Process a single resource effect

  @param[in]  effect    Whole effect info
  @param[in]  protocol  Whole protocol info
  @param[in]  action_id Current action ID

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_process_resource_effect(ResourceEffect_t *effect, ProtocolInfo_t *protocol, int action_id)
{
  ErrorCode_t result = ENGINE_OK;
  Abundancies_t abundancies;
  int parameterId = 0;

  if(effect) {
    memset(&abundancies, 0, sizeof(abundancies));
    abundancies.multiplier = 1; // default - no multiplier

    engine_trace(TRACE_LEVEL_ALWAYS,
        "Processing resource effect "
        "ID [%d] DRONE [%d] RESOURCE_ID [%d] EVENT_TYPE [%d] "
        "LOCAL [%d] INSTALLED [%d] LOCKED [%d] DEPLETE [%d] ABUNDANCIES [%d] QUANTITY [%d] TIME [%d] "
        "for PROTOCOL_ID [%d] MULTIPLIER [%d]",
        effect->resource_effect_id,
        effect->drone_id,
        effect->resource_id,
        effect->event_type,
        effect->local,
        effect->installed,
        effect->locked,
        effect->deplete,
        effect->abundancies,
        effect->quantity,
        effect->time,
        protocol->protocol_id,
        protocol->process_multiplier);

    // We validate resource effect info first (and get whole resource info)
    Resource_t resource;
    resource.resource_id = effect->resource_id;
    result = protocol_validate_resource_effect(effect, &resource);

    if(result == ENGINE_OK) {
      // We just insert the event indicating the total amount change
      Event_t newEvent;
      memset(&newEvent, 0, sizeof(newEvent));

      // Add resource effect time delay.
      newEvent.delay = effect->time;

      newEvent.drone_id = engine_get_current_drone_id();
      // Take into account negative parameters that mean => retrieve it from stack
      if(effect->drone_id < 0) {
        parameterId = (((-1) * effect->drone_id) - 1);
        if(parameterId >= protocol->parameters_num) {
          engine_trace(TRACE_LEVEL_ALWAYS,
            "ERROR: stack parameter [%d] can not be retrieved (only [%d] available)",
            parameterId,
            protocol->parameters_num);

          return ENGINE_INTERNAL_ERROR;
        }
        newEvent.drone_id = protocol->parameters[parameterId];
      }

      newEvent.resource_id = effect->resource_id;
      // Take into account negative parameters that mean => retrieve it from stack
      if(effect->resource_id < 0) {
        parameterId = (((-1) * effect->resource_id) - 1);
        if(parameterId >= protocol->parameters_num) {
          engine_trace(TRACE_LEVEL_ALWAYS,
            "ERROR: stack parameter [%d] can not be retrieved (only [%d] available)",
            parameterId,
            protocol->parameters_num);

          return ENGINE_INTERNAL_ERROR;
        }
        newEvent.resource_id = protocol->parameters[parameterId];
      }

      newEvent.event_type = effect->event_type;
      newEvent.action_id = action_id;
      newEvent.logged = 1; // TBD: We do not have the observation engine in place yet!!
      // newEvent.processed = 0; // Default memset()
      newEvent.installed = effect->installed;
      newEvent.locked = effect->locked;
      newEvent.outcome = OUTCOME_NO_OUTCOME;

      newEvent.new_quantity = NULL_VALUE;
      newEvent.new_credits = NULL_VALUE;
      newEvent.new_location = NULL_VALUE;
      newEvent.new_transmission = NULL_VALUE;
      newEvent.new_cargo = NULL_VALUE;

      newEvent.new_quantity = effect->quantity; // here only reflect the change in quantity

      // Take into account negative parameters that mean => retrieve it from stack
      if(effect->quantity < 0) {
        parameterId = (((-1) * effect->quantity) - 1);
        if(parameterId >= protocol->parameters_num) {
          engine_trace(TRACE_LEVEL_ALWAYS,
            "ERROR: stack parameter [%d] can not be retrieved (only [%d] available)",
            parameterId,
            protocol->parameters_num);

          return ENGINE_INTERNAL_ERROR;
        }
        newEvent.new_quantity = protocol->parameters[parameterId];
      }

      // if depletion - make it negative
      if(effect->deplete) {
        newEvent.new_quantity *= -1;
      }

      // Apply multiplier
      newEvent.new_quantity *= protocol->process_multiplier;

      // Apply abundancies when enabled and event type => increases cargo
      if(effect->abundancies  &&  (newEvent.event_type == 1)) {
        // calculate abundancies, when error just use 0 multiplier
        int objectId;
        result = db_get_event_object_id(&newEvent, &objectId);

        if(result == ENGINE_OK) {
            abundancies.resource_id = newEvent.resource_id;
            abundancies.location_id = objectId;

            result = db_get_abundancies(&abundancies);
            if(result != ENGINE_OK) {
                result = ENGINE_OK;
                // not found - no resource at this location
                abundancies.multiplier = 0;
            }
        }
      }

      if(result == ENGINE_OK) {
        newEvent.new_quantity *= abundancies.multiplier;
        newEvent.new_cargo = (newEvent.new_quantity * resource.resource_mass); // here we reflect the change in mass units

        result = db_insert_event(&newEvent);
      }
    }
  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process protocol resource effect (NULL effect)");
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Process a single market effect

  @param[in]  effect    Whole effect info
  @param[in]  protocol  Whole protocol info
  @param[in]  action_id Current action ID

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_process_market_effect(MarketEffect_t *effect, ProtocolInfo_t *protocol, int action_id)
{
  ErrorCode_t result = ENGINE_OK;

  if(effect) {
    engine_trace(TRACE_LEVEL_ALWAYS,
        "Processing market effect "
        "ID [%d] PROTOCOL_ID [%d] EVENT_TYPE [%d] RESOURCE_ID [%d] "
        "UPPER_LIMIT [%d] QUANTITY [%d] PRICE [%d] TIME [%d]",
        effect->market_effect_id,
        effect->protocol_id,
        effect->event_type,
        effect->resource_id,
        effect->upper_limit,
        effect->quantity,
        effect->price,
        effect->time);

    // Validations
    Resource_t resource;
    resource.resource_id = effect->resource_id;
    result = protocol_validate_market_effect(effect, &resource);

    // Adjust the protocol info received using market_effects rules
    if(result == ENGINE_OK) {
      // We just insert the event indicating the total amount change
      Event_t newEvent;
      memset(&newEvent, 0, sizeof(newEvent));
      newEvent.drone_id = engine_get_current_drone_id();
      newEvent.resource_id = (effect->resource_id == -1)?protocol->parameters[MARKET_RESOURCE_IDX]:effect->resource_id;
      newEvent.event_type = effect->event_type;
      newEvent.action_id = action_id;
      newEvent.new_quantity = NULL_VALUE;
      newEvent.new_credits = NULL_VALUE;
      newEvent.new_location = NULL_VALUE;
      newEvent.new_transmission = NULL_VALUE;
      newEvent.new_cargo = NULL_VALUE;
      // Default memset()
      // newEvent.logged = 0;
      // newEvent.processed = 0;
      // newEvent.installed = 0;
      // newEvent.locked = effect->locked;
      newEvent.outcome = OUTCOME_NO_OUTCOME;
      // Use stack defined limits
      newEvent.new_quantity = (effect->quantity == -1)?protocol->parameters[MARKET_QUANTITY_IDX]:effect->quantity;
      newEvent.new_credits = (effect->price == -1)?protocol->parameters[MARKET_PRICE_IDX]:effect->price;

      result = db_insert_event(&newEvent);
    }

  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process protocol market effect (NULL effect)");
    result = ENGINE_INTERNAL_ERROR;
  }

  return result;
}

/** ****************************************************************************

  @brief      Process a single location effect

  @param[in]  effect    Whole effect info
  @param[in]  protocol  Whole protocol info
  @param[in]  action_id Current action ID

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_process_location_effect
(
    LocationEffect_t *effect,
    ProtocolInfo_t *protocol,
    int action_id
)
{
  ErrorCode_t result = ENGINE_OK;
  int agent_id = engine_get_current_drone_id();

  if(!effect || !protocol) {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process location effect (NULL data)");
    result = ENGINE_INTERNAL_ERROR;
  }

  if(result == ENGINE_OK) {
    engine_trace(TRACE_LEVEL_ALWAYS,
        "Processing location effect "
        "ID [%d] EVENT_TYPE [%d] LOCATION [%d] TIME [%d] "
        "for PROTOCOL_ID [%d]",
        effect->location_effect_id,
        effect->event_type,
        effect->location,
        effect->time,
        effect->protocol_id);

    // When location is -1, apply user defined one
    if(effect->location == -1) {
      effect->location = protocol->optional_location;
      engine_trace(TRACE_LEVEL_ALWAYS, "Using user provided location: [%d]", effect->location);
    }

    // Validate that object exists
    ObjectOrbitInfo_t object;
    object.object_id = effect->location;
    result = db_get_orbit_info(engine_get_db_connection(), &object);
  }

  // Once validated - insert new event
  if(result == ENGINE_OK) {
    // We just insert the event indicating the total amount change
    Event_t newEvent;
    memset(&newEvent, 0, sizeof(newEvent));
    newEvent.drone_id = agent_id;
    newEvent.event_type = effect->event_type;
    newEvent.action_id = action_id;
    newEvent.logged = 1; // TBD: We do not have the observation engine in place yet!!
    newEvent.outcome = OUTCOME_NO_OUTCOME;
    newEvent.new_quantity = NULL_VALUE;
    newEvent.new_credits = NULL_VALUE;
    newEvent.new_location = NULL_VALUE;
    newEvent.new_transmission = NULL_VALUE;
    newEvent.new_cargo = NULL_VALUE;

    // Add time delay.
    newEvent.delay = effect->time;

    newEvent.new_location = (effect->location != -1)?effect->location:protocol->parameters[0]; // we use effect info or stack
    // rest of fields - set to 0 with memset()

    result = db_insert_event(&newEvent);
  } else {
    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process protocol location effect");
    result = ENGINE_INTERNAL_ERROR;
  }

  /* Update current drone location at DB BUGBUG Should not happen until the event is processed by the Event Engine.
  if(result == ENGINE_OK) {
    result = db_update_agent_object(engine_get_db_connection(), agent_id, effect->location);
  }*/

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
      result = protocol_process_resource_effect(&resourceEffects[effectId], protocol, action_id);

      if(result != ENGINE_OK) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process resource effect [%d] for protocol [%s]",
          resourceEffects[effectId].resource_effect_id,
          protocol->protocol_name);

        // Stop when any error is detected for this protocol
        break;
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
    MarketEffect_t* marketEffects = NULL;
    int effectsNum = 0;
    db_get_market_effects(protocol, &marketEffects, &effectsNum);

    for(int effectId=0; effectId < effectsNum; effectId++) {
      // Only process those effects with the same resource or when none specified at effect
      if((marketEffects[effectId].resource_id == -1) || (marketEffects[effectId].resource_id == protocol->parameters[MARKET_RESOURCE_IDX])) {
        result = protocol_process_market_effect(&marketEffects[effectId], protocol, action_id);

        if(result != ENGINE_OK) {
          engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to process market effect [%d] for protocol [%s]",
            marketEffects[effectId].market_effect_id,
            protocol->protocol_name);

          // Stop when any error is detected for this protocol
          break;
        }
      } else {
        engine_trace(TRACE_LEVEL_ALWAYS, "Market effect [%d] for protocol [%s] ignored (does not apply to current resource)",
          marketEffects[effectId].market_effect_id,
          protocol->protocol_name);
      }
    }
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

    if(protocol)
    {
        LocationEffect_t* effects = NULL;
        int effectsNum = 0;

        db_get_location_effects(protocol, &effects, &effectsNum);

        for(int effectId=0; effectId < effectsNum; effectId++) {
            result = protocol_process_location_effect(&effects[effectId], protocol, action_id);

            if(result != ENGINE_OK) {
                engine_trace(TRACE_LEVEL_ALWAYS,
                    "ERROR: Unable to process location effect [%d] for protocol [%s]",
                    effects[effectId].location_effect_id,
                    protocol->protocol_name);

                // Stop when any error is detected for this protocol
                break;
            }
        }
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

/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Execute protocol ID with given processMultiplier

  @param[in]  protocolId  Protocol ID
  @param[in]  vmExt       VM extension handler to be used if we need to communicate with forth

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_execute(int protocolId, VmExtension_t* vmExt)
{
  // Get from DB the rest of information for this protocol ID
  Bool_t actionCreated = ENGINE_FALSE;
  ProtocolInfo_t protocol;
  Action_t action;

  // Reset result msg
  protocolMsg[0] = 0;

  memset(&protocol, 0, sizeof(protocol));
  protocol.protocol_id = protocolId;
  protocol.process_multiplier = 1; // default value
  protocol.optional_location = -1; // default value

  engine_trace(TRACE_LEVEL_ALWAYS, "Executing protocol [%d]", protocol.protocol_id);

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

  int stack_value;

  if(result == ENGINE_OK && protocol.multiplier) {
    // When multiplier flag is enabled - check the presence of an extra multiplier at stack
    // multiplier is always after the list of params
    ErrorCode_t vm_result = vm_extension_pop(vmExt, &stack_value);
    if(vm_result == ENGINE_OK) {
        // We received the optional multiplier -> use it
        protocol.process_multiplier = stack_value;

        engine_trace(TRACE_LEVEL_ALWAYS,
            "Multiplier set to [%d] for protocol [%d]",
            protocol.process_multiplier,
            protocol.protocol_id);
    }
  } else if(result == ENGINE_OK) {
    // We can receive an optional location for location effects
    ErrorCode_t vm_result = vm_extension_pop(vmExt, &stack_value);
    if(vm_result == ENGINE_OK) {
      protocol.optional_location = stack_value;

      engine_trace(TRACE_LEVEL_ALWAYS,
            "Optional location set to [%d] for protocol [%d]",
            protocol.optional_location,
            protocol.protocol_id);
    }
  }

  if(result == ENGINE_OK) {
    result = protocol_process_effects(&protocol, action.action_id);
  }

  // Abort action when something failed
  if((result != ENGINE_OK) && (actionCreated == ENGINE_TRUE)) {
    // sprintf(protocolMsg, "Protocol \"%s\" failed", protocol.protocol_name);
    // Push zero into VM stack
    vm_extension_push(vmExt, 0);
    // delete any event for current action
    db_delete_action_events(action.action_id);
    // abort action
    db_abort_action(action.action_id);
  } else {
    // sprintf(protocolMsg, "Protocol \"%s\" succesfully executed", protocol.protocol_name);
    // Push action ID into VM stack
    vm_extension_push(vmExt, action.action_id);
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
