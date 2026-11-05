/*
 * A small cache of rows that don't change while the engines run (#431).
 * See include/static_cache.h.
 */
#include <stdlib.h>
#include <string.h>
#include "static_cache.h"

static unsigned slot_of(int id)
{
    return ((unsigned)id * 2654435761u) % STATIC_CACHE_SLOTS;
}

int static_cache_get(StaticCache_t* cache, int id, void* out, size_t size, time_t now)
{
    unsigned s = slot_of(id);
    for(int p = 0; p < STATIC_CACHE_PROBES; p++) {
        StaticCacheEntry_t* e = &cache->slots[(s + p) % STATIC_CACHE_SLOTS];
        if(e->at != 0 && e->id == id) {
            if(e->size != size || now - e->at >= cache->ttl)
                return 0;
            memcpy(out, e->data, size);
            return 1;
        }
    }
    return 0;
}

void static_cache_put(StaticCache_t* cache, int id, const void* in, size_t size, time_t now)
{
    unsigned s = slot_of(id);
    StaticCacheEntry_t* target = NULL;
    // Its own slot if it has one, else the first empty one, else the oldest
    for(int p = 0; p < STATIC_CACHE_PROBES && !target; p++) {
        StaticCacheEntry_t* e = &cache->slots[(s + p) % STATIC_CACHE_SLOTS];
        if(e->at != 0 && e->id == id)
            target = e;
    }
    for(int p = 0; p < STATIC_CACHE_PROBES && !target; p++) {
        StaticCacheEntry_t* e = &cache->slots[(s + p) % STATIC_CACHE_SLOTS];
        if(e->at == 0)
            target = e;
    }
    for(int p = 0; p < STATIC_CACHE_PROBES; p++) {
        StaticCacheEntry_t* e = &cache->slots[(s + p) % STATIC_CACHE_SLOTS];
        if(!target || (target->at != 0 && target->id != id && e->at < target->at))
            target = e;
    }
    if(target->size != size) {
        free(target->data);
        target->data = malloc(size);
        if(!target->data) { target->at = 0; target->size = 0; return; }
        target->size = size;
    }
    memcpy(target->data, in, size);
    target->id = id;
    target->at = now;
}
