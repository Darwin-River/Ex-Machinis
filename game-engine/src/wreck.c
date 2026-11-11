/*
 * Wrecks, plunder (#324 step 12) and the sales tax (#380): the arithmetic. See wreck.h.
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

long long sales_tax(long long gross, int permille)
{
    if(gross <= 0 || permille <= 0)
        return 0;
    if(permille >= 1000)
        return gross;
    return (gross * permille + 500) / 1000;     // to the nearest credit
}
