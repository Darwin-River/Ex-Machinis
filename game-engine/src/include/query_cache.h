/*
 * A shared cache of Lua query results (#694). Drones at one place ask the
 * same questions about the world (its order books, deposits, the price
 * board, the House's stock) many times a second, and those queries took
 * most of the game engine's database time. A result is kept for a short,
 * real time and shared by every drone the answer is the same for; the
 * questions a drone asks about itself (its hold, its credits) are never
 * cached.
 */
#ifndef QUERY_CACHE_H
#define QUERY_CACHE_H

#include <stddef.h>

#define QUERY_CACHE_SLOTS 4096

/* How a query's result may be shared */
typedef enum
{
    QC_NONE = 0,       // never cached
    QC_WORLD,          // the same for every drone (its SQL decides it)
    QC_PLACE,          // the same for every drone at one place
    QC_DRONE_PLACE,    // one drone's, while it stays at one place
    QC_DRONE           // one drone's, for as long as it is kept
} QueryCacheScope_t;

/* The scope of query id and how long (real milliseconds) its result is
   good for; base_ms is the configured time for answers about the world
   (0 turns caching off) */
QueryCacheScope_t query_cache_policy(int id, int base_ms, int* ttl_ms);

/* The key for a result: the query's SQL before the drone's ID and the game
   time are put in, plus the place or the drone when the scope needs them.
   Returns 0 when it doesn't fit in size bytes */
int query_cache_key(char* out, size_t size, QueryCacheScope_t scope, const char* sql, int drone, int place);

typedef void (*QueryCacheFree_t)(void* value);

typedef struct
{
    char* key;          // NULL: empty
    void* value;
    long long at_ms;
    int ttl_ms;
} QueryCacheEntry_t;

typedef struct
{
    QueryCacheFree_t free_value;
    long long hits, misses;
    QueryCacheEntry_t slots[QUERY_CACHE_SLOTS];
} QueryCache_t;

/* The value stored for key if it is still fresh at now_ms, else NULL */
void* query_cache_get(QueryCache_t* cache, const char* key, long long now_ms);

/* Stores value for key (the cache owns it from now on, and frees the one it
   replaces) */
void query_cache_put(QueryCache_t* cache, const char* key, void* value, int ttl_ms, long long now_ms);

#endif
