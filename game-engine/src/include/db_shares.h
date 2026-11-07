/*
 * The share exchange at Earth (#469, step 2 of the stock market #466): the
 * database side. See shares.h for the arithmetic.
 */
#ifndef __DB_SHARES_H__
#define __DB_SHARES_H__

#include "common_types.h"

/* An exchange batch lasts a game hour */
#define SHARE_BATCH_SECONDS 3600

/** Clears the exchange once a game hour: the House's standing offers, the
    newest order of each kind replacing older ones, then the trades */
ErrorCode_t db_shares_clear_due(void);

#endif
