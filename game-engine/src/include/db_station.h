/*
 * House drop stations (#324 step 10): the database side, in the events
 * engine. See station.h for the clearing arithmetic.
 */
#ifndef __EM_DB_STATION__
#define __EM_DB_STATION__

#include "common_types.h"

/** Records an offer (event type 9) or order (10) for the House station at
    the drone's location, in the batch open now. When no station there trades
    the resource that way (yet), sets the event's outcome to
    OUTCOME_NO_STATION and returns ENGINE_LOGIC_ERROR. */
ErrorCode_t db_station_record(Event_t* event);

/** Clears every batch that has ended: pays for what the stations buy (one
    price per batch, from the curve, within the budget), returns what they
    don't, and charges for and delivers what they sell. Each batch is cleared
    once (station_batches). Run inside a transaction. */
ErrorCode_t db_station_clear_due(void);

#endif
