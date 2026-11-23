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

int wreck_condition(long long age_seconds)
{
    // 1000 x 0.98^days, rounded down, as query 660 and WreckField work it out
    long long days = age_seconds > 0 ? age_seconds / 86400 : 0;
    double c = 1000.0;
    for(long long d = 0; d < days && c >= 1.0; d++)
        c *= 0.98;
    return (int)(c + 1e-9);
}

int wreck_equipment(int resource)
{
    return resource >= 5000 && resource < 5900;
}

int salvage_whole(int wreck, int resource, long long left, int condition)
{
    if(condition >= 1000)
        return 1;
    if(condition <= 0)
        return 0;
    unsigned long long draw = ((unsigned long long)(unsigned)wreck * 7919ULL +
                               (unsigned long long)(unsigned)resource * 104729ULL +
                               (unsigned long long)left * 1299709ULL) % 1000ULL;
    return (long long)draw < condition;
}

long long salvage_scrap(long long left, int condition, int run)
{
    if(left <= 0 || condition <= 0 || run <= 0)
        return 0;
    long long take = (long long)run * condition / 1000;
    return take < left ? take : left;
}

long long hull_scrap(long long hull_mass)
{
    return hull_mass < 10 ? 10 : hull_mass;
}
