/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Protocol executions module

******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>
#include <limits.h>
#include <time.h>
#include <math.h>
#include <stdlib.h>

#include "protocol.h"
#include "trace.h"
#include "db.h"
#include "engine.h"
#include "vm.h"
#include "game_time.h"
#include "event_order.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

char protocolMsg[LINE_MAX];

/******************************* LOCAL FUNCTIONS *****************************/

/* The events an action is about to create. They are inserted together, in
   game-time order, once all its effects have been worked out (see
   protocol_flush_events) */
static Event_t* g_pending_events = NULL;
static int g_pending_count = 0;
static int g_pending_size = 0;

static ErrorCode_t protocol_queue_event(Event_t* event)
{
  if(g_pending_count == g_pending_size) {
    int size = g_pending_size ? g_pending_size * 2 : 16;
    Event_t* grown = realloc(g_pending_events, sizeof(Event_t) * size);
    if(!grown)
      return ENGINE_MEMORY_ALLOC_ERROR;
    g_pending_events = grown;
    g_pending_size = size;
  }
  g_pending_events[g_pending_count++] = *event;
  return ENGINE_OK;
}

/* Inserts the queued events in game-time order (or drops them) */
static ErrorCode_t protocol_flush_events(Bool_t insert)
{
  ErrorCode_t result = ENGINE_OK;

  // Game-time order; at the same game time, fuel burns first and each
  // equipment check's two halves stay together (event_order.h, #380)
  if(g_pending_count > 1) {
    EventKey_t* keys = malloc(sizeof(EventKey_t) * g_pending_count);
    int* order = malloc(sizeof(int) * g_pending_count);
    Event_t* sorted = malloc(sizeof(Event_t) * g_pending_count);
    if(!keys || !order || !sorted) {
      free(keys); free(order); free(sorted);
      g_pending_count = 0;
      return ENGINE_MEMORY_ALLOC_ERROR;
    }
    for(int i = 0; i < g_pending_count; i++) {
      Event_t* e = &g_pending_events[i];
      keys[i] = (EventKey_t){ e->delay, e->event_type, e->drone_id, e->resource_id,
                              e->installed, e->locked, e->new_quantity, e->burn };
    }
    event_order(keys, g_pending_count, order);
    for(int i = 0; i < g_pending_count; i++)
      sorted[i] = g_pending_events[order[i]];
    memcpy(g_pending_events, sorted, sizeof(Event_t) * g_pending_count);
    free(keys); free(order); free(sorted);
  }

  // Never before the drones' other events (#324 step 8). An event stamped
  // earlier than one already processed would be worked out from older state
  // and undo it (this engine reads the clock once per loop, and on a fast
  // clock the events engine can be ahead of that reading). And the inventory
  // takes the highest event ID of each of a drone's stock entries (resource,
  // installed, locked) as its latest state, so within an entry IDs must keep
  // game-time order. So the action starts a game second after the acting
  // drone's latest event, done or still to come (its script waits for its
  // own action anyway; this mostly delays power bills until the current
  // action ends). Another drone it touches, the seller in a pull or the
  // buyer in a push, only holds it back by events on the entries this action
  // changes (#380): a pull buys from a drone that is busy mining or
  // travelling at once, as long as both are at the same place.
  if(insert && g_pending_count > 0) {
    game_time_load();
    int acting = engine_get_current_drone_id();
    int drones[2 * 8];
    int n = 0;
    for(int i = 0; i < g_pending_count && n < (int)(sizeof(drones) / sizeof(drones[0])); i++) {
      int d = g_pending_events[i].drone_id, seen = 0;
      for(int j = 0; j < n; j++) seen |= (drones[j] == d);
      if(!seen) drones[n++] = d;
    }
    long long latest = 0, now = game_time_now_seconds();
    for(int j = 0; j < n; j++) {
      long long t = 0;
      if(drones[j] == acting || acting <= 0) {
        db_get_latest_processed_game_t(&drones[j], 1, &t);
      } else {
        // The other drone's stock entries this action changes
        int res[16], inst[16], lock[16], m = 0;
        for(int i = 0; i < g_pending_count && m < 16; i++) {
          Event_t* e = &g_pending_events[i];
          if(e->drone_id != drones[j] ||
             (e->event_type != INCREMENT_INVENTORY_EVENT_TYPE && e->event_type != DECREMENT_INVENTORY_EVENT_TYPE))
            continue;
          res[m] = e->resource_id; inst[m] = e->installed; lock[m] = e->locked; m++;
        }
        if(m > 0)
          db_get_latest_entry_game_t(drones[j], res, inst, lock, m, &t);
      }
      if(t > latest)
        latest = t;
    }
    if(latest >= now) {
      long long shift = latest + 1 - now;
      engine_trace(TRACE_LEVEL_ALWAYS, "Action starts [%lld] game s later, after the drones' latest event", shift);
      for(int i = 0; i < g_pending_count; i++)
        g_pending_events[i].delay += shift;
    }
  }

  // Inserted in this order, events that land in the same real second (at
  // a high game time scale) are still processed in game-time order: the
  // events engine runs an action's same-second events by ID
  for(int i = 0; insert && result == ENGINE_OK && i < g_pending_count; i++) {
    g_pending_events[i].event_id = 0;
    result = db_insert_event(&g_pending_events[i]);
  }

  g_pending_count = 0;
  return result;
}

/** ****************************************************************************

  @brief      Times a trip: when a protocol moves the drone (a location
              effect), the move happens after the travel time, and every
              effect at or after the move's time is delayed to match.

  Drones travel with constant acceleration a, speeding up to the midpoint
  and slowing down after: time = 2 * sqrt(distance / a), with the distance
  between the two bodies at departure (game time).

  @param[in|out]  protocol  Protocol being performed

  @return     void

*******************************************************************************/
static void protocol_plan_travel(ProtocolInfo_t *protocol)
{
  LocationEffect_t* effects = NULL;
  int effectsNum = 0;

  protocol->travel_from_time = 0;
  protocol->travel_shift = 0;

  if(db_get_location_effects(protocol, &effects, &effectsNum) != ENGINE_OK || effectsNum <= 0) {
    free(effects);
    return;
  }

  int destination = (effects[0].location == -1) ? protocol->optional_location : effects[0].location;
  int move_time = effects[0].time;
  free(effects);

  int origin = engine_get_current_drone_object_id();
  double acceleration = engine_get_travel_acceleration();
  double km = 0;

  if(destination < 0 || acceleration <= 0 ||
     engine_get_objects_distance(origin, destination, &km) != ENGINE_OK)
    return; // the location effect reports a bad destination

  double seconds = 2.0 * sqrt(km * 1000.0 / acceleration);
  if(seconds < 1) seconds = 1;
  if(seconds > 2e9) seconds = 2e9;

  protocol->travel_from_time = move_time;
  protocol->travel_shift = (int)lround(seconds) - move_time;

  engine_trace(TRACE_LEVEL_ALWAYS,
    "Trip from object [%d] to [%d]: [%.0f] km at [%.2f] m/s2 takes [%.0f] game seconds ([%d] real)",
    origin, destination, km, acceleration, seconds, game_time_to_real(seconds));
}

/** ****************************************************************************

  @brief      Checks a launch ([D K 12 perform]) and times it: the copy of
              drone D's program reaches the launching drone at the speed of
              light, so the launch is delayed by that light time.

  @param[in|out]  protocol  Protocol being performed

  @return     ENGINE_OK, or an error when K isn't a ship kit or D isn't one of
              the player's drones (perform is refused)

*******************************************************************************/
static ErrorCode_t protocol_plan_launch(ProtocolInfo_t *protocol)
{
  protocol->launch_delay = 0;

  if(protocol->protocol_id != LAUNCH_PROTOCOL_ID)
    return ENGINE_OK;

  int kit = protocol->parameters[LAUNCH_KIT_PARAM];
  int source = protocol->parameters[LAUNCH_SOURCE_PARAM];
  int source_object = -1;

  if(db_get_launch_source(engine_get_db_connection(), engine_get_current_drone_id(),
                          source, kit, &source_object) != ENGINE_OK) {
    engine_trace(TRACE_LEVEL_ALWAYS,
      "Launch refused: resource [%d] is not a ship kit or drone [%d] isn't the player's", kit, source);
    return ENGINE_LOGIC_ERROR;
  }

  double km = 0;
  int here = engine_get_current_drone_object_id();
  if(source_object != here && engine_get_objects_distance(source_object, here, &km) == ENGINE_OK)
    protocol->launch_delay = (int)lround(km / LIGHT_SPEED_KM_PER_SECOND);

  engine_trace(TRACE_LEVEL_ALWAYS,
    "Launch from kit [%d] with drone [%d]'s program: [%.0f] km away, [%d] game seconds of light time",
    kit, source, km, protocol->launch_delay);

  return ENGINE_OK;
}

/** ****************************************************************************

  @brief      Checks a wait ([M 3 perform], #324 step 14) and times it: its
              wait event lands M game minutes from now, so perform returns
              then. The script pauses meanwhile and runs no instructions.

  @param[in|out]  protocol  Protocol being performed

  @return     ENGINE_OK, or an error when M is out of range (perform is
              refused)

*******************************************************************************/
static ErrorCode_t protocol_plan_wait(ProtocolInfo_t *protocol)
{
  protocol->wait_delay = 0;

  if(protocol->protocol_id != WAIT_PROTOCOL_ID)
    return ENGINE_OK;

  int minutes = protocol->parameters[0];
  if(minutes < 1 || minutes > MAX_WAIT_MINUTES) {
    engine_trace(TRACE_LEVEL_ALWAYS, "Wait refused: [%d] game minutes (1 to %d)", minutes, MAX_WAIT_MINUTES);
    return ENGINE_LOGIC_ERROR;
  }
  protocol->wait_delay = minutes * 60;
  engine_trace(TRACE_LEVEL_ALWAYS, "Drone waits [%d] game minutes", minutes);
  return ENGINE_OK;
}

/** True when the protocol moves the drone to a location given on the stack */
static Bool_t protocol_takes_location(ProtocolInfo_t *protocol)
{
  LocationEffect_t* effects = NULL;
  int effectsNum = 0;
  Bool_t takes = ENGINE_FALSE;

  if(db_get_location_effects(protocol, &effects, &effectsNum) == ENGINE_OK) {
    for(int i = 0; i < effectsNum; i++)
      if(effects[i].location == -1) takes = ENGINE_TRUE;
  }
  free(effects);
  return takes;
}

/** Game seconds after the action starts that an effect takes place */
static int protocol_effect_delay(ProtocolInfo_t *protocol, int time)
{
  if(protocol->travel_shift && time >= protocol->travel_from_time)
    return time + protocol->travel_shift;
  return time;
}


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
    // Store them at array. A parameter marked as a double (#324 step 9)
    // takes two cells: its high cell (on top of the stack), then its low cell
    for(int i=0; i < protocol->parameters_num; i++) {
      result = vm_extension_pop(vmExt, &protocol->parameters[i]);
      if(result == ENGINE_OK && (protocol->double_params & (1 << i))) {
        int low = 0;
        result = vm_extension_pop(vmExt, &low);
        protocol->parameters[i] = (int)(int32_t)(((uint32_t)(protocol->parameters[i] & 0xFFFF) << 16) |
                                                 (uint32_t)(low & 0xFFFF));
      }
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
    memset(&resource, 0, sizeof(resource));
    resource.resource_id = effect->resource_id;
    result = protocol_validate_resource_effect(effect, &resource);

    if(result == ENGINE_OK) {
      // We just insert the event indicating the total amount change
      Event_t newEvent;
      memset(&newEvent, 0, sizeof(newEvent));

      // Add resource effect time delay (game seconds).
      newEvent.delay = protocol_effect_delay(protocol, effect->time);
      if(effect->event_type == LAUNCH_EVENT_TYPE)
        newEvent.delay += protocol->launch_delay;
      if(effect->event_type == WAIT_EVENT_TYPE)
        newEvent.delay += protocol->wait_delay;

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

        // The resource came from the stack: look it up now (its mass sets
        // the cargo change below)
        resource.resource_id = newEvent.resource_id;
        result = protocol_validate_resource_id(&resource);
        if(result != ENGINE_OK) {
          engine_trace(TRACE_LEVEL_ALWAYS,
            "ERROR: resource [%d] from the stack does not exist", newEvent.resource_id);
          return result;
        }
      }

      newEvent.event_type = effect->event_type;
      newEvent.action_id = action_id;
      newEvent.logged = 1; // TBD: We do not have the observation engine in place yet!!
      // newEvent.processed = 0; // Default memset()
      newEvent.installed = effect->installed;
      newEvent.locked = effect->locked;
      // The action's own fuel cost: fuel, a fixed amount, from the acting
      // drone (a transfer's fuel names its amount, and the other drone, on
      // the stack)
      newEvent.burn = (effect->resource_id == FUEL_RESOURCE_ID && effect->deplete &&
                       effect->quantity > 0 && effect->drone_id == 0) ? 1 : 0;
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

      // Apply multiplier. Fuel burned is per action, not per unit moved
      // or made (#324 step 8)
      if(effect->resource_id != FUEL_RESOURCE_ID)
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

        // Mining takes from the place's deposit (#324 step 11): at most
        // what is left, so a mined-out deposit yields nothing until it refills
        if(effect->abundancies && newEvent.event_type == 1 && newEvent.new_quantity > 0) {
          int objectId = 0;
          long long taken = newEvent.new_quantity;
          if(db_get_event_object_id(&newEvent, &objectId) == ENGINE_OK &&
             db_take_deposit(objectId, newEvent.resource_id, newEvent.new_quantity, &taken) == ENGINE_OK)
            newEvent.new_quantity = (int)taken;
        }

        newEvent.new_cargo = (newEvent.new_quantity * resource.resource_mass); // here we reflect the change in mass units
        // A launch carries drone D's ID in new_quantity, not an amount
        if(newEvent.event_type == LAUNCH_EVENT_TYPE)
          newEvent.new_cargo = NULL_VALUE;

        result = protocol_queue_event(&newEvent);
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

      result = protocol_queue_event(&newEvent);
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

    // Add time delay (game seconds): the trip's duration for a move
    newEvent.delay = protocol_effect_delay(protocol, effect->time);

    newEvent.new_location = (effect->location != -1)?effect->location:protocol->parameters[0]; // we use effect info or stack
    // rest of fields - set to 0 with memset()

    result = protocol_queue_event(&newEvent);
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
  memset(&action, 0, sizeof(action));

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

  // A broadcast (#380): read the message from the drone's memory now, while
  // the address means something; printable characters only
  char broadcast[BROADCAST_MAX_LEN + 1];
  broadcast[0] = 0;
  if(result == ENGINE_OK && protocol.protocol_id == BROADCAST_PROTOCOL_ID) {
    int len = vm_extension_read_counted(vmExt, protocol.parameters[0], broadcast, BROADCAST_MAX_LEN);
    for(int i = 0; i < len; i++)
      if((unsigned char)broadcast[i] < 32 || (unsigned char)broadcast[i] > 126) len = -1;
    if(len < 1) {
      engine_trace(TRACE_LEVEL_ALWAYS, "Broadcast refused: no message of 1 to %d printable characters at [%d]",
        BROADCAST_MAX_LEN, protocol.parameters[0]);
      result = ENGINE_LOGIC_ERROR;
    }
  }

  // Protocols with the multiplier flag (push, pull) always take it, after
  // their parameters: N R D 10 perform (#315). Taking it only when the stack
  // had a value made perform eat a script's own values. Missing, it is a
  // stack underflow (-4) like a missing parameter; below 1, perform refuses.
  if(result == ENGINE_OK && protocol.multiplier) {
    result = vm_extension_pop(vmExt, &stack_value);
    if(result == ENGINE_OK) {
        protocol.process_multiplier = (int16_t)stack_value;

        engine_trace(TRACE_LEVEL_ALWAYS,
            "Multiplier set to [%d] for protocol [%d]",
            protocol.process_multiplier,
            protocol.protocol_id);

        if(protocol.process_multiplier < 1) {
          engine_trace(TRACE_LEVEL_ALWAYS, "Multiplier [%d] refused: it must be at least 1",
            protocol.process_multiplier);
          result = ENGINE_LOGIC_ERROR;
        }
    }
  } else if(result == ENGINE_OK && !protocol.multiplier && protocol_takes_location(&protocol) &&
            vm_extension_depth(vmExt) > 0) {
    // A move whose destination comes from the stack (travel: N 5001
    // perform). Other protocols take no location: popping one would eat a
    // value that belongs to the script (#236)
    ErrorCode_t vm_result = vm_extension_pop(vmExt, &stack_value);
    if(vm_result == ENGINE_OK) {
      protocol.optional_location = stack_value;

      engine_trace(TRACE_LEVEL_ALWAYS,
            "Optional location set to [%d] for protocol [%d]",
            protocol.optional_location,
            protocol.protocol_id);
    }
  }

  if(result == ENGINE_OK)
    result = protocol_plan_launch(&protocol);

  if(result == ENGINE_OK)
    result = protocol_plan_wait(&protocol);

  if(result == ENGINE_OK) {
    protocol_plan_travel(&protocol);
    g_pending_count = 0;
    result = protocol_process_effects(&protocol, action.action_id);
    ErrorCode_t flushed = protocol_flush_events(result == ENGINE_OK ? ENGINE_TRUE : ENGINE_FALSE);
    if(result == ENGINE_OK) result = flushed;
  }

  if(result == ENGINE_OK && protocol.protocol_id == BROADCAST_PROTOCOL_ID) {
    game_time_load();
    result = db_insert_broadcast(action.action_id, engine_get_current_drone_id(), broadcast,
                                 game_time_now_seconds());
  }

  // perform leaves 0 on success or a negative code on failure (like an ior)
  if((result != ENGINE_OK) && (actionCreated == ENGINE_FALSE)) {
    // Unknown protocol (no action was created)
    vm_extension_push(vmExt, PERFORM_UNKNOWN_PROTOCOL);
  } else if((result != ENGINE_OK) && (actionCreated == ENGINE_TRUE)) {
    // Refused when performed (e.g. a parameter names something that doesn't
    // exist). Missing parameters never get here: popping them throws -4.
    vm_extension_push(vmExt, PERFORM_REFUSED);
    // delete any event for current action
    db_delete_action_events(action.action_id);
    // abort action
    db_abort_action(action.action_id);
  } else {
    // Accepted: the script pauses until the action's events have all been
    // processed, then perform leaves its result (see vm_wait_for_action)
    vm_wait_for_action(action.action_id);
  }

  return result;
}

/** ****************************************************************************

  @brief      Burns fuel for the current drone's computer (#324 step 8): plans
              the power protocol, [units 2 perform], as an action of its own.
              The script doesn't wait for it. If the fuel isn't there, the
              events engine fails the action and the drone becomes a wreck.

  @param[in]  units  Fuel to burn

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_burn_power(int units)
{
  ProtocolInfo_t protocol;
  Action_t action;

  if(units <= 0)
    return ENGINE_OK;

  memset(&protocol, 0, sizeof(protocol));
  memset(&action, 0, sizeof(action));
  protocol.protocol_id = POWER_PROTOCOL_ID;
  protocol.process_multiplier = 1;
  protocol.optional_location = -1;

  ErrorCode_t result = db_get_prococol_info(&protocol);
  if(result == ENGINE_OK)
    result = protocol_generate_action(&protocol, &action);

  if(result == ENGINE_OK) {
    protocol.parameters[0] = units;
    g_pending_count = 0;
    result = protocol_process_effects(&protocol, action.action_id);
    ErrorCode_t flushed = protocol_flush_events(result == ENGINE_OK ? ENGINE_TRUE : ENGINE_FALSE);
    if(result == ENGINE_OK) result = flushed;
    if(result != ENGINE_OK) {
      db_delete_action_events(action.action_id);
      db_abort_action(action.action_id);
    }
  }

  engine_trace(TRACE_LEVEL_ALWAYS, "Drone [%d] burns [%d] fuel for power (action [%d], result [%d])",
    engine_get_current_drone_id(), units, action.action_id, result);

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
