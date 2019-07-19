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
ErrorCode_t event_update_new_quantities(Event_t *event) 
{
  ErrorCode_t result = ENGINE_OK;

  // The EE will search for the last processed event with the same drone, 
  // resource, installed, and locked values (if such an event exists) and 
  // add that quantity to the new_quantities field of the current event.

  // New query to add all previous quantities

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

  // new_cargo.  The EE will search for the last processed event with the same drone and add that quantity to the new_cargo field of the current event.

  // New query to add all previous cargo

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

  // The EE will search for the last processed event for any drone belonging to the owner of the current drone and add the quantity of the new_credit field of the current event. 

  // New query to add all previous credit

  return result;
}

/** ****************************************************************************

  @brief      Validate the event conditions to decide if can continue or not

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_validate_event_conditions(Event_t *event) 
{
  ErrorCode_t result = ENGINE_OK;

  // TODO: Define an enum for the different outcome codes and use it at DB query!!!! now we use 0 directly !!!

  // Check field by field

  // Next, the EE will ensure that the conditions are sufficient for the event to take place.  It will do so by checking for the following errors:
//a.  The local field is set to true but the affected drone is not local. The outcome will be set to -1 if this error is detected.
//b.  The affected ship contains insufficient resources of the indicated state (identical resource, locked, and installed values) to accommodate a depletion event. This happens when the newly computed new_quantity field is less than zero. The outcome will be set to -2 if this error is detected.
//c.  There affected ship contains insufficient cargo space to accommodate the accumulation event. This happens when the recently computed new_cargo value is greater than the cargo capacity of the drone (found in hull table).  The outcome will be set to -3 if this error is detected.
//d.  The player has insufficient credits to cover the purchase price of transferred items.  The outcome will be set to -4 if this error is detected.
//e.  The depletion event will violate the affected drone’s self imposed lower limit for the resource. This only applies to cases in which the acting and affected ships are different. The outcome will be set to -5 if this error is detected.
//f.  The accumulation event will violate the affected drone’s self imposed upper limit for the resource. This only applies to cases in which the acting and affected ships are different. The outcome will be set to -6 if this error is detected.
//g.  The Depletion Price for items being removed from the cargo bay of a non-allied ship is equal to or greater than the price the player has set on those items. The outcome will be set to -7 if this error is detected.
//h.  The Accumulation Price for items being added to the cargo bay of a non-allied ship is less than or equal to the price the player has set for those items. The outcome will be set to -8 if this error is detected.

  return result;
}

/** ****************************************************************************

  @brief      Once event outcome is OK, we can delete previous events from DB

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_delete_previous_events(Event_t *event) 
{
  ErrorCode_t result = ENGINE_OK;

  // Delete previous events, except this one 

  return result;
}

/** ****************************************************************************

  @brief      When something goes wrong, we abort action associated with this event

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_abort_action(Event_t *event) 
{
  ErrorCode_t result = ENGINE_OK;

  // Abort action

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
  if(result == ENGINE_OK)
  {
      engine_trace(TRACE_LEVEL_ALWAYS, "WARNING: NULL outcome event skipped");
      if(!event) return ENGINE_INTERNAL_ERROR;
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
    result = event_update_new_quantities(event);
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

#ifdef ENABLED
  2.  When it finds such an event, the EE will update the resource, credit, and cargo quantities so they represent 
  total values rather than changes in the total value.  
  Important: This adjustment will NOT be performed on events associated with market actions.  
  Depending on the affected quantity, the EE will compute the totals as follows:
a.  new_quantities. The EE will search for the last processed event with the same drone, resource, installed, and locked values (if such an event exists) and add that quantity to the new_quantities field of the current event.
b.  new_cargo.  The EE will search for the last processed event with the same drone and add that quantity to the new_cargo field of the current event.
c.  new_credit.  The EE will search for the last processed event for any drone belonging to the owner of the current drone and add the quantity of the new_credit field of the current event. 


3.  Next, the EE will ensure that the conditions are sufficient for the event to take place.  It will do so by checking for the following errors:
a.  The local field is set to true but the affected drone is not local. The outcome will be set to -1 if this error is detected.
b.  The affected ship contains insufficient resources of the indicated state (identical resource, locked, and installed values) to accommodate a depletion event. This happens when the newly computed new_quantity field is less than zero. The outcome will be set to -2 if this error is detected.
c.  There affected ship contains insufficient cargo space to accommodate the accumulation event. This happens when the recently computed new_cargo value is greater than the cargo capacity of the drone (found in hull table).  The outcome will be set to -3 if this error is detected.
d.  The player has insufficient credits to cover the purchase price of transferred items.  The outcome will be set to -4 if this error is detected.
e.  The depletion event will violate the affected drone’s self imposed lower limit for the resource. This only applies to cases in which the acting and affected ships are different. The outcome will be set to -5 if this error is detected.
f.  The accumulation event will violate the affected drone’s self imposed upper limit for the resource. This only applies to cases in which the acting and affected ships are different. The outcome will be set to -6 if this error is detected.
g.  The Depletion Price for items being removed from the cargo bay of a non-allied ship is equal to or greater than the price the player has set on those items. The outcome will be set to -7 if this error is detected.
h.  The Accumulation Price for items being added to the cargo bay of a non-allied ship is less than or equal to the price the player has set for those items. The outcome will be set to -8 if this error is detected.


4.  If any of these errors are detected, the EE will update the event’s outcome with the indicated value and flag the corresponding action in the action table as aborted. 
5.  Otherwise, the event’s outcome will be set to 1 and any events (processed or otherwise) with the same event type, drone, resource, installed, and locked values that are older than the game’s data lifespan will be deleted.  This helps ensure that the database size does not grow beyond limits.
6.  Then, the EE creates a new observations table entry for the acting and affected drones and sets the observation time to that of the event plus the distance in light minutes that the drone is away from the event.  For drones that are local to the event (meaning they are orbiting the same static object), the observation time is identical to the event time.
7.  If the event is reportable (i.e. the reportable field is true), then the EE creates an observations table entry for the Central Database (Drone ID = 0) and sets the observation time to the event time plus the distance of the drone from the Earth in light minutes.
8.  If the event is observable and the event does was successfully processed  (i.e. the observable field is true and status = 1), the EE also creates observation table entries for each local drone and sets the observation time to the event time.
#endif

  return result;
}
