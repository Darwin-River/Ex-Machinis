/*
 * Tests for holdings in memory (#694 stage 4 step 5, holding_state.c)
 */
#include <stdio.h>
#include <stdlib.h>
#include <limits.h>
#include "holding_state.h"

static int failures = 0;
#define CHECK(cond, ...) do { if(!(cond)) { failures++; printf("FAIL %s:%d: ", __FILE__, __LINE__); \
    printf(__VA_ARGS__); printf("\n"); } } while(0)

/* units() counts installed and locked 0 and 1 only, as query 212 does */
static void test_units(void)
{
    HoldingState_t s;
    holding_state_init(&s);
    CHECK(holding_state_units(&s, 7, 2061) == 0, "empty: nothing held");
    holding_state_set(&s, 7, 2061, 0, 0, 100);
    holding_state_set(&s, 7, 2061, 1, 0, 2);
    holding_state_set(&s, 7, 2061, 0, 1, 3);
    holding_state_set(&s, 7, 2061, 1, 1, 4);
    holding_state_set(&s, 7, 2061, -1, 0, 1000);   // NULL installed: not counted
    holding_state_set(&s, 7, 2061, 0, -1, 1000);   // NULL locked: not counted
    holding_state_set(&s, 8, 2061, 0, 0, 50);
    holding_state_set(&s, 7, 2062, 0, 0, 60);
    CHECK(holding_state_units(&s, 7, 2061) == 109, "sum of the four kinds: %lld", holding_state_units(&s, 7, 2061));
    CHECK(holding_state_units(&s, 8, 2061) == 50, "another drone's");
    CHECK(holding_state_units(&s, 7, 2062) == 60, "another resource");
    CHECK(holding_state_units(&s, 7, 9999) == 0, "a resource not held");
    CHECK(holding_state_units(&s, -7, 2061) == 0, "a negative drone holds nothing");
    CHECK(holding_state_units(&s, 7, -2061) == 0, "a negative resource");
    CHECK(holding_state_units(&s, 7LL + 4294967296LL, 2061) == 0, "a drone id past 32 bits doesn't wrap");
    CHECK(s.present == 8, "rows held: %zu", s.present);
    holding_state_set(&s, 7, 2061, 0, 0, -5);
    CHECK(holding_state_units(&s, 7, 2061) == 4, "negative units count as they are: %lld", holding_state_units(&s, 7, 2061));
    holding_state_free(&s);
}

/* A change applies only over an older one */
static void test_order(void)
{
    HoldingState_t s;
    holding_state_init(&s);
    holding_state_set(&s, 1, 10, 0, 0, 5);   // a full read: id 0
    CHECK(holding_state_apply(&s, 100, 1, 10, 0, 0, 1, 7) == 1, "a change over a full read applies");
    CHECK(holding_state_units(&s, 1, 10) == 7, "7 now");
    CHECK(holding_state_apply(&s, 90, 1, 10, 0, 0, 1, 3) == 0, "an older change, read late, is ignored");
    CHECK(holding_state_units(&s, 1, 10) == 7, "still 7");
    CHECK(holding_state_apply(&s, 100, 1, 10, 0, 0, 1, 3) == 0, "the same change twice is ignored");
    CHECK(holding_state_apply(&s, 101, 1, 10, 0, 0, 0, 0) == 1, "a delete applies");
    CHECK(holding_state_units(&s, 1, 10) == 0 && s.present == 0, "deleted");
    CHECK(holding_state_apply(&s, 99, 1, 10, 0, 0, 1, 9) == 0, "an older change can't bring it back");
    CHECK(holding_state_units(&s, 1, 10) == 0, "still deleted");
    CHECK(holding_state_apply(&s, 102, 1, 10, 0, 0, 1, 4) == 1, "a newer insert brings it back");
    CHECK(holding_state_units(&s, 1, 10) == 4 && s.present == 1, "4, held again");
    // A delete of a row never seen is kept too
    CHECK(holding_state_apply(&s, 200, 2, 10, 0, 0, 0, 0) == 1, "a delete of an unknown row");
    CHECK(holding_state_apply(&s, 150, 2, 10, 0, 0, 1, 8) == 0, "an older insert after it is ignored");
    CHECK(holding_state_units(&s, 2, 10) == 0, "nothing held");
    // A full read resets the guard
    holding_state_set(&s, 1, 10, 0, 0, 11);
    CHECK(holding_state_apply(&s, 50, 1, 10, 0, 0, 1, 12) == 1, "after a full read any change applies");
    holding_state_free(&s);
}

/* Many rows: growth keeps them all; diff counts the differences */
static void test_many(void)
{
    HoldingState_t a, b;
    holding_state_init(&a);
    holding_state_init(&b);
    for(unsigned int d = 1; d <= 20000; d++)
        for(unsigned int r = 2000; r < 2010; r++) {
            holding_state_set(&a, d, r, 0, 0, (int)(d + r));
            holding_state_set(&b, d, r, 0, 0, (int)(d + r));
        }
    CHECK(a.present == 200000, "200,000 rows: %zu", a.present);
    long long sum = 0;
    for(unsigned int d = 1; d <= 20000; d++)
        sum += holding_state_units(&a, d, 2005);
    CHECK(sum == 20000LL * 2005 + 20000LL * 20001 / 2, "every row found: %lld", sum);
    CHECK(holding_state_diff(&a, &b) == 0, "the same");
    holding_state_set(&b, 5, 2001, 0, 0, 0);          // other units
    holding_state_apply(&b, 9, 6, 2001, 0, 0, 0, 0);   // deleted in b
    holding_state_set(&b, 30000, 2001, 1, 1, 3);      // held in b only
    CHECK(holding_state_diff(&a, &b) == 3, "three differences: %zu", holding_state_diff(&a, &b));
    CHECK(holding_state_diff(&b, &a) == 3, "either way");
    holding_state_swap_in(&a, &b);
    CHECK(holding_state_units(&a, 30000, 2001) == 3 && b.slots == NULL, "b's rows taken in");
    holding_state_clear(&a);
    CHECK(a.present == 0 && holding_state_units(&a, 5, 2005) == 0, "cleared");
    holding_state_free(&a);
    holding_state_free(&b);
}

int main(void)
{
    test_units();
    test_order();
    test_many();
    if(failures) {
        printf("test_holding_state: %d failure(s)\n", failures);
        return 1;
    }
    printf("test_holding_state: all passed\n");
    return 0;
}
