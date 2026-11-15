/* Tests for the shared Lua query cache (#694) */
#include <assert.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "query_cache.h"

static int freed = 0;
static void count_free(void* v) { freed++; free(v); }

int main(void)
{
    int ttl = -1;
    // Off when the base time is 0
    assert(query_cache_policy(610, 0, &ttl) == QC_NONE && ttl == 0);
    // World answers by place or for everyone; a drone's own questions never
    assert(query_cache_policy(610, 500, &ttl) == QC_PLACE && ttl == 500);
    assert(query_cache_policy(615, 500, &ttl) == QC_PLACE);
    assert(query_cache_policy(680, 500, &ttl) == QC_WORLD);
    assert(query_cache_policy(708, 500, &ttl) == QC_DRONE_PLACE && ttl == 2000);
    assert(query_cache_policy(212, 500, &ttl) == QC_NONE);   // units(): its own hold
    assert(query_cache_policy(207, 500, &ttl) == QC_NONE);   // now(): the time moves

    // Keys: the same SQL at two places differs; two drones at one place share
    char a[256], b[256], c[256];
    assert(query_cache_key(a, sizeof(a), QC_PLACE, "SELECT 1", 7, 10));
    assert(query_cache_key(b, sizeof(b), QC_PLACE, "SELECT 1", 8, 10));
    assert(query_cache_key(c, sizeof(c), QC_PLACE, "SELECT 1", 7, 11));
    assert(!strcmp(a, b) && strcmp(a, c));
    assert(query_cache_key(a, sizeof(a), QC_DRONE_PLACE, "SELECT 1", 7, 10));
    assert(query_cache_key(b, sizeof(b), QC_DRONE_PLACE, "SELECT 1", 8, 10));
    assert(strcmp(a, b));
    assert(!query_cache_key(a, 4, QC_WORLD, "SELECT 1", 0, 0));   // too long

    // Fresh, stale, replaced
    static QueryCache_t cache;
    cache.free_value = count_free;
    query_cache_put(&cache, "k", strdup("one"), 500, 1000);
    assert(!strcmp((char*)query_cache_get(&cache, "k", 1499), "one"));
    assert(query_cache_get(&cache, "k", 1500) == NULL);
    assert(query_cache_get(&cache, "other", 1000) == NULL);
    query_cache_put(&cache, "k", strdup("two"), 500, 2000);
    assert(freed == 1);
    assert(!strcmp((char*)query_cache_get(&cache, "k", 2100), "two"));
    assert(cache.hits == 2 && cache.misses == 2);

    printf("query cache: policy, keys and expiry OK\n");
    return 0;
}
