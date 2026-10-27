/*
 * The order of an action's events at the same game time (#380). See
 * event_order.h.
 */
#include <stdlib.h>

#include "event_order.h"

#define ORDER_INCREMENT 1
#define ORDER_DECREMENT 2
#define ORDER_FUEL      2061

typedef struct
{
    long long delay;
    int rank;      // 0 fuel burn, 1 equipment check, 2 other decrement, 3 the rest
    int pair;      // an equipment check: the index of its decrement
    int half;      // 0 the decrement, 1 the increment
    int index;
} OrderKey_t;

static int order_compare(const void *a, const void *b)
{
    const OrderKey_t *x = a, *y = b;
    if(x->delay != y->delay) return x->delay < y->delay ? -1 : 1;
    if(x->rank != y->rank) return x->rank - y->rank;
    if(x->pair != y->pair) return x->pair - y->pair;
    if(x->half != y->half) return x->half - y->half;
    return x->index - y->index;
}

static int order_matches(const EventKey_t *d, const EventKey_t *i)
{
    return i->event_type == ORDER_INCREMENT && i->delay == d->delay &&
           i->drone_id == d->drone_id && i->resource_id == d->resource_id &&
           i->installed == d->installed && i->locked == d->locked &&
           abs(i->quantity) == abs(d->quantity);
}

void event_order(const EventKey_t *keys, int n, int *order)
{
    OrderKey_t *k = calloc(n > 0 ? n : 1, sizeof(OrderKey_t));
    if(!k) {
        for(int i = 0; i < n; i++) order[i] = i;
        return;
    }

    for(int i = 0; i < n; i++) {
        k[i].delay = keys[i].delay;
        k[i].index = i;
        k[i].pair = 0;
        k[i].half = 0;
        k[i].rank = 3;
        if(keys[i].event_type == ORDER_DECREMENT)
            k[i].rank = keys[i].resource_id == ORDER_FUEL ? 0 : 2;
    }

    // Pair each other decrement with the first unpaired increment that puts
    // the same thing back at the same time
    for(int d = 0; d < n; d++) {
        if(k[d].rank != 2) continue;
        for(int i = 0; i < n; i++) {
            if(k[i].rank != 3 || !order_matches(&keys[d], &keys[i])) continue;
            k[d].rank = k[i].rank = 1;
            k[d].pair = k[i].pair = d;
            k[i].half = 1;
            break;
        }
    }

    qsort(k, n, sizeof(OrderKey_t), order_compare);
    for(int i = 0; i < n; i++) order[i] = k[i].index;
    free(k);
}
