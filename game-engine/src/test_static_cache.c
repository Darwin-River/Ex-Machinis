/* Tests for the static row cache (#431) */
#include <stdio.h>
#include <string.h>
#include "static_cache.h"

static int failures = 0;
#define CHECK(c, msg) do { if(!(c)) { printf("FAIL: %s\n", msg); failures++; } } while(0)

typedef struct { int id; char name[32]; } Row_t;

int main(void)
{
    static StaticCache_t cache;
    cache.ttl = 60;
    Row_t a = {7, "seven"}, out;

    CHECK(!static_cache_get(&cache, 7, &out, sizeof(out), 1000), "empty: a miss");
    static_cache_put(&cache, 7, &a, sizeof(a), 1000);
    CHECK(static_cache_get(&cache, 7, &out, sizeof(out), 1030) && strcmp(out.name, "seven") == 0, "a hit");
    CHECK(!static_cache_get(&cache, 7, &out, sizeof(out), 1060), "expired after the ttl");
    CHECK(!static_cache_get(&cache, 8, &out, sizeof(out), 1030), "another id: a miss");

    // Replacing keeps one entry; many ids on the same slots evict the oldest
    Row_t b = {7, "SEVEN"};
    static_cache_put(&cache, 7, &b, sizeof(b), 1070);
    CHECK(static_cache_get(&cache, 7, &out, sizeof(out), 1071) && strcmp(out.name, "SEVEN") == 0, "replaced");
    for(int i = 0; i < 5000; i++) {
        Row_t r = {i, ""};
        snprintf(r.name, sizeof(r.name), "row %d", i);
        static_cache_put(&cache, i, &r, sizeof(r), 2000 + i);
    }
    CHECK(static_cache_get(&cache, 4999, &out, sizeof(out), 7000) && strcmp(out.name, "row 4999") == 0, "newest kept");
    CHECK(!static_cache_get(&cache, 7, &out, sizeof(out), 1071) || strcmp(out.name, "row 7") == 0, "never a wrong row");

    if(failures == 0)
        printf("static cache: all tests passed\n");
    return failures ? 1 : 0;
}
