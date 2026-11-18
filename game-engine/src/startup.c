/*
 * Startups (#637): the trading rules' arithmetic. See startup.h.
 */
#include "common_types.h"
#include "startup.h"

long long startup_buy_cap(long long stock, int cap_permille)
{
    if(stock < 0) stock = 0;
    long long cap = stock * (long long)cap_permille / 1000;
    // At least STARTUP_BUY_FLOOR units (#777: Earth often had 17 to 40
    // metals on sale, so a Maker got 1 to 4 a day and took 70 to 110 game
    // days to mature)
    return cap < STARTUP_BUY_FLOOR ? STARTUP_BUY_FLOOR : cap;
}

int startup_transfer_outcome(StartupSide_t from, StartupSide_t to, long long units,
                              long long house_stock, long long bought_today, int cap_permille)
{
    // Within one company, anything goes (a startup's own drones)
    if(from.company == to.company)
        return 0;
    if(!from.startup && !to.startup)
        return 0;
    // A startup trades only with the House
    if((from.startup && !to.house) || (to.startup && !from.house))
        return OUTCOME_STARTUP_HOUSE_ONLY;
    // Buying from the House: a share of its stock a game day
    if(to.startup && bought_today + units > startup_buy_cap(house_stock, cap_permille))
        return OUTCOME_STARTUP_CAP;
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

long long startup_cap_base(long long debt, long long push_fees)
{
    return debt > push_fees ? debt - push_fees : 0;
}

long long startup_over_cap(long long credits, long long debt, int cap_permille)
{
    if(cap_permille <= 0 || debt <= 0)
        return 0;
    long long cap = debt * (long long)cap_permille / 1000;
    return credits > cap ? credits - cap : 0;
}
