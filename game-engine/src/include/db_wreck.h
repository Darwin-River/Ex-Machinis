/*
 * Wrecks, plunder and company end (#324 step 12): the database side, in the
 * events engine. See wreck.h for the arithmetic.
 */
#ifndef __EM_DB_WRECK__
#define __EM_DB_WRECK__

#include "common_types.h"

/** A drone plunders a wreck (event type 11; the wreck's ID is in
    new_quantity). The wreck must be where the drone is, not yet plundered,
    and another player's: otherwise sets the event's outcome
    (OUTCOME_NO_WRECK or OUTCOME_SAME_PLAYER) and returns ENGINE_LOGIC_ERROR.
    Everything aboard leaves the wreck; the plunderer's share arrives as
    actions of protocol 35, one per resource, as far as its hold has room.
    The rest is destroyed. */
ErrorCode_t db_plunder(Event_t* event);

/** Charges the tax bills that are due. A company in its grace period pays
    nothing; one that can't pay is eliminated: its credits vanish and its
    drones become wrecks. Run inside a transaction. */
ErrorCode_t db_tax_due(void);

#endif
