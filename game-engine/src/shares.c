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

int shares_cross(ShareOrder_t* offers, int n_offers, ShareOrder_t* bids, int n_bids,
                 long long* credits, ShareFill_t* out, int max)
{
    int n = 0;
    for(int b = 0; b < n_bids && n < max; b++) {
        for(int o = 0; o < n_offers && n < max && bids[b].units > 0; o++) {
            if(offers[o].units <= 0 || offers[o].company == bids[b].company)
                continue;
            if(offers[o].price > bids[b].price)
                break;              /* sorted: no cheaper offers left */
            long long price = offers[o].price;
            long long units = offers[o].units < bids[b].units ? offers[o].units : bids[b].units;
            long long affordable = price > 0 ? credits[b] / price : units;
            if(affordable < units)
                units = affordable;
            if(units <= 0)
                break;              /* this bid can't pay for any more */
            out[n].offer = o;
            out[n].bid = b;
            out[n].units = units;
            out[n].price = price;
            n++;
            offers[o].units -= units;
            bids[b].units -= units;
            credits[b] -= units * price;
        }
    }
    return n;
}

long long house_share_price(long long net_sales_last_day)
{
    long long price = net_sales_last_day > 0 ? net_sales_last_day * 30 / SHARES_PER_COMPANY : 0;
    return price > 0 ? price : 1;
}
