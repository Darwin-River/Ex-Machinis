/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Events management module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>
#include <limits.h>

#include "event.h"
#include "engine_e.h"
#include "db.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief      The EE will search for the last processed event with the same drone, resource, installed, and locked values
              (if such an event exists) and add that quantity to the new_quantities field of the current event.

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_update_new_quantity(Event_t *event) 
{
    ErrorCode_t result = ENGINE_OK;
    Event_t previous_event;

    // The EE will search for the last processed event with the same drone, 
    // resource, installed, and locked values (if such an event exists) and 
    // add that quantity to the new_quantities field of the current event.

    if(event == NULL)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL event received, unable to update new quantity");
        result = ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        // Get last event with same drone, resource, installed and locked values
        // We pass an enum to indicate this condition
        result = db_get_previous_event(event, PREVIOUS_EVENT_BY_RESOURCE_INFO, &previous_event);
    }

    if(result == ENGINE_OK)
    {
        // We obtained and event - do the maths to update new_quantity
        event->new_quantity += previous_event.new_quantity;
    }

    if(result == ENGINE_NOT_FOUND)
    {
        // not found is ok to continue logic
        result = ENGINE_OK;
    }

    return result;
}

/** ****************************************************************************

  @brief      The EE will search for the last processed event with the same drone 
              and add that quantity to the new_cargo field of the current event.

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_update_new_cargo(Event_t *event) 
{
    ErrorCode_t result = ENGINE_OK;
    Event_t previous_event;

    // new_cargo.  The EE will search for the last processed event with the same drone and add that quantity to the new_cargo field of the current event.

    if(event == NULL)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL event received, unable to update new cargo");
        result = ENGINE_INTERNAL_ERROR;
    }

    if(result == ENGINE_OK)
    {
        // search for the last processed event with the same drone 
        result = db_get_previous_event(event, PREVIOUS_EVENT_BY_DRONE, &previous_event);
    }

    if(result == ENGINE_OK)
    {
        // We obtained and event - do the maths to update new_quantity
        event->new_cargo += previous_event.new_cargo;
    }

    if(result == ENGINE_NOT_FOUND)
    {
        // not found is ok to continue logic
        result = ENGINE_OK;
    }

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

  @param[in]  event  Input event

  @return     Boolean (TRUE when condition is met, FALSE otherwise)

*******************************************************************************/
Bool_t event_no_resources_condition(Event_t *event) 
{
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

    if(db_get_max_drone_cargo(event->drone_id, &capacity) == ENGINE_OK) {
        // Compare current cargo vs. max capacity
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Current cargo [%d], max capacity [%d]", 
            event->new_cargo, capacity);

        if(capacity > event->new_cargo) {
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

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_validate_event_conditions(Event_t *event) 
{
    ErrorCode_t result = ENGINE_LOGIC_ERROR;

    // Update outcome value using Outcome_t enum depending on validations

    // Check event conditions to ensure that it can take place
    if(event_no_local_condition(event) == ENGINE_TRUE) {
        //a.  The local field is set to true but the affected drone is not local.
        event->outcome = OUTCOME_NO_LOCAL;
    } else if(event_no_resources_condition(event) == ENGINE_TRUE) {
        //b.  The affected ship contains insufficient resources of the indicated state 
        // (identical resource, locked, and installed values) to accommodate a depletion event. 
        // This happens when the newly computed new_quantity field is less than zero.
        event->outcome = OUTCOME_NO_RESOURCES;
    } else if(event_no_cargo_condition(event) == ENGINE_TRUE) {
        //c.  There affected ship contains insufficient cargo space to accommodate the accumulation event.
        // This happens when the recently computed new_cargo value is greater than the cargo capacity of the drone (found in hull table)
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

  @brief      When outcome is OK we update the observations table accordingly

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_update_observations(Event_t *event) 
{
  ErrorCode_t result = ENGINE_OK;

  // Then, the EE creates a new observations table entry for the acting and affected drones and sets the observation time to that of the event plus the distance in light minutes that the drone is away from the event.  For drones that are local to the event (meaning they are orbiting the same static object), the observation time is identical to the event time.
  // 7.  If the event is reportable (i.e. the reportable field is true), then the EE creates an observations table entry for the Central Database (Drone ID = 0) and sets the observation time to the event time plus the distance of the drone from the Earth in light minutes.
  // 8.  If the event is observable and the event does was successfully processed  (i.e. the observable field is true and status = 1), the EE also creates observation table entries for each local drone and sets the observation time to the event time.

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

    // Sanity check 
    if(event == NULL)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL outcome event skipped");
        return ENGINE_INTERNAL_ERROR;
    }

    // Check that associate action is not aborted - we do not process events for aborted actions
    Action_t action;
    memset(&action, 0, sizeof(action));
    action.action_id = event->action_id;

    result = db_get_action(&action);

    if((result != ENGINE_OK) || action.aborted) 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "WARNING: Outcome event [%d] is associated with an aborted action [%d], ignored",
            event->event_id,
            event->action_id);

        // Mark aborted as error
        if(result == ENGINE_OK) result = ENGINE_LOGIC_ERROR;
    }

    // Do the maths to calculate the new total cargo
    if(result == ENGINE_OK)
    {
        // Update new_quantities
        result = event_update_new_quantity(event);
    }

    if(result == ENGINE_OK)
    {
        // Update new_cargo
        result = event_update_new_cargo(event);
    }

    if(result == ENGINE_OK)
    {
        // Update new_credit
        result = event_update_new_credit(event);
    }

    if(result == ENGINE_OK)
    {
        // Validate event conditions
        result = event_validate_event_conditions(event);
    }

    if(result == ENGINE_OK)
    {
        // Delete all previous events
        result = event_delete_previous_events(event);
    }
    else 
    {
        // Abort the associated action
        event_abort_action(event);
    }

    if(result == ENGINE_OK)
    {
        // Update observation entries
        result = event_update_observations(event);
    }

    return result;
}
