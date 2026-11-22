/*
 * Holdings in memory (#694 stage 4 step 5, #431, MEMORY_STATE=1).
 *
 * A copy of the `holdings` table: per drone, resource, installed and locked,
 * the units held. It is fed by `holding_changes`, which triggers on
 * `holdings` fill (migration 0092) whoever writes it: settlement, the
 * website, procedures. Each change carries the feed's id; a row keeps the id
 * of the last change applied to it and never takes an older one, so a
 * change read late (its transaction committed after a newer id was read)
 * can't undo a newer one. A deleted row is kept as absent, with its id.
 *
 * This file has no MySQL in it (test_holding_state.c); db_state.c feeds it.
 */
#ifndef HOLDING_STATE_H
#define HOLDING_STATE_H

#include <stddef.h>

typedef struct
{
    unsigned int drone, resource;
    signed char installed, locked;   // -1: NULL in the event (as holdings keeps it)
    unsigned char used;              // 0: an empty slot
    unsigned char present;           // 0: deleted (kept for its id)
    int units;
    unsigned long long id;           // the last change applied (0: from a full read)
} HoldingEntry_t;

typedef struct
{
    HoldingEntry_t* slots;
    size_t size;      // a power of 2
    size_t used;      // slots taken, present or not
    size_t present;   // rows held
} HoldingState_t;

void holding_state_init(HoldingState_t* s);
void holding_state_free(HoldingState_t* s);
void holding_state_clear(HoldingState_t* s);

/* Sets a row as a full read found it (id 0: any change read afterwards
   applies). 0, or -1 out of memory */
int holding_state_set(HoldingState_t* s, unsigned int drone, unsigned int resource, int installed, int locked, int units);

/* Applies change id from the feed (present 0: the row was deleted) if it is
   newer than the last applied to that row: 1 applied, 0 older and ignored,
   -1 out of memory */
int holding_state_apply(HoldingState_t* s, unsigned long long id, unsigned int drone, unsigned int resource,
                        int installed, int locked, int present, int units);

/* A row: 1 and *units if it is held, else 0 */
int holding_state_get(const HoldingState_t* s, unsigned int drone, unsigned int resource, int installed, int locked,
                      int* units);

/* Query 212, units(): the drone's units of the resource, installed 0 or 1,
   locked 0 or 1 (rows kept under -1 don't count). Out-of-range ids hold
   nothing, as the SQL finds no row for them */
long long holding_state_units(const HoldingState_t* s, long long drone, long long resource);

/* Rows in a that b doesn't hold the same (held in one only, or other
   units): what a full check counts */
size_t holding_state_diff(const HoldingState_t* a, const HoldingState_t* b);

/* Takes b's rows into a (a's are freed), b left empty */
void holding_state_swap_in(HoldingState_t* a, HoldingState_t* b);

#endif
