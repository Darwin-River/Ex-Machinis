/*
 * A small cache of rows that don't change while the engines run (#431): a
 * query's definition, a resource, an event type. The engines asked MySQL for
 * them on every turn and event (about one statement in five). Entries expire
 * after a time, so game data reloaded by a deploy is picked up without
 * restarting the engines.
 */
#ifndef STATIC_CACHE_H
#define STATIC_CACHE_H

#include <stddef.h>
#include <time.h>

#define STATIC_CACHE_SLOTS 1024
#define STATIC_CACHE_PROBES 8

typedef struct
{
    int id;
    time_t at;      // when it was stored (0: empty)
    size_t size;
    void* data;
} StaticCacheEntry_t;

typedef struct
{
    int ttl;        // real seconds an entry is good for
    StaticCacheEntry_t slots[STATIC_CACHE_SLOTS];
} StaticCache_t;

// Copies the entry for id into out (size bytes) if there is a fresh one.
// Returns 1 on a hit, 0 otherwise. now is the real time (time(NULL)).
int static_cache_get(StaticCache_t* cache, int id, void* out, size_t size, time_t now);

// Stores a copy of size bytes for id
void static_cache_put(StaticCache_t* cache, int id, const void* in, size_t size, time_t now);

#endif
