/*
 * Shares and dividends (#467): the arithmetic. See shares.h.
 */
#include "shares.h"

long long dividend_for(long long net, int units)
{
    if(net <= 0 || units <= 0)
        return 0;
    if(units > SHARES_PER_COMPANY)
        units = SHARES_PER_COMPANY;
    return net * units / SHARES_PER_COMPANY;
}
