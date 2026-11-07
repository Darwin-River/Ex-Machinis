/*
 * The share exchange at Earth (#469, step 2 of the stock market #466): the
 * database side. See shares.h for the arithmetic.
 */
#ifndef __DB_SHARES_H__
#define __DB_SHARES_H__

#include "common_types.h"
#include "db.h"

/* The House re-posts its orders once a game hour */
#define SHARE_BATCH_SECONDS 3600

/** Once a game hour: the House re-posts its sell orders for the shares it
    collected, and ended companies' orders go */
ErrorCode_t db_shares_clear_due(void);

/** Settles a share request when it reaches Earth (its Share event, type 14):
    posts or withdraws a standing order, or pulls or pushes shares against
    another company's order. Returns OUTCOME_OK or why it was refused */
int db_shares_settle(Event_t* event);

#endif
