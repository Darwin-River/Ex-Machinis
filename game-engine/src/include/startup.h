/*
 * Startups (#637, step 2 of the new rules in #635): what a startup may
 * trade. No database: db_startup.c does the rest.
 */
#ifndef __STARTUP_H__
#define __STARTUP_H__

/* One side of a transfer between two drones: its company, and whether that
   company is a startup or the House */
typedef struct {
    int company;
    int startup;
    int house;
} StartupSide_t;

/* A transfer's outcome under the startup rules: 0 when it may go ahead, or
   OUTCOME_STARTUP_HOUSE_ONLY when a startup deals with anyone but the
   House (or its own company), or OUTCOME_STARTUP_CAP when a startup buying
   from the House would take more today than cap_permille thousandths of the
   House's stock (at least STARTUP_BUY_FLOOR units). `from` gives up `units`, `to` receives
   them; `house_stock` is what the House has on sale there
   (its sell order's units above the stock it keeps) plus what this startup
   bought today (#651), and `bought_today`
   what the receiving startup bought of it at that exchange today. */
int startup_transfer_outcome(StartupSide_t from, StartupSide_t to, long long units,
                              long long house_stock, long long bought_today, int cap_permille);

/* The least a startup may buy of a good at one exchange in a game day,
   whatever the House has on sale there (#777) */
#define STARTUP_BUY_FLOOR 5

/* The most a startup may buy in a game day from a House stock of `stock`:
   cap_permille thousandths of it, and at least STARTUP_BUY_FLOOR */
long long startup_buy_cap(long long stock, int cap_permille);

/* The minimum order life (#639): may a drone of a new-rules company replace
   its standing order (posted `age` game seconds ago, at prev_price for
   prev_units) with one at new_price for new_units? Within `lock` seconds it
   may not withdraw it, cut its units (a buy order's wanted units lower,
   a sell order's kept stock higher), or make it worse for the other side
   (a buy order lower, a sell order higher): 0 when it may, else
   OUTCOME_ORDER_LOCKED. `buy` is 1 for a buy order, 0 for a sell order. */
int order_lock_outcome(int buy, long long prev_price, long long prev_units, long long new_price, long long new_units,
                       long long age, long long lock);

/* What a startup holding `credits` must give the bank (#672): what is above
   cap_permille thousandths of its debt (0 when below it, or when cap_permille
   is 0: no cap) */
long long startup_over_cap(long long credits, long long debt, int cap_permille);

/* What the credit cap is a multiple of (#684): the debt without the push
   fees, so paying for a push doesn't raise the cap */
long long startup_cap_base(long long debt, long long push_fees);

#endif
