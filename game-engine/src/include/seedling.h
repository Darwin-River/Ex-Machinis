/*
 * Seedlings (#637, step 2 of the new rules in #635): what a seedling may
 * trade. No database: db_seedling.c does the rest.
 */
#ifndef __SEEDLING_H__
#define __SEEDLING_H__

/* One side of a transfer between two drones: its company, and whether that
   company is a seedling or the House */
typedef struct {
    int company;
    int seedling;
    int house;
} SeedlingSide_t;

/* A transfer's outcome under the seedling rules: 0 when it may go ahead, or
   OUTCOME_SEEDLING_HOUSE_ONLY when a seedling deals with anyone but the
   House (or its own company), or OUTCOME_SEEDLING_CAP when a seedling buying
   from the House would take more today than cap_permille thousandths of the
   House's stock (at least 1 unit). `from` gives up `units`, `to` receives
   them; `house_stock` is what the House drone holds now, and `bought_today`
   what the receiving seedling bought of it at that exchange today. */
int seedling_transfer_outcome(SeedlingSide_t from, SeedlingSide_t to, long long units,
                              long long house_stock, long long bought_today, int cap_permille);

/* The most a seedling may buy in a game day from a House stock of `stock` */
long long seedling_buy_cap(long long stock, int cap_permille);

#endif
