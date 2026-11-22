/*
 * Holdings in memory (#694 stage 4 step 5). See include/holding_state.h.
 */
#include <stdlib.h>
#include <string.h>
#include <limits.h>
#include "holding_state.h"

#define HS_MIN_SIZE 1024

static size_t hs_hash(unsigned int drone, unsigned int resource, int installed, int locked)
{
    unsigned long long h = drone;
    h = h * 0x9E3779B97F4A7C15ULL ^ resource;
    h = h * 0x9E3779B97F4A7C15ULL ^ (unsigned long long)(unsigned char)installed;
    h = h * 0x9E3779B97F4A7C15ULL ^ (unsigned long long)(unsigned char)locked;
    h ^= h >> 29;
    h *= 0xBF58476D1CE4E5B9ULL;
    h ^= h >> 32;
    return (size_t)h;
}

static HoldingEntry_t* hs_find(const HoldingState_t* s, unsigned int drone, unsigned int resource, int installed,
                               int locked, int* found)
{
    *found = 0;
    if(!s->slots)
        return NULL;
    size_t mask = s->size - 1;
    for(size_t i = hs_hash(drone, resource, installed, locked) & mask; ; i = (i + 1) & mask) {
        HoldingEntry_t* e = &s->slots[i];
        if(!e->used)
            return e;
        if(e->drone == drone && e->resource == resource && e->installed == installed && e->locked == locked) {
            *found = 1;
            return e;
        }
    }
}

static int hs_grow(HoldingState_t* s)
{
    size_t size = s->size ? s->size * 2 : HS_MIN_SIZE;
    HoldingEntry_t* slots = calloc(size, sizeof(HoldingEntry_t));
    if(!slots)
        return -1;
    HoldingState_t grown = { slots, size, 0, 0 };
    for(size_t i = 0; i < s->size; i++) {
        HoldingEntry_t* e = &s->slots[i];
        if(!e->used)
            continue;
        int found;
        HoldingEntry_t* d = hs_find(&grown, e->drone, e->resource, e->installed, e->locked, &found);
        *d = *e;
        grown.used++;
        if(e->present) grown.present++;
    }
    free(s->slots);
    *s = grown;
    return 0;
}

/* The row's slot, made if new: NULL out of memory */
static HoldingEntry_t* hs_slot(HoldingState_t* s, unsigned int drone, unsigned int resource, int installed, int locked)
{
    if((s->used + 1) * 10 > s->size * 7 && hs_grow(s))
        return NULL;
    int found;
    HoldingEntry_t* e = hs_find(s, drone, resource, installed, locked, &found);
    if(!found) {
        memset(e, 0, sizeof(*e));
        e->used = 1;
        e->drone = drone;
        e->resource = resource;
        e->installed = (signed char)installed;
        e->locked = (signed char)locked;
        s->used++;
    }
    return e;
}

void holding_state_init(HoldingState_t* s)
{
    memset(s, 0, sizeof(*s));
}

void holding_state_free(HoldingState_t* s)
{
    free(s->slots);
    memset(s, 0, sizeof(*s));
}

void holding_state_clear(HoldingState_t* s)
{
    if(s->slots)
        memset(s->slots, 0, s->size * sizeof(HoldingEntry_t));
    s->used = s->present = 0;
}

int holding_state_set(HoldingState_t* s, unsigned int drone, unsigned int resource, int installed, int locked, int units)
{
    HoldingEntry_t* e = hs_slot(s, drone, resource, installed, locked);
    if(!e)
        return -1;
    if(!e->present)
        s->present++;
    e->present = 1;
    e->units = units;
    e->id = 0;
    return 0;
}

int holding_state_apply(HoldingState_t* s, unsigned long long id, unsigned int drone, unsigned int resource,
                        int installed, int locked, int present, int units)
{
    int found;
    HoldingEntry_t* e = hs_find(s, drone, resource, installed, locked, &found);
    if(found && e->id >= id)
        return 0;
    // A delete of a row not held is kept too (absent, with its id), so a
    // change older than it, read late, can't bring the row back
    e = hs_slot(s, drone, resource, installed, locked);
    if(!e)
        return -1;
    if(present && !e->present) s->present++;
    if(!present && e->present) s->present--;
    e->present = present ? 1 : 0;
    e->units = present ? units : 0;
    e->id = id;
    return 1;
}

int holding_state_get(const HoldingState_t* s, unsigned int drone, unsigned int resource, int installed, int locked,
                      int* units)
{
    int found;
    const HoldingEntry_t* e = hs_find(s, drone, resource, installed, locked, &found);
    if(!found || !e->present)
        return 0;
    if(units) *units = e->units;
    return 1;
}

long long holding_state_units(const HoldingState_t* s, long long drone, long long resource)
{
    if(drone < 0 || drone > UINT_MAX || resource < 0 || resource > UINT_MAX)
        return 0;
    long long sum = 0;
    for(int installed = 0; installed <= 1; installed++)
        for(int locked = 0; locked <= 1; locked++) {
            int units;
            if(holding_state_get(s, (unsigned int)drone, (unsigned int)resource, installed, locked, &units))
                sum += units;
        }
    return sum;
}

static size_t hs_missing_or_other(const HoldingState_t* a, const HoldingState_t* b)
{
    size_t n = 0;
    for(size_t i = 0; i < a->size; i++) {
        const HoldingEntry_t* e = &a->slots[i];
        if(!e->used || !e->present)
            continue;
        int units;
        if(!holding_state_get(b, e->drone, e->resource, e->installed, e->locked, &units) || units != e->units)
            n++;
    }
    return n;
}

size_t holding_state_diff(const HoldingState_t* a, const HoldingState_t* b)
{
    // Held in a but not the same in b, plus held in b only
    size_t n = hs_missing_or_other(a, b);
    for(size_t i = 0; i < b->size; i++) {
        const HoldingEntry_t* e = &b->slots[i];
        if(e->used && e->present && !holding_state_get(a, e->drone, e->resource, e->installed, e->locked, NULL))
            n++;
    }
    return n;
}

void holding_state_swap_in(HoldingState_t* a, HoldingState_t* b)
{
    free(a->slots);
    *a = *b;
    memset(b, 0, sizeof(*b));
}
