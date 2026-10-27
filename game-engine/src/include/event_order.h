/*
 * The order of an action's events at the same game time (#380).
 *
 * Many protocols check for a piece of equipment by taking it out and putting
 * it straight back: a decrement and an increment of the same thing at the
 * same time. If anything between the two fails, the action is aborted and
 * the increment never runs, so the equipment is lost. Round 20 lost every
 * Machinist's Spider that way: the fuel burn sat between the two halves.
 *
 * So, at each game time: the action's fuel cost first (not fuel a transfer
 * moves: a pull of fuel processed its transfer, which runs both halves at
 * once, before the cost, which then undid it), then each equipment check with its
 * two halves together, then the other decrements, then everything else. Ties
 * keep the order the effects were worked out in.
 */
#ifndef EVENT_ORDER_H
#define EVENT_ORDER_H

typedef struct
{
    long long delay;   // game seconds after the action starts
    int event_type;    // 1 increment, 2 decrement, others as in common_types.h
    int drone_id;
    int resource_id;
    int installed;
    int locked;
    int quantity;      // the change (negative for a decrement)
    int burn;          // 1 for the action's own fuel cost
} EventKey_t;

// Fills order[0..n-1] with the indices of keys in the order to insert them
void event_order(const EventKey_t *keys, int n, int *order);

#endif
