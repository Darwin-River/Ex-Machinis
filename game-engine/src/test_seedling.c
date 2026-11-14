/*
 * Checks the seedling trading rules (seedling.c, #637).
 * Run with: make test
 */
#include <stdio.h>

#include "common_types.h"
#include "seedling.h"

static int failures = 0;
static void check(const char *what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

int main(void)
{
    SeedlingSide_t house = {1, 0, 1};
    SeedlingSide_t seed = {2, 1, 0};
    SeedlingSide_t seed_too = {2, 1, 0};
    SeedlingSide_t other_seed = {3, 1, 0};
    SeedlingSide_t player = {4, 0, 0};
    SeedlingSide_t player_too = {5, 0, 0};

    check("two players' companies trade freely", seedling_transfer_outcome(player, player_too, 50, 0, 0, 100) == 0);
    check("a seedling's own drones pass goods", seedling_transfer_outcome(seed, seed_too, 50, 0, 0, 100) == 0);
    check("a seedling sells to the House", seedling_transfer_outcome(seed, house, 500, 0, 0, 100) == 0);
    check("a seedling buys from the House within the cap", seedling_transfer_outcome(house, seed, 10, 100, 0, 100) == 0);
    check("a seedling can't sell to a player", seedling_transfer_outcome(seed, player, 1, 0, 0, 100) == OUTCOME_SEEDLING_HOUSE_ONLY);
    check("a seedling can't buy from a player", seedling_transfer_outcome(player, seed, 1, 0, 0, 100) == OUTCOME_SEEDLING_HOUSE_ONLY);
    check("two seedlings can't trade", seedling_transfer_outcome(seed, other_seed, 1, 0, 0, 100) == OUTCOME_SEEDLING_HOUSE_ONLY);
    check("over a tenth of the House's stock is refused", seedling_transfer_outcome(house, seed, 11, 100, 0, 100) == OUTCOME_SEEDLING_CAP);
    check("the day's earlier buys count", seedling_transfer_outcome(house, seed, 5, 100, 6, 100) == OUTCOME_SEEDLING_CAP);
    check("up to the cap exactly", seedling_transfer_outcome(house, seed, 4, 100, 6, 100) == 0);
    check("a small stock still sells 1 unit", seedling_buy_cap(3, 100) == 1 && seedling_transfer_outcome(house, seed, 1, 3, 0, 100) == 0);
    check("but not 2", seedling_transfer_outcome(house, seed, 2, 3, 0, 100) == OUTCOME_SEEDLING_CAP);
    check("selling to the House has no cap", seedling_transfer_outcome(seed, house, 1000000, 5, 1000, 100) == 0);

    // The minimum order life (#639): 12 game hours
    long long h = 3600, lock = 12 * h;
    check("a young buy order can rise", order_lock_outcome(1, 50, 10, 60, 10, h, lock) == 0);
    check("or want more", order_lock_outcome(1, 50, 10, 50, 20, h, lock) == 0);
    check("but not fall", order_lock_outcome(1, 50, 10, 40, 10, h, lock) == OUTCOME_ORDER_LOCKED);
    check("nor be withdrawn", order_lock_outcome(1, 50, 10, 0, 0, h, lock) == OUTCOME_ORDER_LOCKED);
    check("nor be cut", order_lock_outcome(1, 50, 10, 50, 5, h, lock) == OUTCOME_ORDER_LOCKED);
    check("a young sell order can get cheaper", order_lock_outcome(0, 50, 10, 40, 10, h, lock) == 0);
    check("but not dearer", order_lock_outcome(0, 50, 10, 60, 10, h, lock) == OUTCOME_ORDER_LOCKED);
    check("nor keep more back (#662)", order_lock_outcome(0, 50, 10, 50, 20, h, lock) == OUTCOME_ORDER_LOCKED);
    check("nor be withdrawn (keep all)", order_lock_outcome(0, 50, 10, 50, 1000000, h, lock) == OUTCOME_ORDER_LOCKED);
    check("but may keep less back", order_lock_outcome(0, 50, 10, 50, 5, h, lock) == 0);
    check("after 12 game hours anything goes", order_lock_outcome(1, 50, 10, 0, 0, lock, lock) == 0);

    printf("%s\n", failures ? "FAILED" : "all passed");
    return failures ? 1 : 0;
}
