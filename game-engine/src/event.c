/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Event Engine

******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>
#include <limits.h>
#include <time.h>

#include "db_startup.h"
#include "event.h"
#include "db_station.h"
#include "db_wreck.h"
#include "db_shares.h"
#include "wreck.h"
#include "engine_e.h"
#include "db.h"
#include "db_e.h"
#include "orbits.h"
#include "game_time.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

// The action of the event being settled (#446): each event asked MySQL for
// it two or three times (is it aborted, is it a transfer, what protocol).
// Good for one event_process_outcome call; an abort clears it.
static Action_t current_action;
static int current_action_valid = 0;

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief      Gets an event's action, once per event (#446)

  @param[in]  action_id  The action
  @param[out] action     Its row

  @return     Execution result

*******************************************************************************/
/*
 * A batch that hit a passing database error (#711, #712): a deadlock with the
 * website's tick, a lock wait timeout, a lost connection or a full disk. Such
 * an error is no reason to fail the drone's action: until round 70 it was
 * recorded as the event's failure and the action aborted, and when that fell
 * between a recipe locking its tool and unlocking it, the tool stayed locked
 * for good and every later make or mine failed (-2). Now the rest of the
 * batch is skipped, the engine rolls the batch back and runs it again.
 */
static Bool_t batch_retry = ENGINE_FALSE;

void event_batch_start(void) { batch_retry = ENGINE_FALSE; }
Bool_t event_batch_retry(void) { return batch_retry; }

static Bool_t event_db_error_is_transient(void)
{
    DbConnection_t *connection = ee_engine_get_db_connection();
    if(connection == NULL || connection->hndl == NULL)
        return ENGINE_TRUE;
    switch(mysql_errno(connection->hndl)) {
        case 1205:   // lock wait timeout
        case 1213:   // deadlock
        case 1021:   // disk full
        case 1114:   // table full
        case 2006:   // server gone away
        case 2013:   // lost connection during a query
        case 2055:   // lost connection
            return ENGINE_TRUE;
        default:
            return ENGINE_FALSE;
    }
}

/* Marks the batch for a retry when the last database error was a passing one */
static Bool_t event_retry_if_transient(Event_t *event)
{
    if(!event_db_error_is_transient())
        return ENGINE_FALSE;
    ee_engine_trace(TRACE_LEVEL_ALWAYS,
        "WARNING: event [%d] of action [%d] hit a passing database error [%u]: the batch is rolled back and retried",
        event->event_id, event->action_id, mysql_errno(ee_engine_get_db_connection()->hndl));
    batch_retry = ENGINE_TRUE;
    return ENGINE_TRUE;
}

static ErrorCode_t event_get_action(int action_id, Action_t *action)
{
    if(current_action_valid && current_action.action_id == action_id) {
        *action = current_action;
        return ENGINE_OK;
    }

    memset(action, 0, sizeof(*action));
    action->action_id = action_id;
    ErrorCode_t result = db_get_action(action);
    if(result == ENGINE_OK) {
        current_action = *action;
        current_action_valid = 1;
    }
    return result;
}

/** ****************************************************************************

  @brief      The EE will search for the last processed event with the same drone
              and add that quantity to the new_cargo field of the current event.

  @param[in]  event                    Input event
  @param[in]  previous_event           Previous valid event info for this drone
  @param[in]  previous_resource_event  Previous valid event info for this resource

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_update_new_cargo_and_quantity
(
  Event_t* event,
  Event_t* previous_event,
  Event_t* previous_resource_event
)
{
    ErrorCode_t result = ENGINE_OK;

    // new_cargo.  The EE will search for the last processed event with the same drone and add that quantity to the new_cargo field of the current event.

    event_trace(event);

    if(event == NULL)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL event received, unable to update new cargo");
        result = ENGINE_INTERNAL_ERROR;
    }

    if(previous_event == NULL)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL previous_event received, unable to update new cargo");
        result = ENGINE_INTERNAL_ERROR;
    }

    if(previous_resource_event == NULL)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL previous_resource_event received, unable to update new cargo");
        result = ENGINE_INTERNAL_ERROR;
    }

    if(previous_event->new_cargo == NULL_VALUE) previous_event->new_cargo = 0; // first cargo
    if(previous_resource_event->new_quantity == NULL_VALUE) previous_resource_event->new_quantity = 0; // first time

    if((result == ENGINE_OK) && (event->new_quantity != NULL_VALUE))
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
          "New quantity %d, prev_event_cargo %d, previous_event_resource_quantity %d",
          event->new_quantity, previous_event->new_cargo,
          previous_resource_event->new_quantity);

        // Update quantities
        //event->new_cargo = (previous_event->new_cargo + event->new_quantity);
        event->new_quantity += previous_resource_event->new_quantity;
    }

    // Calculate new cargo using all previous events
    DroneResources_t *resources = NULL;
    int resourcesNum = 0;
    result = db_get_drone_resources(event->drone_id, &resources, &resourcesNum);
    if(result == ENGINE_OK)
    {
        // add all those quantities >= 0 to obtain the final cargo
        int total_cargo = 0;
        for(int i=0; i < resourcesNum; i++) {
            DroneResources_t* resource = (resources + i); // shortcut
            if(resource->quantity >= 0) {
                total_cargo += (resource->quantity * resource->mass);
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                  "Adding resource [%s], quantity [%d], mass [%d] to new_cargo, total now [%d]",
                  resource->name, resource->quantity, resource->mass,
                  total_cargo);
            }
        }

        // add current event new_cargo that already contains (mass * quantity)
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
          "Adding current event cargo [%d] to [%d]",
          event->new_cargo,
          total_cargo);

        event->new_cargo += total_cargo;
    }
    // db_get_drone_resources mallocs the list; it leaked once per inventory
    // event, about 450 MB a game day's worth at full load (#380)
    free(resources);

    event_trace(event);

    return result;
}

/** ****************************************************************************

  @brief      The EE will search for the last processed event for any drone belonging
              to the owner of the current drone and add the quantity of the new_credit
              field of the current event.

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_update_new_credit(Event_t *event)
{
    ErrorCode_t result = ENGINE_OK;
    Event_t previous_event;

    memset(&previous_event, 0, sizeof(previous_event));

    // The EE will search for the last processed event for any drone belonging to the owner of the current drone and add the quantity of the new_credit field of the current event.

    if(event == NULL)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL event received, unable to update new credits");
        result = ENGINE_INTERNAL_ERROR;
    }

    // Only an event that carries credits uses the company's latest event
    // (#435): the lookup cost ~11 ms an event with 183 drones, and about
    // three in four events (depletions, most increments) carry none
    if(result == ENGINE_OK && !(event->new_credits >= 0 && event->new_credits != NULL_VALUE))
        return ENGINE_OK;

    if(result == ENGINE_OK)
    {
        // search for the last processed event with the same drone
        result = db_get_previous_event(event, PREVIOUS_EVENT_BY_OWNER, &previous_event);
    }

    if(result == ENGINE_OK)
    {
        // We obtained and event - do the maths to update new_quantity
        event->new_credits += previous_event.new_credits;
    }

    if(result == ENGINE_NOT_FOUND)
    {
        // not found is ok to continue logic
        result = ENGINE_OK;
    }

    return result;
}

/** ****************************************************************************

  @brief      Check given condition is met

  @param[in]  event  Input event

  @return     Boolean (TRUE when condition is met, FALSE otherwise)

*******************************************************************************/
Bool_t event_no_local_condition(Event_t *event)
{
    return ENGINE_FALSE;
}

/** ****************************************************************************

  @brief      Check given condition is met

  @param[in]  event                    Input event
  @param[in]  previous_event           Previous drone event
  @param[in]  previous_resource_event  Previous event for this resource

  @return     Boolean (TRUE when condition is met, FALSE otherwise)

*******************************************************************************/
Bool_t event_no_resources_condition
(
    Event_t* event,
    Event_t* previous_event,
    Event_t* previous_resource_event
)
{
    // Check if we can execute this resource change
    if((event->new_quantity != NULL_VALUE) && (event->new_quantity < 0)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "Current resources quantity [%d] is not enough for a depletion of [%d]",
            previous_resource_event->new_quantity, previous_event->new_quantity);

        return ENGINE_TRUE;
    }

    // Nor take a tool in use (#814): a decrement of an unlocked holding
    // can't leave fewer units than the locks on it
    if(event->event_type == DECREMENT_INVENTORY_EVENT_TYPE && event->locked == 0 && event->new_quantity != NULL_VALUE &&
       db_tool_lock_blocks(event->drone_id, event->resource_id, event->installed, event->new_quantity)) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "Resource [%d] of drone [%d]: [%d] would be left, fewer than its tools in use",
            event->resource_id, event->drone_id, event->new_quantity);
        return ENGINE_TRUE;
    }


    return ENGINE_FALSE;
}

/** ****************************************************************************

  @brief      Check given condition is met

  @param[in]  event  Input event

  @return     Boolean (TRUE when condition is met, FALSE otherwise)

*******************************************************************************/
Bool_t event_no_cargo_condition(Event_t *event)
{
    int capacity = 0;

    if(event->new_cargo == NULL_VALUE)
      return ENGINE_FALSE;

    // Taking something out of the hold can't overfill it (#324 step 8: a
    // drone already over capacity must still be able to burn fuel)
    if(event->event_type == DECREMENT_INVENTORY_EVENT_TYPE)
      return ENGINE_FALSE;

    if(db_get_max_drone_cargo(event->drone_id, &capacity) == ENGINE_OK) {
        // Compare current cargo vs. max capacity
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "Current cargo [%d], max capacity [%d]",
            event->new_cargo, capacity);

        if(event->new_cargo <= capacity) {
            return ENGINE_FALSE;
        }
        // else { we do not know the max capacity - reject event }
    }

    return ENGINE_TRUE;
}

/** ****************************************************************************

  @brief      Check given condition is met

  @param[in]  event  Input event

  @return     Boolean (TRUE when condition is met, FALSE otherwise)

*******************************************************************************/
Bool_t event_no_credits_condition(Event_t *event)
{
    return ENGINE_FALSE;
}

/** ****************************************************************************

  @brief      Check given condition is met

  @param[in]  event  Input event

  @return     Boolean (TRUE when condition is met, FALSE otherwise)

*******************************************************************************/
Bool_t event_lower_resources_limit_condition(Event_t *event)
{
    return ENGINE_FALSE;
}

/** ****************************************************************************

  @brief      Check given condition is met

  @param[in]  event  Input event

  @return     Boolean (TRUE when condition is met, FALSE otherwise)

*******************************************************************************/
Bool_t event_upper_resources_limit_condition(Event_t *event)
{
    return ENGINE_FALSE;
}

/** ****************************************************************************

  @brief      Check given condition is met

  @param[in]  event  Input event

  @return     Boolean (TRUE when condition is met, FALSE otherwise)

*******************************************************************************/
Bool_t event_wrong_depletion_price_condition(Event_t *event)
{
    return ENGINE_FALSE;
}

/** ****************************************************************************

  @brief      Check given condition is met

  @param[in]  event  Input event

  @return     Boolean (TRUE when condition is met, FALSE otherwise)

*******************************************************************************/
Bool_t event_wrong_accumulation_price_condition(Event_t *event)
{
    return ENGINE_FALSE;
}

/** ****************************************************************************

  @brief      Validate the event conditions to decide if can continue or not

  @param[in]  event                   Input event
  @param[in]  previous_event          Previous valid event info for this drone
  @param[in]  previous_resource_event Previous valid event info for this resource

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_validate_event_conditions
(
    Event_t* event,
    Event_t* previous_event,
    Event_t* previous_resource_event
)
{
    ErrorCode_t result = ENGINE_LOGIC_ERROR;

    // Pointers checked by calling function

    // Update outcome value using Outcome_t enum depending on validations

    // Check event conditions to ensure that it can take place
    if(event_no_local_condition(event) == ENGINE_TRUE) {
        //a.  The local field is set to true but the affected drone is not local.
        event->outcome = OUTCOME_NO_LOCAL;
    } else if(event_no_resources_condition(event, previous_event, previous_resource_event) == ENGINE_TRUE) {
        //b.  The affected ship contains insufficient resources of the indicated state
        // (identical resource, locked, and installed values) to accommodate a depletion event.
        // This happens when the newly computed new_quantity field is less than zero.
        event->outcome = OUTCOME_NO_RESOURCES;
    } else if(event_no_cargo_condition(event) == ENGINE_TRUE) {
        //c.  There affected ship contains insufficient cargo space to accommodate the accumulation event.
        // This happens when the recently computed new_cargo value is greater than the cargo capacity of the drone (found in hull table)
        // We need to return to previous event cargo
        event->outcome = OUTCOME_NO_CARGO_SPACE;
    } else if(event_no_credits_condition(event) == ENGINE_TRUE) {
        //d.  The player has insufficient credits to cover the purchase price of transferred items.
        event->outcome = OUTCOME_NO_CREDITS;
    } else if(event_lower_resources_limit_condition(event) == ENGINE_TRUE) {
        // e.  The depletion event will violate the affected drone’s self imposed lower limit for the resource.
        // This only applies to cases in which the acting and affected ships are different.
        event->outcome = OUTCOME_RESOURCES_LOWER_LIMIT;
    } else if(event_upper_resources_limit_condition(event) == ENGINE_TRUE) {
        //f.  The accumulation event will violate the affected drone’s self imposed upper limit for the resource.
        // This only applies to cases in which the acting and affected ships are different.
        event->outcome = OUTCOME_RESOURCES_UPPER_LIMIT;
    } else if(event_wrong_depletion_price_condition(event) == ENGINE_TRUE) {
        //g.  The Depletion Price for items being removed from the cargo bay of a non-allied ship is equal to or greater
        // than the price the player has set on those items.
        event->outcome = OUTCOME_WRONG_DEPLETION_PRICE;
    } else if(event_wrong_accumulation_price_condition(event) == ENGINE_TRUE) {
        //h.  The Accumulation Price for items being added to the cargo bay of a non-allied ship is less than
        // or equal to the price the player has set for those items.
        event->outcome = OUTCOME_WRONG_ACCUMULATION_PRICE;
    } else {
        event->outcome = OUTCOME_OK;
        result = ENGINE_OK;
    }

    ee_engine_trace(TRACE_LEVEL_ALWAYS,
        "Updating event "
        "EVENT_ID [%d] EVENT_TYPE [%d] ACTION_ID [%d] LOGGED [%d] OUTCOME [%d] "
        "DRONE_ID [%d] RESOURCE_ID [%d] INSTALLED [%d] LOCKED [%d] NEW_QUANTITY [%d] "
        "NEW_CREDITS [%d] NEW_LOCATION [%d] NEW_CARGO [%d] TIMESTAMP [%ld]",
        event->event_id,
        event->event_type,
        event->action_id,
        event->logged,
        event->outcome,
        event->drone_id,
        event->resource_id,
        event->installed,
        event->locked,
        event->new_quantity,
        event->new_credits,
        event->new_location,
        event->new_transmission,
        event->new_cargo,
        event->timestamp);


    // update outcome at DB
    result = db_update_event(event);

    return result;
}

/** ****************************************************************************

  @brief      When something goes wrong, we abort action associated with this event

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_abort_action(Event_t *event)
{
    ErrorCode_t result = ENGINE_INTERNAL_ERROR;

    if(event)
        result = ee_db_abort_action(event->action_id);
    current_action_valid = 0;   // it is aborted now

    return result;
}

/** ****************************************************************************

  @brief          Gets the position of a given object ID (body and distance from Earth)

  @param[in]      object_id     Current object ID (drone object ID)
  @param[out]     position      Output buffer where we store current position
  @param[out]     distance      Output buffer where we store current distance from Earth

  @return         Error code

*******************************************************************************/
ErrorCode_t event_get_drone_position(int object_id, char* position, double* distance)
{
    ErrorCode_t result = ENGINE_INTERNAL_ERROR;

    if(position && distance) {
        ObjectOrbitInfo_t drone;
        ObjectOrbitInfo_t object;
        ObjectOrbitInfo_t sun;
        ObjectOrbitInfo_t earth;
        CartesianInfo_t final_position;
        int objects_num = 0;
        Bool_t done = ENGINE_FALSE;

        result = ENGINE_OK; // default OK

        // Obtain the Sun info from DB
        memset(&sun, 0, sizeof(ObjectOrbitInfo_t));

        if(db_get_object_info_by_name_x("Sun", &sun) != ENGINE_OK) {
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to obtain SUN position");
            result = ENGINE_DB_QUERY_ERROR;
        }

        if(result == ENGINE_OK) {
            ee_engine_trace(TRACE_LEVEL_DEBUG,  "[%s] has OBJ_ID=[%d]", sun.object_name, sun.object_id);

            // Obtain the drone position from this object ID
            memset(&object, 0, sizeof(ObjectOrbitInfo_t));
            memset(&final_position, 0, sizeof(CartesianInfo_t));
            object.object_id = object_id;

            do {
                // Get current object info
                if(db_get_orbit_info_x(&object) != ENGINE_OK) {
                    ee_engine_trace(TRACE_LEVEL_ALWAYS,
                        "ERROR: Unable to obtain position for drone with OBJ_ID=[%d]",
                        object.object_id);

                    result = ENGINE_DB_QUERY_ERROR;
                    done = ENGINE_TRUE;
                } else {
                    // Save drone position at first search (safe = same size)
                    if(objects_num++ == 0) {
                        memcpy(&drone, &object, sizeof(ObjectOrbitInfo_t));
                        sprintf(position, "%s", drone.object_name);
                    }

                    ee_engine_trace(TRACE_LEVEL_DEBUG,
                        "OBJ_ID=[%d] CENTRAL_BODY_OBJ_ID=[%d] SUN_OBJECT_ID=[%d]",
                        object.object_id, object.central_body_object_id, sun.object_id);

                    // Use current object info to calculate position
                    CartesianInfo_t position;
                    orbits_get_object_position(&object, &position);

                    // Add coordinates to current values
                    CartesianInfo_t current_total_position;
                    current_total_position.x = final_position.x;
                    current_total_position.y = final_position.y;
                    current_total_position.z = final_position.z;

                    orbits_add_coordinates(&position, &current_total_position, &final_position);

                    // Check if our central body is the sun to stop
                    if((object.central_body_object_id == sun.object_id) || (object.object_id == sun.object_id)) {
                        done = ENGINE_TRUE;
                    } else {
                        // update object ID to do next search
                        object.object_id = object.central_body_object_id;
                    }
                }

            } while(done == ENGINE_FALSE);

        }

        if(result == ENGINE_OK) {
            ee_engine_trace(TRACE_LEVEL_DEBUG,  "OBJ_ID=[%d] is at [%s]", drone.object_id, drone.object_name);

            // Obtain Earth info
            memset(&earth, 0, sizeof(ObjectOrbitInfo_t));

            if(db_get_object_info_by_name_x("Earth", &earth) != ENGINE_OK) {
                ee_engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to obtain EARTH position");
                result = ENGINE_DB_QUERY_ERROR;
            }
        }

        if(result == ENGINE_OK) {
            ee_engine_trace(TRACE_LEVEL_DEBUG,  "OBJ_ID=[%d] is at [%s]", earth.object_id, earth.object_name);

            // Get distance between Earth <-> drone
            *distance = orbits_get_distance_from_object(&earth, &final_position);
        }


    }

    return result;
}

/** ****************************************************************************

  @brief      When outcome is OK we update the observations table accordingly

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_update_observations(Event_t *event)
{
    ErrorCode_t result = ENGINE_OK;
    Observation_t observation;

    // Get first the flags observable and reportable for current event
    // From the protocols cache when the action is at hand (#446)
    ProtocolInfo_t protocol;
    Action_t action;
    if(event_get_action(event->action_id, &action) == ENGINE_OK)
        result = db_get_protocol_flags(action.protocol_id, &protocol);
    else
        result = db_get_event_observation_info(event, &protocol);

    // The affected drone sees its own events: no row for that (#814). Query
    // 803 finds them by the event's own drone, as it finds local sightings
    // by its place, so an observation row is only the Earth database's.
    // That row a settled event was half the observations table, and a write
    // on every event.
    memset(&observation, 0, sizeof(observation));

    // Observable events are also observed by any other spacecraft in orbit around the same object.
    if(result == ENGINE_OK && protocol.observable)
    {
        result = db_insert_local_observations(event);
    }

    // Reportable events are also observed by the Earth Based Central Database.
    if(result == ENGINE_OK && protocol.reportable)
    {
        char position[MAX_OBJECT_NAME_SIZE];
        double distance;
        int objectId;

        result = db_get_event_object_id(event, &objectId);

        if(result == ENGINE_OK)
        {
           result = event_get_drone_position(objectId, position, &distance);
        }

        if(result == ENGINE_OK)
        {
            observation.event_id = event->event_id;
            observation.drone_id = CENTRAL_DATABASE_DRONE_ID;
            observation.timestamp = time(NULL) + game_time_light_delay(distance); // light time, scaled
            observation.game_t = game_time_now_seconds() + game_time_light_seconds(distance);
            result = db_insert_observation(&observation);
        }

    }

    return result;
}

/** ****************************************************************************

  @brief      Check if a given event is a market transaction
              (resources pushed/pull to/from drones that belong to different players)

  @param[in]  action Parent action (that generated this event)
  @param[in]  event  Input event
  @param[out] user   Output struct where we store the current user info (owner of event's drone)
  @param[out] peer   Output struct where we store the current peer info (owner of the peer drone)

  @return     Execution result

              ENGINE_TRUE: It is a market transaction
              ENGINE_FALSE: Not a market transaction

*******************************************************************************/
Bool_t event_is_market_transaction(Action_t* action, Event_t *event, User_t* user, User_t* peer_user)
{
    Bool_t isMarketTransaction = ENGINE_FALSE;

    // Input pointers checked by calling function

    ee_engine_trace(TRACE_LEVEL_ALWAYS,
        "Checking if event [%d] represents a market transaction between drone [%d] and drone [%d]",
        event->event_id,
        action->drone_id,
        event->drone_id);

    // A delivery on a contract (#928) moves goods but is no trade: the
    // contract's escrow pays when it is settled
    if(action->protocol_id == DELIVER_PROTOCOL_ID)
        return ENGINE_FALSE;

    // Check if the drone that generated the action is different from the affected one
    // and both belong to different companies
    if(action->drone_id != event->drone_id) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "Checking if drones [%d] and [%d] belong to different users",
            action->drone_id,
            event->drone_id);

        if((db_get_drone_user(action->drone_id, peer_user) == ENGINE_OK) &&
           (db_get_drone_user(event->drone_id, user) == ENGINE_OK) &&
           (peer_user->user_id != user->user_id)) {

            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "Event [%d] represents a market transaction between drone [%d] and drone [%d]",
                event->event_id,
                action->drone_id,
                event->drone_id);

            isMarketTransaction = ENGINE_TRUE;

            // Link users and drones
            user->current_drone_id = event->drone_id;
            peer_user->current_drone_id = action->drone_id;

            // Link also users <-> action
            user->action_id = action->action_id;
            peer_user->action_id = action->action_id;
        }
    }

    return isMarketTransaction;
}

/** ****************************************************************************

  @brief      Processes a market transaction event

  @param[in]      event                    Input event
  @param[in]      previous_resource_event  Previous resource event or 0's struct when none
  @param[in|out]  user                     Drone user's info
  @param[in|out]  user   Drone user's info (peer drone at market transaction)
  @param[in|out]  amount Transaction amount (credits) when success

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_process_market_transaction
(
  Event_t* event,
  Event_t* previous_resource_event,
  User_t* user,
  User_t* peer_user,
  int* amount
)
{
    ErrorCode_t result = ENGINE_OK;
    PreviousEventFilter_t filter = PREVIOUS_EVENT_BY_SELL_ORDER; // Assuming that the acting drone is adding to another play's cargo,
    int resource_quantity = 0;
    Bool_t selling = ENGINE_TRUE;

    if(event->new_quantity >= 0) { // Meaning that the drone is pulling from another player's the cargo.
        filter = PREVIOUS_EVENT_BY_BUY_ORDER;
        selling = ENGINE_FALSE;
    }

    ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "Processing market transaction at event [%d], "
                "drone [%d], user [%d] is %s [%d] units of resource [%d] %s drone [%d], user [%d]",
                event->event_id,
                event->drone_id,
                user->user_id,
                (selling==ENGINE_TRUE)?"selling":"buying",
                event->new_quantity,
                event->resource_id,
                (selling==ENGINE_TRUE)?"to":"from",
                user->current_drone_id,
                user->user_id);

    // Check if there is a sell or buy order associated to current target drone
    // We need to find an event that meets these conditions:
    // drone + resource + event_type (SELL or BUY) + outcome = OK
    Event_t sell_buy_event;
    result = db_get_previous_event(event, filter, &sell_buy_event);
    if(result != ENGINE_OK) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "Drone [%d] has no %s order for resource [%d]",
            event->drone_id, (selling == ENGINE_TRUE) ? "sell" : "buy", event->resource_id);
        event->outcome = OUTCOME_NO_MARKET_ORDER;
    }

    // Sell order found - get current resource quantity
    resource_quantity = previous_resource_event->new_quantity;


    if(result == ENGINE_OK) {
        // Do quantities maths to determine if this can be done quantities
        // If the event is a sell order - new_quantity indicates the min amount to keep (for this resource)
        // If the event is a buy order - new_quantity indicates the max amount to load (for this resource)
        if(selling == ENGINE_TRUE) {
            int canSellAmount = resource_quantity - sell_buy_event.new_quantity;
            if(canSellAmount < 0) canSellAmount = 0;

            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "Per event ID [%d], drone [%d] has [%d] units of resource [%d]. Per event [%d], it needs to keep [%d] and could sell [%d]",
                previous_resource_event->event_id,
                previous_resource_event->drone_id,
                previous_resource_event->new_quantity,
                previous_resource_event->resource_id,
                sell_buy_event.event_id,
                sell_buy_event.new_quantity,
                canSellAmount);

            if(canSellAmount && (canSellAmount >= abs(event->new_quantity))) {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "drone [%d] sells [%d] units of resource [%d] (operation permitted)",
                    event->drone_id,
                    abs(event->new_quantity),
                    event->resource_id);
            } else {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "WARNING: drone [%d] CAN NOT SELL [%d] units of resource [%d] "
                    "(operation forbidden, can only sell [%d])",
                    event->drone_id,
                    abs(event->new_quantity),
                    event->resource_id,
                    canSellAmount);

                event->outcome = OUTCOME_RESOURCES_LOWER_LIMIT;
                result = ENGINE_LOGIC_ERROR;
            }
        } else {
            int canBuyAmount = sell_buy_event.new_quantity - resource_quantity;
            if(canBuyAmount < 0) canBuyAmount = 0;

            ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "Per event ID [%d], drone [%d] has [%d] units of resource [%d]. Per event [%d], it needs a total of [%d] units and will buy [%d]",
                previous_resource_event->event_id,
                previous_resource_event->drone_id,
                previous_resource_event->new_quantity,
                previous_resource_event->resource_id,
                sell_buy_event.event_id,
                sell_buy_event.new_quantity,
                canBuyAmount);

            if(canBuyAmount && (canBuyAmount >= event->new_quantity)) {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "drone [%d] buys [%d] units of resource [%d] (operation permitted)",
                    event->drone_id,
                    event->new_quantity,
                    event->resource_id);
            } else {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "WARNING: drone [%d] CAN NOT BUY [%d] units of resource [%d] "
                    "(operation forbidden, can only buy [%d])",
                    event->drone_id,
                    event->new_quantity,
                    event->resource_id,
                    canBuyAmount);

                event->outcome = OUTCOME_RESOURCES_UPPER_LIMIT;
                result = ENGINE_LOGIC_ERROR;
            }
        }

        // The amount is OK - check if the buyer has credits to buy it
        if(result == ENGINE_OK) {
            // transaction price (new_quantity is negative when the market drone sells)
            int price = abs(event->new_quantity) * sell_buy_event.new_credits;
            User_t* buyer = user;

            if(selling == ENGINE_TRUE) {
                buyer = peer_user;
            }


            if(buyer->credits >= price) {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "user [%d] pays [%d] credits to buy [%d] units of resource [%d] (current credits [%d])",
                    buyer->user_id,
                    price,
                    event->new_quantity,
                    event->resource_id,
                    buyer->credits);

                // Credits gained by the market drone's owner (user): paid when
                // selling, charged when buying
                *amount = (selling == ENGINE_TRUE) ? price : -price;
            } else {
                ee_engine_trace(TRACE_LEVEL_ALWAYS,
                    "WARNING: user [%d] CAN NOT pay [%d] credits to buy [%d] units of resource [%d] (current credits [%d])",
                    buyer->user_id,
                    price,
                    event->new_quantity,
                    event->resource_id,
                    buyer->credits);

                event->outcome = OUTCOME_NO_CREDITS;
                result = ENGINE_LOGIC_ERROR;
            }
        }
    }

    return result;
}

/** ****************************************************************************

  @brief      Executes the market transaction, updating bank accounts and inserting events

  @param[in]      event  Input event
  @param[in|out]  user   Drone user's info
  @param[in|out]  user   Drone user's info (peer drone at market transaction)
  @param[in]      amount Credits gained by user (negative when user pays)

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_do_market_transaction(Event_t *event, User_t* user, User_t* peer_user, int amount, int* tax)
{
    ErrorCode_t result = ENGINE_OK;

    // Update bank accounts: amount is what user gains (negative when user
    // buys). The seller pays the sales tax out of the sale (#380): the buyer
    // pays the full price, the seller gets it less the tax, which goes to
    // the House
    User_t* seller = (amount >= 0) ? user : peer_user;
    // What each side held when the trade began: the change, not the total,
    // is written back (#756)
    int user_before = user->credits, peer_before = peer_user->credits;
    *tax = (int)sales_tax(abs(amount), db_sales_tax_permille());
    user->credits += amount;
    peer_user->credits -= amount;
    seller->credits -= *tax;
    if(*tax > 0) {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "Sales tax: user [%d] pays [%d] of [%d] credits", seller->user_id, *tax, abs(amount));
        db_record_sales_tax(seller->user_id, *tax, seller->credits);
    }

    // A startup's credits above its cap go to the bank (#672): no point
    // hoarding as a startup, since maturing turns its credits into shares
    seller->credits -= (int)db_startup_skim(seller->user_id, seller->credits, event->game_t);

    // Dividends (#467) are no longer paid out of each sale: since #603 the
    // events engine settles them once a game day from the company's profit
    // (db_shares.c)

    ee_engine_trace(TRACE_LEVEL_ALWAYS,
        "Completing market transaction at event [%d], "
        "user [%d] drone [%d] credits [%d] <=>  user [%d] drone [%d] credits [%d]",
        event->event_id,
        user->user_id,
        user->current_drone_id,
        user->credits,
        peer_user->user_id,
        peer_user->current_drone_id,
        peer_user->credits);

    // update credits at DB: each side's change, added in the row's lock, and
    // the totals read back (#756: a total written over a website change lost it)
    db_add_user_credits(user, (long long)user->credits - user_before);
    db_add_user_credits(peer_user, (long long)peer_user->credits - peer_before);

    // Insert 2 events to reflect the transactions
    Event_t newEvent;
    memset(&newEvent, 0, sizeof(newEvent));

    // Current user event
    newEvent.drone_id = user->current_drone_id;
    newEvent.resource_id = event->resource_id;
    newEvent.event_type = (amount >= 0)?INCREMENT_CREDITS_EVENT_TYPE:DECREMENT_CREDITS_EVENT_TYPE;
    newEvent.action_id = user->action_id;
    newEvent.outcome = OUTCOME_OK;
    newEvent.new_quantity = NULL_VALUE;
    newEvent.new_credits = user->credits;
    newEvent.new_location = NULL_VALUE;
    newEvent.new_transmission = NULL_VALUE;
    newEvent.new_cargo = NULL_VALUE;
    newEvent.logged = 1;
    db_insert_event_e(&newEvent);

    // Peer user event
    newEvent.drone_id = peer_user->current_drone_id;
    newEvent.event_type = (amount >= 0)?DECREMENT_CREDITS_EVENT_TYPE:INCREMENT_CREDITS_EVENT_TYPE;
    newEvent.new_credits = peer_user->credits;
    db_insert_event_e(&newEvent);

    return result;
}


/** ****************************************************************************

  @brief      Applies one event: checks its conditions and updates inventory,
              cargo and credits. Does not abort the action on failure.

  @param[in]  event  Input event (outcome set on return)

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
static ErrorCode_t event_apply(Event_t *event)
{
    ErrorCode_t result = ENGINE_OK;
    Event_t previous_resource_event;
    Event_t previous_event;
    Bool_t market_transaction = ENGINE_FALSE;
    User_t user_info; // required at market transactions
    User_t peer_user_info; // required at market transactions
    int transactionAmount = 0;  // credits involved in transaction
    int tradedUnits = 0;        // units that change hands in it

    memset(&previous_event, 0, sizeof(previous_event));
    memset(&previous_resource_event, 0, sizeof(previous_resource_event));

    // Sanity check
    if(event == NULL)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL outcome event skipped");
        return ENGINE_INTERNAL_ERROR;
    }

    ee_engine_trace(TRACE_LEVEL_ALWAYS,
        "Processing event "
        "EVENT_ID [%d] EVENT_TYPE [%d] ACTION_ID [%d] LOGGED [%d] OUTCOME [%d] "
        "DRONE_ID [%d] RESOURCE_ID [%d] INSTALLED [%d] LOCKED [%d] NEW_QUANTITY [%d] "
        "NEW_CREDITS [%d] NEW_LOCATION [%d] NEW_CARGO [%d] TIMESTAMP [%ld]",
        event->event_id,
        event->event_type,
        event->action_id,
        event->logged,
        event->outcome,
        event->drone_id,
        event->resource_id,
        event->installed,
        event->locked,
        event->new_quantity,
        event->new_credits,
        event->new_location,
        event->new_transmission,
        event->new_cargo,
        event->timestamp);

    // Check that associated action is not aborted - we do not process events for aborted actions
    Action_t action;
    result = event_get_action(event->action_id, &action);

    if((result != ENGINE_OK) && event_retry_if_transient(event))
        return result;

    if((result != ENGINE_OK) || action.aborted)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "WARNING: Event [%d] is associated with an aborted action [%d], not processed/discarded",
            event->event_id,
            event->action_id);

        // Mark this event as aborted
        event->outcome = OUTCOME_ACTION_ABORTED;

        // Mark aborted as error
        if(result == ENGINE_OK) result = ENGINE_LOGIC_ERROR;
    }

    // Check if it is a market event type
    // These events - are just marked with outcome = OK and registered at observations table
    if((result == ENGINE_OK) && ((event->event_type == SELL_EVENT_TYPE) || (event->event_type == BUY_EVENT_TYPE)))
    {
        // Under the new rules an order stands for its first game hours (#639):
        // checked here, as market events skip the conditions below (#650)
        int lock_outcome = db_order_lock_outcome(event);
        if(lock_outcome != 0)
        {
            event->outcome = lock_outcome;   // the caller records the failure
            return ENGINE_LOGIC_ERROR;
        }

        ee_engine_trace(TRACE_LEVEL_ALWAYS,
          "Market event [%d], no processing done, only observations table updated",
          event->event_id,
          event->action_id);

        event->outcome = OUTCOME_OK;
        event->logged = 1;

        // update outcome at DB
        result = db_update_event(event);

        if(result == ENGINE_OK) result = event_update_observations(event);

        return result;
    }

    // The end of a wait (#324 step 14): nothing to do but finish the action,
    // so the waiting script's perform returns
    // A share request reaching the Earth exchange (#469): posts an order, or
    // pulls or pushes shares against another company's order. A refusal is
    // recorded like any failed event, and perform returns why
    if(result == ENGINE_OK && event->event_type == SHARE_EVENT_TYPE) {
        int outcome = db_shares_settle(event);
        event->outcome = outcome;
        event->logged = 1;
        event->new_cargo = NULL_VALUE;
        event->new_credits = NULL_VALUE;
        if(outcome != OUTCOME_OK)
            return ENGINE_LOGIC_ERROR;
        return db_update_event(event);
    }

    if(result == ENGINE_OK && event->event_type == WAIT_EVENT_TYPE) {
        event->outcome = OUTCOME_OK;
        event->logged = 1;
        event->new_cargo = NULL_VALUE;
        event->new_credits = NULL_VALUE;
        return db_update_event(event);
    }

    // A tool taken for an action's time, and given back (#814): a row in
    // tool_locks, not inventory history. A tool that isn't free fails -2 as
    // it did when the lock was an inventory decrement. Neither is observed,
    // and the archiver's pass deletes them once settled
    if(result == ENGINE_OK && event->event_type == LOCK_TOOL_EVENT_TYPE) {
        int outcome = db_tool_lock(event);
        if(outcome == 0)
            return ENGINE_DB_QUERY_ERROR;
        event->outcome = outcome;
        event->logged = 1;
        event->new_cargo = NULL_VALUE;
        event->new_credits = NULL_VALUE;
        if(outcome != OUTCOME_OK)
            return ENGINE_LOGIC_ERROR;
        return db_update_event(event);
    }
    if(result == ENGINE_OK && event->event_type == UNLOCK_TOOL_EVENT_TYPE) {
        result = db_tool_unlock(event);
        if(result != ENGINE_OK)
            return result;
        event->outcome = OUTCOME_OK;
        event->logged = 1;
        event->new_cargo = NULL_VALUE;
        event->new_credits = NULL_VALUE;
        return db_update_event(event);
    }

    // A broadcast (#380): the message is in broadcasts already; the event
    // finishes the action and is observed like any other
    if(result == ENGINE_OK && event->event_type == BROADCAST_EVENT_TYPE) {
        event->outcome = OUTCOME_OK;
        event->logged = 1;
        event->new_cargo = NULL_VALUE;
        event->new_credits = NULL_VALUE;
        result = db_update_event(event);
        if(result == ENGINE_OK) result = event_update_observations(event);
        return result;
    }

    // If move event, update drone location.  No further action required.
    if(result == ENGINE_OK && event->event_type == MOVE_EVENT_TYPE) {

        // Places waiting to be written are where the drone was (#448)
        result = db_flush_places();

        // Update drone location.
        if(result == ENGINE_OK)
            result = ee_db_update_agent_object(ee_engine_get_db_connection(), event->drone_id, event->new_location);

        // Log result.
        ee_engine_trace(TRACE_LEVEL_ALWAYS,
                "Drone [%d] moves to location [%d] in event [%d].",
                event->drone_id,
                event->new_location,
                event->event_id);

        // Update event status.
        event->outcome = OUTCOME_OK;
        event->logged = 1;

        if(result == ENGINE_OK) result = db_update_event(event);

        if(result == ENGINE_OK) result = event_update_observations(event);

        return result;
    }

    // Launch: the new drone is created now (the kit was used up earlier in
    // the same action). Records the new drone's ID in new_quantity.
    if(result == ENGINE_OK && event->event_type == LAUNCH_EVENT_TYPE) {
        int new_id = 0;
        int source_id = event->new_quantity;

        // The fleet cap, checked again as the launch lands (#746: a company
        // came to 9 working drones under a cap of 8). Refused (-20), the
        // launcher gets its kit and the clone's fuel back
        int cap = db_fleet_full_e(event->drone_id);
        if(cap > 0) {
            Event_t back;
            memset(&back, 0, sizeof(back));
            back.event_type = INCREMENT_INVENTORY_EVENT_TYPE;
            back.action_id = event->action_id;
            back.logged = 1;
            back.outcome = OUTCOME_NO_OUTCOME;
            back.drone_id = event->drone_id;
            back.new_credits = NULL_VALUE;
            back.new_location = NULL_VALUE;
            back.new_transmission = NULL_VALUE;
            back.resource_id = event->resource_id;   // the kit
            back.new_quantity = 1;
            back.new_cargo = db_resource_mass_e(event->resource_id);
            result = db_insert_event_e(&back);
            if(result == ENGINE_OK) {
                back.resource_id = FUEL_RESOURCE_ID;
                back.new_quantity = LAUNCH_FUEL;
                back.new_cargo = LAUNCH_FUEL;
                result = db_insert_event_e(&back);
            }
            ee_engine_trace(TRACE_LEVEL_ALWAYS, "Launch event [%d] refused: drone [%d]'s company has its [%d] working drones; "
                "kit [%d] and the clone's fuel given back", event->event_id, event->drone_id, cap, event->resource_id);
            event->outcome = OUTCOME_FLEET_CAP;
            event->logged = 1;
            event->new_quantity = NULL_VALUE;
            event->new_cargo = NULL_VALUE;
            event->new_credits = NULL_VALUE;
            if(result == ENGINE_OK) result = db_update_event(event);
            return result;
        }

        result = db_launch_drone(event->drone_id, source_id, event->resource_id, &new_id);

        // Its tank (#324 step 13): the fuel the launcher paid for it when
        // the launch started arrives in the clone's hold, so it can pay for
        // its first thinking
        if(result == ENGINE_OK) {
            Event_t fuel;
            memset(&fuel, 0, sizeof(fuel));
            fuel.event_type = INCREMENT_INVENTORY_EVENT_TYPE;
            fuel.action_id = event->action_id;
            fuel.logged = 1;
            fuel.outcome = OUTCOME_NO_OUTCOME;
            fuel.drone_id = new_id;
            fuel.resource_id = FUEL_RESOURCE_ID;
            fuel.new_quantity = LAUNCH_FUEL;     // the change: the events engine works out the total
            fuel.new_credits = NULL_VALUE;
            fuel.new_location = NULL_VALUE;
            fuel.new_transmission = NULL_VALUE;
            fuel.new_cargo = LAUNCH_FUEL;        // fuel has mass 1
            result = db_insert_event_e(&fuel);
        }

        event->outcome = (result == ENGINE_OK) ? OUTCOME_OK : OUTCOME_ACTION_ABORTED;
        event->logged = 1;
        event->new_quantity = (result == ENGINE_OK) ? new_id : NULL_VALUE;
        event->new_cargo = NULL_VALUE;
        event->new_credits = NULL_VALUE;

        if(result == ENGINE_OK) result = db_update_event(event);

        if(result == ENGINE_OK) result = event_update_observations(event);

        return result;
    }

    // An offer to or order from a House station (#324 step 10): recorded for
    // the hour's batch, which db_station_clear_due clears
    if(result == ENGINE_OK && (event->event_type == STATION_OFFER_EVENT_TYPE ||
                               event->event_type == STATION_ORDER_EVENT_TYPE)) {
        result = db_station_record(event);
        if(result != ENGINE_OK)
            return result;   // the caller records the outcome and aborts the action

        event->outcome = OUTCOME_OK;
        event->logged = 1;
        event->new_cargo = NULL_VALUE;
        event->new_credits = NULL_VALUE;

        result = db_update_event(event);
        if(result == ENGINE_OK) result = event_update_observations(event);
        return result;
    }

    // A drone plunders a wreck (#324 step 12): its share of the wreck's
    // goods and parts arrives as actions of protocol 35
    if(result == ENGINE_OK && event->event_type == PLUNDER_EVENT_TYPE) {
        result = db_plunder(event);
        if(result != ENGINE_OK)
            return result;   // the caller records the outcome and aborts the action

        event->outcome = OUTCOME_OK;
        event->logged = 1;
        event->new_cargo = NULL_VALUE;
        event->new_credits = NULL_VALUE;

        result = db_update_event(event);
        if(result == ENGINE_OK) result = event_update_observations(event);
        return result;
    }

    // A drone salvages a wreck (#929): a module or scrap arrives as an
    // action of protocol 35, as plunder does
    if(result == ENGINE_OK && event->event_type == SALVAGE_EVENT_TYPE) {
        result = db_salvage(event);
        if(result != ENGINE_OK)
            return result;   // the caller records the outcome and aborts the action

        event->outcome = OUTCOME_OK;
        event->logged = 1;
        event->new_cargo = NULL_VALUE;
        event->new_credits = NULL_VALUE;

        result = db_update_event(event);
        if(result == ENGINE_OK) result = event_update_observations(event);
        return result;
    }

    // The previous event of this resource, kind and state. (The drone's
    // previous event was looked up too, for a log line only: the cargo comes
    // from the holdings. It was the events engine's top cost, #446.)
    if(result == ENGINE_OK)
    {
        db_get_previous_event(event, PREVIOUS_EVENT_BY_RESOURCE_INFO, &previous_resource_event);
    }

    if(result == ENGINE_OK)
    {
        market_transaction = event_is_market_transaction(&action, event, &user_info, &peer_user_info);
    }

    // Any two companies may trade (#367 step 1): companies working together
    // is a legitimate gain, so trades between one player's companies, once
    // blocked with OUTCOME_SAME_PLAYER (#324 step 4), go through

    if((result == ENGINE_OK) && (market_transaction == ENGINE_TRUE))
    {
        // new_quantity is still the change here (it becomes the total below)
        tradedUnits = abs(event->new_quantity);
        result = event_process_market_transaction(event,
                                                  &previous_resource_event,
                                                  &user_info,
                                                  &peer_user_info,
                                                  &transactionAmount);
    }

    // Do the maths to calculate the new total cargo
    if(result == ENGINE_OK)
    {
        // Update new_cargo
        result = event_update_new_cargo_and_quantity(event,
                                                     &previous_event,
                                                     &previous_resource_event);
    }

    if(result == ENGINE_OK)
    {
        // Update new_credit
        result = event_update_new_credit(event);
    }

    if(result == ENGINE_OK)
    {
        // Validate event conditions
        result = event_validate_event_conditions(event,
                                                 &previous_event,
                                                 &previous_resource_event);
    }

    if((result == ENGINE_OK) && (event->outcome == OUTCOME_OK))
    {
        // It went through. (The events before it of its kind were deleted
        // here once they were 30 real days old; now old events move to
        // events_archive, db_archive_due, #694)
    }
    else
    {
        // Failed: the caller records the outcome and aborts the action
        result = ENGINE_LOGIC_ERROR;
    }

    if(result == ENGINE_OK)
    {
        // Do credits transaction and generate events
        if(market_transaction == ENGINE_TRUE) {
            int tax = 0;
            event_do_market_transaction(event, &user_info, &peer_user_info, transactionAmount, &tax);

            // Record the trade for the market volume queries (#249). The
            // amount is what user gains: positive when user's drone sold.
            int location = -1;
            db_get_event_object_id(event, &location);
            Bool_t user_sold = (transactionAmount >= 0) ? ENGINE_TRUE : ENGINE_FALSE;
            db_insert_trade(event->resource_id, tradedUnits, abs(transactionAmount),
                user_sold ? user_info.current_drone_id : peer_user_info.current_drone_id,
                user_sold ? peer_user_info.current_drone_id : user_info.current_drone_id,
                location, tax);
        }
        // Update observation entries
        result = event_update_observations(event);
    }

    return result;
}

/** ****************************************************************************

  @brief      Records a failed event's outcome and aborts its action

  @param[in]  event    Failed event
  @param[in]  outcome  Outcome to record

  @return     void

*******************************************************************************/
static void event_record_failure(Event_t *event, int outcome)
{
    if(outcome == OUTCOME_NO_OUTCOME || outcome == OUTCOME_OK)
        outcome = OUTCOME_ACTION_ABORTED;

    event->outcome = outcome;
    db_update_event(event);
    event_abort_action(event);

    // Keep why the action failed: perform leaves it for the drone's script
    // (not when it was aborted for another reason, such as by the player)
    if(outcome != OUTCOME_ACTION_ABORTED)
        db_set_action_outcome(event->action_id, outcome);

    // What a failed mining action took from its deposit goes back (#324 step 11)
    db_refund_deposits(event->action_id);

    // A drone that can't pay for its power has run dry: it becomes a wreck
    // (#324 step 8). Only a lack of fuel does that: a power burn that fails
    // for another reason just leaves the bill unpaid this time.
    Action_t action;
    if(outcome == OUTCOME_NO_RESOURCES &&
       event_get_action(event->action_id, &action) == ENGINE_OK && action.protocol_id == POWER_PROTOCOL_ID)
        db_wreck_drone(event->drone_id, "fuel");
}

/** ****************************************************************************

  @brief      Checks whether an event is the first half of a transfer
              (push or pull) and gets the second half

  A transfer moves goods out of one drone (a decrement event) and into another
  (an increment event a moment later). Both halves must succeed or neither.

  @param[in]   event    Input event
  @param[out]  partner  The transfer's increment event

  @return     ENGINE_TRUE when event is a transfer's decrement half

*******************************************************************************/
static Bool_t event_get_transfer_partner(Event_t *event, Event_t *partner)
{
    Action_t action;

    if(event->event_type != DECREMENT_INVENTORY_EVENT_TYPE)
        return ENGINE_FALSE;

    if(event_get_action(event->action_id, &action) != ENGINE_OK || action.aborted)
        return ENGINE_FALSE;

    // (a delivery on a contract is a transfer too, #928)
    if(action.protocol_id != PUSH_PROTOCOL_ID && action.protocol_id != PULL_PROTOCOL_ID &&
       action.protocol_id != DELIVER_PROTOCOL_ID)
        return ENGINE_FALSE;

    if(db_get_pending_action_event(event->action_id, INCREMENT_INVENTORY_EVENT_TYPE,
                                   event->event_id, partner) != ENGINE_OK)
        return ENGINE_FALSE;

    // The two halves are two drones' (#380 round 38): in a pull of fuel,
    // the buyer's own fuel cost is a fuel decrement too, and paired with
    // the buyer's increment it delivered the fuel while the seller's half
    // failed on its own: an over-pull got its fuel unpaid
    return (partner->resource_id == event->resource_id && partner->drone_id != event->drone_id)
        ? ENGINE_TRUE : ENGINE_FALSE;
}

/** ****************************************************************************

  @brief      Applies both halves of a transfer, or neither

  The drones must be at the same place. The halves run together inside a
  savepoint: if either fails, everything both did (inventory, cargo, credits)
  is rolled back and the action aborted.

  @param[in]  event    The transfer's decrement event
  @param[in]  partner  The transfer's increment event

  @return     Execution result

*******************************************************************************/
static ErrorCode_t event_process_transfer(Event_t *event, Event_t *partner)
{
    Event_t first = *event;     // untouched copies, to record a failure
    Event_t second = *partner;
    ErrorCode_t result;

    ee_engine_trace(TRACE_LEVEL_ALWAYS,
        "Transfer for action [%d]: events [%d] and [%d] are applied together",
        event->action_id, event->event_id, partner->event_id);

    // Both drones must be at the same place
    int from_object = -1, to_object = -1;
    if((db_get_event_object_id(event, &from_object) != ENGINE_OK) ||
       (db_get_event_object_id(partner, &to_object) != ENGINE_OK) ||
       (from_object != to_object) ||
       // A drone on a trip keeps the place it left until it arrives (#380)
       db_drone_in_transit(event->drone_id, event->game_t) ||
       db_drone_in_transit(partner->drone_id, partner->game_t))
    {
        if(event_retry_if_transient(event))
            return ENGINE_DB_QUERY_ERROR;

        ee_engine_trace(TRACE_LEVEL_ALWAYS,
            "Transfer for action [%d] refused: drone [%d] is at [%d], drone [%d] is at [%d]",
            event->action_id, event->drone_id, from_object, partner->drone_id, to_object);

        event_record_failure(&first, OUTCOME_NO_LOCAL);
        event_record_failure(&second, OUTCOME_ACTION_ABORTED);
        db_refund_fuel(event->action_id);   // a failed transfer costs no fuel (#380 round 38)
        return ENGINE_LOGIC_ERROR;
    }

    // A startup trades only with the House, and buys at most its share of
    // the House's stock here in a game day (#637)
    int startup_outcome = 0, startup_buyer = 0;
    if(db_startup_transfer_outcome(event, partner, from_object, &startup_outcome, &startup_buyer) == ENGINE_OK &&
       startup_outcome != 0)
    {
        event_record_failure(&first, startup_outcome);
        event_record_failure(&second, OUTCOME_ACTION_ABORTED);
        db_refund_fuel(event->action_id);   // a refused transfer costs no fuel
        return ENGINE_LOGIC_ERROR;
    }

    BatchWritesMark_t mark = db_batch_writes_mark();
    result = db_savepoint("SAVEPOINT transfer");
    if(result != ENGINE_OK)
        return result;

    result = event_apply(event);
    if(result == ENGINE_OK)
        result = event_apply(partner);

    if(result == ENGINE_OK) {
        db_savepoint("RELEASE SAVEPOINT transfer");
        // What was bought: the untouched copy, as the applied event now holds
        // the buyer's whole stock (#663)
        db_startup_record_buy(startup_buyer, &second, from_object);
        // A delivery on a contract counts against it (#928)
        db_contract_delivered(event->action_id, first.event_type == INCREMENT_INVENTORY_EVENT_TYPE ? &first : &second);
        return ENGINE_OK;
    }

    if(event_retry_if_transient(event))
        return result;   // the whole batch is rolled back

    ee_engine_trace(TRACE_LEVEL_ALWAYS,
        "Transfer for action [%d] failed (outcomes [%d] and [%d]): undoing both halves",
        event->action_id, event->outcome, partner->outcome);

    db_savepoint("ROLLBACK TO SAVEPOINT transfer");
    db_batch_writes_undo(mark);   // what both halves added to the batch's writes

    if(event->outcome != OUTCOME_OK) {
        event_record_failure(&first, event->outcome);
        event_record_failure(&second, OUTCOME_ACTION_ABORTED);
    } else {
        event_record_failure(&first, OUTCOME_ACTION_ABORTED);
        event_record_failure(&second, partner->outcome);
    }
    db_refund_fuel(event->action_id);   // a failed transfer costs no fuel (#380 round 38)

    return ENGINE_LOGIC_ERROR;
}

/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Processes outcome event info

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_process_outcome(Event_t *event)
{
    ErrorCode_t result;
    Event_t partner;

    if(event == NULL)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL outcome event skipped");
        return ENGINE_INTERNAL_ERROR;
    }

    // The batch was read before earlier events ran: skip events already
    // settled since (e.g. the second half of a transfer). Kept in memory
    // (#446): only this engine settles events, in this transaction
    Bool_t settled = ENGINE_FALSE;
    if((db_event_settled_in_batch(event->event_id, &settled) == ENGINE_OK) && settled)
    {
        ee_engine_trace(TRACE_LEVEL_ALWAYS, "Event [%d] already processed", event->event_id);
        return ENGINE_OK;
    }

    // After a passing database error the batch is rolled back: the rest of
    // it waits for the retry
    if(batch_retry)
        return ENGINE_DB_QUERY_ERROR;

    current_action_valid = 0;   // read it afresh for each event

    memset(&partner, 0, sizeof(partner));
    if(event_get_transfer_partner(event, &partner) == ENGINE_TRUE)
        return event_process_transfer(event, &partner);

    result = event_apply(event);
    if(result != ENGINE_OK && !event_retry_if_transient(event))
        event_record_failure(event, event->outcome);

    return result;
}

/** ****************************************************************************

  @brief      Trace a given event info

  @param[in]  event  Input event

  @return     void

*******************************************************************************/
void event_trace(Event_t* event)
{
  ee_engine_trace(TRACE_LEVEL_ALWAYS,
          "Event info: "
          "EVENT_ID [%d] EVENT_TYPE [%d] ACTION_ID [%d] LOGGED [%d] OUTCOME [%d] "
          "DRONE_ID [%d] RESOURCE_ID [%d] INSTALLED [%d] LOCKED [%d] NEW_QUANTITY [%d] "
          "NEW_CREDITS [%d] NEW_LOCATION [%d] NEW_CARGO [%d] TIMESTAMP [%ld]",
          event->event_id,
          event->event_type,
          event->action_id,
          event->logged,
          event->outcome,
          event->drone_id,
          event->resource_id,
          event->installed,
          event->locked,
          event->new_quantity,
          event->new_credits,
          event->new_location,
          event->new_transmission,
          event->new_cargo,
          event->timestamp);
}
