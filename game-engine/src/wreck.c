/*
 * Wrecks, plunder and taxes (#324 step 12): the arithmetic. See wreck.h.
 */
#include "wreck.h"

long long wreck_share(long long units, int percent, int wreck, int resource)
{
    if(units <= 0 || percent <= 0)
        return 0;
    if(percent >= 100)
        return units;

    long long share = units * percent / 100;
    long long rest = units * percent % 100;          // the part-unit, in hundredths
    unsigned long long draw = ((unsigned long long)(unsigned)wreck * 7919ULL +
                               (unsigned long long)(unsigned)resource * 104729ULL) % 100ULL;
    if((long long)draw < rest)
        share++;
    return share;
}

long long tax_bill(int flat, int permille, long long combined_credits)
{
    long long bill = flat > 0 ? flat : 0;
    if(permille > 0 && combined_credits > 0)
        bill += combined_credits * permille / 1000;
    return bill;
}

long long tax_periods_due(long long now, long long taxed_t, int period)
{
    if(period <= 0 || now <= taxed_t)
        return 0;
    return (now - taxed_t) / period;
}
