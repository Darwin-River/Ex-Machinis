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

    printf("%s\n", failures ? "FAILED" : "all passed");
    return failures ? 1 : 0;
}
