/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Events management module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>
#include <limits.h>
#include <time.h>

#include "event.h"
#include "engine_e.h"
#include "db.h"
#include "orbits.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

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
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL event received, unable to update new cargo");
        result = ENGINE_INTERNAL_ERROR;
    }

    if(previous_event == NULL)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL previous_event received, unable to update new cargo");
        result = ENGINE_INTERNAL_ERROR;
    }

    if(previous_resource_event == NULL)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL previous_resource_event received, unable to update new cargo");
        result = ENGINE_INTERNAL_ERROR;
    }

    if(previous_event->new_cargo == NULL_VALUE) previous_event->new_cargo = 0; // first cargo
    if(previous_resource_event->new_quantity == NULL_VALUE) previous_resource_event->new_quantity = 0; // first time

    if((result == ENGINE_OK) && (event->new_quantity != NULL_VALUE))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
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
                engine_trace(TRACE_LEVEL_ALWAYS, 
                  "Adding resource [%s], quantity [%d], mass [%d] to new_cargo, total now [%d]",  
                  resource->name, resource->quantity, resource->mass, 
                  total_cargo);
            }
        }

        // add current event new_cargo that already contains (mass * quantity)
        engine_trace(TRACE_LEVEL_ALWAYS, 
          "Adding current event cargo [%d] to [%d]",  
          event->new_cargo, 
          total_cargo);

        event->new_cargo += total_cargo;
    }

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
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL event received, unable to update new credits");
        result = ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        // search for the last processed event with the same drone 
        result = db_get_previous_event(event, PREVIOUS_EVENT_BY_OWNER, &previous_event);
    }

    if(result == ENGINE_OK)
    {
        if(event->new_credits >= 0 &&  event->new_credits != NULL_VALUE) {
          // We obtained and event - do the maths to update new_quantity
          event->new_credits += previous_event.new_credits;
        }
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
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Current resources quantity [%d] is not enough for a depletion of [%d]", 
            previous_resource_event->new_quantity, previous_event->new_quantity);

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

    if(db_get_max_drone_cargo(event->drone_id, &capacity) == ENGINE_OK) {
        // Compare current cargo vs. max capacity
        engine_trace(TRACE_LEVEL_ALWAYS, 
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

    engine_trace(TRACE_LEVEL_ALWAYS, 
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

  @brief      Once event outcome is OK, we can delete previous events from DB

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_delete_previous_events(Event_t *event) 
{
  ErrorCode_t result = ENGINE_INTERNAL_ERROR;

  if(event)
    result = db_delete_previous_events(event);

  // Purge old actions too
  db_purge_old_actions();

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
        result = db_abort_action(event->action_id);

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
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to obtain SUN position");
            result = ENGINE_DB_QUERY_ERROR;
        }

        if(result == ENGINE_OK) {
            engine_trace(TRACE_LEVEL_ALWAYS,  "[%s] has OBJ_ID=[%d]", sun.object_name, sun.object_id);

            // Obtain the drone position from this object ID
            memset(&object, 0, sizeof(ObjectOrbitInfo_t));
            memset(&final_position, 0, sizeof(CartesianInfo_t));
            object.object_id = object_id;

            do {
                // Get current object info
                if(db_get_orbit_info_x(&object) != ENGINE_OK) {
                    engine_trace(TRACE_LEVEL_ALWAYS, 
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

                    engine_trace(TRACE_LEVEL_ALWAYS, 
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
            engine_trace(TRACE_LEVEL_ALWAYS,  "OBJ_ID=[%d] is at [%s]", drone.object_id, drone.object_name);

            // Obtain Earth info
            memset(&earth, 0, sizeof(ObjectOrbitInfo_t));

            if(db_get_object_info_by_name_x("Earth", &earth) != ENGINE_OK) {
                engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to obtain EARTH position");
                result = ENGINE_DB_QUERY_ERROR;
            }
        } 

        if(result == ENGINE_OK) {
            engine_trace(TRACE_LEVEL_ALWAYS,  "OBJ_ID=[%d] is at [%s]", earth.object_id, earth.object_name);

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
    ProtocolInfo_t protocol;
    result = db_get_event_observation_info(event, &protocol);

    if(result == ENGINE_OK)
    {
        // All events are observed by the acting and affected drones.
        memset(&observation, 0, sizeof(observation));
        observation.event_id = event->event_id;
        observation.drone_id = event->drone_id;
        observation.timestamp = time(NULL); // now for affected drone

        result = db_insert_observation(&observation);
    }        

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
            observation.timestamp = time(NULL) + (time_t)(distance/LIGHT_SPEED_KM_PER_SECOND);
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

    engine_trace(TRACE_LEVEL_ALWAYS, 
        "Checking if event [%d] represents a market transaction between drone [%d] and drone [%d]",
        event->event_id,
        action->drone_id,
        event->drone_id);

    // Check if the drone that generated the action is different from the affected one
    // and both belong to different users
    if(action->drone_id != event->drone_id) {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Checking if drones [%d] and [%d] belong to different users",
            action->drone_id,
            event->drone_id);

        if((db_get_drone_user(action->drone_id, peer_user) == ENGINE_OK) && 
           (db_get_drone_user(event->drone_id, user) == ENGINE_OK) && 
           (peer_user->user_id != user->user_id)) {

            engine_trace(TRACE_LEVEL_ALWAYS, 
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
    PreviousEventFilter_t filter = PREVIOUS_EVENT_BY_SELL_ORDER;
    int resource_quantity = 0;
    Bool_t selling = ENGINE_TRUE;

    if(event->new_quantity >= 0) {
        filter = PREVIOUS_EVENT_BY_BUY_ORDER;
        selling = ENGINE_FALSE;
    }

    engine_trace(TRACE_LEVEL_ALWAYS, 
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

    // Sell order found - get current resource quantity
    resource_quantity = previous_resource_event->new_quantity;

    if(result == ENGINE_OK) { 
        // Do quantities maths to determine if this can be done quantities
        // If the event is a sell order - new_quantity indicates the min amount to keep (for this resource)
        // If the event is a buy order - new_quantity indicates the max amount to load (for this resource)
        if(selling == ENGINE_TRUE) {
            int canSellAmount = resource_quantity + event->new_quantity; // + new_quantity because is negative
            if(canSellAmount < 0) canSellAmount = 0;

            engine_trace(TRACE_LEVEL_ALWAYS, 
                "drone [%d], has [%d] units of resource [%d], needs to keep [%d], could sell [%d]",
                event->drone_id,
                resource_quantity,
                event->resource_id,
                sell_buy_event.new_quantity,
                canSellAmount);

            if(canSellAmount && (canSellAmount >= abs(event->new_quantity))) {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "drone [%d] sells [%d] units of resource [%d] (operation permitted)",
                    event->drone_id,
                    abs(event->new_quantity),
                    event->resource_id);
            } else {
                engine_trace(TRACE_LEVEL_ALWAYS, 
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

            engine_trace(TRACE_LEVEL_ALWAYS, 
                "drone [%d], has [%d] units of resource [%d], max capacity [%d], can buy [%d]",
                event->drone_id,
                resource_quantity,
                event->resource_id,
                sell_buy_event.new_quantity,
                canBuyAmount);

            if(canBuyAmount && (canBuyAmount >= event->new_quantity)) {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "drone [%d] buys [%d] units of resource [%d] (operation permitted)",
                    event->drone_id,
                    event->new_quantity,
                    event->resource_id);
            } else {
                engine_trace(TRACE_LEVEL_ALWAYS, 
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
            int price = event->new_quantity * sell_buy_event.new_credits; // transaction price
            User_t* buyer = user;

            if(selling == ENGINE_TRUE) {
                buyer = peer_user;
            }


            if(buyer->credits >= price) {
                engine_trace(TRACE_LEVEL_ALWAYS, 
                    "user [%d] pays [%d] credits to buy [%d] units of resource [%d] (current credits [%d])",
                    buyer->user_id,
                    price,
                    event->new_quantity,
                    event->resource_id,
                    buyer->credits);

                // update output amount 
                *amount = price;
            } else {
                engine_trace(TRACE_LEVEL_ALWAYS, 
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
  @param[in|out]  amount Transaction amount (credits) when success

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_do_market_transaction(Event_t *event, User_t* user, User_t* peer_user, int amount) 
{
    ErrorCode_t result = ENGINE_OK;
    
    // Update bank accounts
    if(event->new_quantity < 0) {
        // user sells -> peer_user
        user->credits += amount;
        peer_user->credits -= amount;
    } else {
        // peer_user sells -> user
        user->credits -= amount;
        peer_user->credits += amount;
    }

    engine_trace(TRACE_LEVEL_ALWAYS, 
        "Completing market transaction at event [%d], "
        "user [%d] drone [%d] credits [%d] <=>  user [%d] drone [%d] credits [%d]",
        event->event_id,
        user->user_id,
        user->current_drone_id,
        user->credits,
        peer_user->user_id,
        peer_user->current_drone_id,
        peer_user->credits);

    // update credits at DB
    db_update_user_credits(user);
    db_update_user_credits(peer_user);

    // Insert 2 events to reflect the transactions
    Event_t newEvent;
    memset(&newEvent, 0, sizeof(newEvent));

    // Current user event
    newEvent.drone_id = user->current_drone_id;
    newEvent.resource_id = event->resource_id;
    newEvent.event_type = (event->new_quantity < 0)?INCREMENT_CREDITS_EVENT_TYPE:DECREMENT_CREDITS_EVENT_TYPE;
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
    newEvent.event_type = (event->new_quantity < 0)?DECREMENT_CREDITS_EVENT_TYPE:INCREMENT_CREDITS_EVENT_TYPE;
    newEvent.new_credits = peer_user->credits;
    db_insert_event_e(&newEvent); 

    return result;
}


/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Processes outcome event info

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_process_outcome(Event_t *event) 
{
    ErrorCode_t result = ENGINE_OK;
    Event_t previous_resource_event;
    Event_t previous_event;
    Bool_t market_transaction = ENGINE_FALSE;
    User_t user_info; // required at market transactions
    User_t peer_user_info; // required at market transactions
    int transactionAmount = 0;  // credits involved in transaction

    memset(&previous_event, 0, sizeof(previous_event));
    memset(&previous_resource_event, 0, sizeof(previous_resource_event));

    // Sanity check 
    if(event == NULL)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL outcome event skipped");
        return ENGINE_INTERNAL_ERROR;
    }

    engine_trace(TRACE_LEVEL_ALWAYS, 
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
    memset(&action, 0, sizeof(action));
    action.action_id = event->action_id;

    result = db_get_action(&action);

    if((result != ENGINE_OK) || action.aborted) 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
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
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Market event [%d], no processing done, only observations table updated",
            event->event_id,
            event->action_id);

        event->outcome = OUTCOME_OK;
        event->logged = 1;

        // update outcome at DB
        result = db_update_event(event);

        if(result == ENGINE_OK)
            result = event_update_observations(event);

        return result;
    }

    // Get previous events info
    // We get (if exist, the previous valid event and the previous by resource event)
    if(result == ENGINE_OK)
    {
        db_get_previous_event(event, PREVIOUS_EVENT_BY_DRONE, &previous_event); 
        db_get_previous_event(event, PREVIOUS_EVENT_BY_RESOURCE_INFO, &previous_resource_event); 
    }

    if(result == ENGINE_OK)
    {
        market_transaction = event_is_market_transaction(&action, event, &user_info, &peer_user_info);
    }

    if((result == ENGINE_OK) && (market_transaction == ENGINE_TRUE))
    {
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
        // Delete all previous events when outcome was OK
        result = event_delete_previous_events(event);
    }
    else 
    {
        // Abort the associated action and set result != OK to skip rest of the logic
        event_abort_action(event);
        result = ENGINE_LOGIC_ERROR;
    }

    if(result == ENGINE_OK)
    {
        // Do credits transaction and generate events
        if(market_transaction == ENGINE_TRUE) {
            event_do_market_transaction(event, &user_info, &peer_user_info, transactionAmount);
        }
        // Update observation entries
        result = event_update_observations(event);
    }

    return result;
}

/** ****************************************************************************

  @brief      Trace a given event info

  @param[in]  event  Input event

  @return     void

*******************************************************************************/
void event_trace(Event_t* event) 
{
  engine_trace(TRACE_LEVEL_ALWAYS, 
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