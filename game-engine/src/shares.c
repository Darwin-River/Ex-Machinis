/*
 * Shares and dividends (#467): the arithmetic. See shares.h.
 */
#include "shares.h"

long long dividend_for(long long net, long long units, long long total)
{
    if(net <= 0 || units <= 0 || total <= 0)
        return 0;
    if(units > total)
        units = total;
    return net * units / total;
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

long long house_share_price(long long net_sales_last_day, long long total)
{
    long long price = (net_sales_last_day > 0 && total > 0) ? net_sales_last_day * 30 / total : 0;
    return price > 0 ? price : 1;
}

int offer_check(long long units, long long min, long long price, long long days, long long count, int open)
{
    if(units <= 0 || min < 1 || min > units || price <= 0 || days < 1 || days > OFFER_MAX_DAYS)
        return OFFER_BAD;
    if(units > count)
        return OFFER_TOO_MANY;
    if(open)
        return OFFER_ALREADY_OPEN;
    return OFFER_OK;
}

int shares_over_cap(long long outside, long long count)
{
    if(outside <= 0)
        return 0;
    if(count <= 0)
        return 1;
    return outside * 1000 > count * SHARES_MAX_OUTSIDE_PERMILLE;
}

long long offer_issues(long long committed, long long min)
{
    return (committed > 0 && committed >= min) ? committed : 0;
}

int commit_check(long long units, long long left, long long price, long long credits)
{
    if(units <= 0 || left <= 0 || price <= 0)
        return COMMIT_NO_OFFERING;
    if(units > left)
        return COMMIT_TOO_MANY;
    if(credits < units * price)
        return COMMIT_NO_CREDITS;
    return COMMIT_OK;
}
