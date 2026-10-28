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

/** The sales tax rate (#380), in thousandths of a sale: house_rules.sales_tax_permille */
int db_sales_tax_permille(void);

/** Records the sales tax a company paid on a sale, with its credits after */
ErrorCode_t db_record_sales_tax(int company, long long amount, long long credits_after);

#endif
