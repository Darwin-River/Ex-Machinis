/*
 * The shared cache of Lua query results (#694). See include/query_cache.h.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "query_cache.h"

QueryCacheScope_t query_cache_policy(int id, int base_ms, int* ttl_ms)
{
    *ttl_ms = 0;
    if(base_ms <= 0)
        return QC_NONE;
    switch(id)
    {
    // About the world where the drone is: local sell and buy orders, every
    // exchange's orders (live here, an hour old elsewhere), deposits here
    case 610: case 600: case 615: case 640:
        *ttl_ms = base_ms;
        return QC_PLACE;
    // About the whole world: the price board (an hour old already), the
    // House's supply (stored once a real minute)
    case 680: case 707:
        *ttl_ms = base_ms;
        return QC_WORLD;
    // A company's health (#725: 104,000 calls at 69 ms over 10 hours on
    // the live database, a fifth of a core): every drone of a company asks
    // it each round, and it moves in game hours, so 10 real seconds (about
    // 5 game hours at the normal pace) and shared by all who ask
    case 699:
        *ttl_ms = 10000;
        return QC_WORLD;
    // Who owns drone N never changes
    case 201:
        *ttl_ms = 60000;
        return QC_WORLD;
    // The drone's own ID
    case 205:
        *ttl_ms = 3600000;
        return QC_DRONE;
    // Whether its company is a startup, and the House's station drones
    // where it is: a company that matures is told within two seconds
    case 708:
        *ttl_ms = 2000;
        return QC_DRONE_PLACE;
    default:
        return QC_NONE;
    }
}

int query_cache_key(char* out, size_t size, QueryCacheScope_t scope, const char* sql, int drone, int place)
{
    int n;
    switch(scope)
    {
    case QC_WORLD:       n = snprintf(out, size, "w|%s", sql); break;
    case QC_PLACE:       n = snprintf(out, size, "p%d|%s", place, sql); break;
    case QC_DRONE_PLACE: n = snprintf(out, size, "dp%d.%d|%s", drone, place, sql); break;
    case QC_DRONE:       n = snprintf(out, size, "d%d|%s", drone, sql); break;
    default:             return 0;
    }
    return n > 0 && (size_t)n < size;
}

static unsigned slot_of(const char* key)
{
    unsigned h = 2166136261u;
    while(*key) { h ^= (unsigned char)*key++; h *= 16777619u; }
    return h % QUERY_CACHE_SLOTS;
}

void* query_cache_get(QueryCache_t* cache, const char* key, long long now_ms)
{
    QueryCacheEntry_t* e = &cache->slots[slot_of(key)];
    if(e->key && !strcmp(e->key, key) && now_ms - e->at_ms < e->ttl_ms) {
        cache->hits++;
        return e->value;
    }
    cache->misses++;
    return NULL;
}

void query_cache_put(QueryCache_t* cache, const char* key, void* value, int ttl_ms, long long now_ms)
{
    QueryCacheEntry_t* e = &cache->slots[slot_of(key)];
    char* copy = strdup(key);
    if(!copy) {
        if(cache->free_value && value) cache->free_value(value);
        return;
    }
    if(e->value && cache->free_value) cache->free_value(e->value);
    free(e->key);
    e->key = copy;
    e->value = value;
    e->at_ms = now_ms;
    e->ttl_ms = ttl_ms;
}
