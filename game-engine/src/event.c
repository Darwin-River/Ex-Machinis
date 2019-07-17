/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Events management module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <string.h>
#include <limits.h>

#include "event.h"
#include "trace.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief      Processes outcome event info

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_process_outcome(Event_t *event) 
{
  ErrorCode_t result = ENGINE_OK;

  // IMPORTANT: at query to get outcome events... check that it is not an aborted action!!!!! (see doc)

  // Do the maths to calculate total cargo

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
