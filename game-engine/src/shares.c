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

int share_trade_check(int side, long long units, long long price, long long limit,
                      long long seller_held, long long buyer_held, long long buyer_credits)
{
    if(units <= 0 || price <= 0)
        return SHARE_NO_ORDER;
    if(side == SHARE_SELL && seller_held - units < limit)
        return SHARE_BELOW_KEEP;
    if(seller_held < units)
        return SHARE_NOT_HELD;
    if(side == SHARE_BUY && buyer_held + units > limit)
        return SHARE_ABOVE_WANT;
    if(buyer_credits < units * price)
        return SHARE_NO_CREDITS;
    return SHARE_OK;
}

long long house_share_price(long long net_sales_last_day)
{
    long long price = net_sales_last_day > 0 ? net_sales_last_day * 30 / SHARES_PER_COMPANY : 0;
    return price > 0 ? price : 1;
}
