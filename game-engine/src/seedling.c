/*
 * Seedlings (#637): the trading rules' arithmetic. See seedling.h.
 */
#include "common_types.h"
#include "seedling.h"

long long seedling_buy_cap(long long stock, int cap_permille)
{
    if(stock < 0) stock = 0;
    long long cap = stock * (long long)cap_permille / 1000;
    return cap < 1 ? 1 : cap;
}

int seedling_transfer_outcome(SeedlingSide_t from, SeedlingSide_t to, long long units,
                              long long house_stock, long long bought_today, int cap_permille)
{
    // Within one company, anything goes (a seedling's own drones)
    if(from.company == to.company)
        return 0;
    if(!from.seedling && !to.seedling)
        return 0;
    // A seedling trades only with the House
    if((from.seedling && !to.house) || (to.seedling && !from.house))
        return OUTCOME_SEEDLING_HOUSE_ONLY;
    // Buying from the House: a share of its stock a game day
    if(to.seedling && bought_today + units > seedling_buy_cap(house_stock, cap_permille))
        return OUTCOME_SEEDLING_CAP;
    return 0;
}

int order_lock_outcome(int buy, long long prev_price, long long prev_units, long long new_price, long long new_units,
                       long long age, long long lock)
{
    if(age >= lock)
        return 0;
    // A buy order's units are what it wants: fewer is a cut. A sell order's
    // are the stock it keeps: more is a cut, and withdrawing one keeps all
    // (1,000,000) (#662)
    if(buy ? new_units < prev_units : new_units > prev_units)
        return OUTCOME_ORDER_LOCKED;
    if(buy ? new_price < prev_price : new_price > prev_price)
        return OUTCOME_ORDER_LOCKED;
    return 0;
}

long long seedling_over_cap(long long credits, long long debt, int cap_permille)
{
    if(cap_permille <= 0 || debt <= 0)
        return 0;
    long long cap = debt * (long long)cap_permille / 1000;
    return credits > cap ? credits - cap : 0;
}
