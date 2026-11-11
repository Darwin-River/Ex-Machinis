/*
 * Checks the order of an action's events (event_order.c, #380): at the same
 * game time the fuel burn comes first and an equipment check's two halves
 * stay together, so a failure can't leave the equipment out of the hold.
 * Run with: make test
 */
#include <stdio.h>
#include <string.h>

#include "event_order.h"

static int failures = 0;
static void check(const char *what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

static int same(const int *a, const int *b, int n) { return memcmp(a, b, n * sizeof(int)) == 0; }

int main(void)
{
    // The gear box (protocol 14111), effects in table order: Spider back,
    // Spider out, fuel at 0; rod, spring, gear at 1; Spider locked at 2;
    // unlocked again at 28799; the gear box at 28800
    EventKey_t box[] = {
        { 0, 1, 9, 5501, 0, 0, 1 },     // 0 Spider back
        { 0, 2, 9, 5501, 0, 0, -1 },    // 1 Spider out (the check)
        { 0, 2, 9, 2061, 0, 0, -4, 1 }, // 2 fuel (the cost)
        { 1, 2, 9, 3011, 0, 0, -1 },    // 3 rod
        { 1, 2, 9, 3101, 0, 0, -1 },    // 4 spring
        { 1, 2, 9, 3111, 0, 0, -1 },    // 5 gear
        { 2, 1, 9, 5501, 0, 1, 1 },     // 6 Spider locked in
        { 2, 2, 9, 5501, 0, 0, -1 },    // 7 Spider unlocked out
        { 28799, 1, 9, 5501, 0, 0, 1 }, // 8 Spider unlocked in
        { 28799, 2, 9, 5501, 0, 1, -1 },// 9 Spider locked out
        { 28800, 1, 9, 4111, 0, 0, 1 }, // 10 gear box
    };
    int order[11];
    event_order(box, 11, order);
    int want[] = { 2, 1, 0, 3, 4, 5, 7, 6, 9, 8, 10 };
    check("gear box: fuel first, then the Spider out and straight back", same(order, want, 11));

    // Two checks at once (mining and refining tools): each pair together
    EventKey_t two[] = {
        { 0, 1, 9, 5131, 0, 0, 1 },  // 0 Worm back
        { 0, 1, 9, 5201, 0, 0, 1 },  // 1 Smelter back
        { 0, 2, 9, 5131, 0, 0, -1 }, // 2 Worm out
        { 0, 2, 9, 5201, 0, 0, -1 }, // 3 Smelter out
        { 0, 2, 9, 2061, 0, 0, -1, 1 }, // 4 fuel (the cost)
    };
    int order2[5];
    event_order(two, 5, order2);
    int want2[] = { 4, 2, 0, 3, 1 };
    check("two checks: each goes out and back before the next", same(order2, want2, 5));

    // Pushing fuel: the cost comes first, then the transfer, decrement
    // before increment (the events engine runs a transfer's halves at once:
    // with the cost after them, it undid the delivery, #380)
    EventKey_t push[] = {
        { 0, 1, 8, 2061, 0, 0, 40, 0 },  // 0 into the clone
        { 0, 2, 9, 2061, 0, 0, -40, 0 }, // 1 out of the parent
        { 0, 2, 9, 2061, 0, 0, -1, 1 },  // 2 the push's fuel cost
    };
    int order3[3];
    event_order(push, 3, order3);
    int want3[] = { 2, 1, 0 };
    check("pushing fuel: the cost first, then the transfer", same(order3, want3, 3));

    // Pulling fuel: the seller's fuel goes, the buyer pays the cost first
    EventKey_t pull[] = {
        { 0, 2, 7, 2061, 0, 0, -3, 0 },  // 0 out of the seller
        { 0, 2, 9, 2061, 0, 0, -1, 1 },  // 1 the buyer's fuel cost
        { 60, 1, 9, 2061, 0, 0, 3, 0 },  // 2 into the buyer, a minute later
    };
    int order5[3];
    event_order(pull, 3, order5);
    int want5[] = { 1, 0, 2 };
    check("pulling fuel: the buyer's cost before the seller's fuel leaves", same(order5, want5, 3));

    // Different amounts or times don't pair
    EventKey_t other[] = {
        { 0, 1, 9, 2021, 0, 0, 1 },  // 0 an alloy made
        { 0, 2, 9, 2001, 0, 0, -2 }, // 1 metals used
        { 5, 2, 9, 2021, 0, 0, -1 }, // 2 an alloy later
    };
    int order4[3];
    event_order(other, 3, order4);
    int want4[] = { 1, 0, 2 };
    check("unmatched events: decrements first, by time", same(order4, want4, 3));

    printf("test_event_order: %s\n", failures ? "FAILED" : "all passed");
    return failures ? 1 : 0;
}
