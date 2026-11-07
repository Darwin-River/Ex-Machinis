/* Tests for shares.c (#467) */
#include <stdio.h>
#include <stdlib.h>
#include "shares.h"

static int failures = 0;
static void check(const char* what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

int main(void)
{
    check("one share of 1,000 credits pays 1", dividend_for(1000, 1) == 1);
    check("all 1,000 shares pay everything", dividend_for(1234, 1000) == 1234);
    check("half the shares pay half", dividend_for(1000, 500) == 500);
    check("rounded down: one share of 999 pays nothing", dividend_for(999, 1) == 0);
    check("250 shares of 999 pay 249", dividend_for(999, 250) == 249);
    check("nothing from nothing", dividend_for(0, 500) == 0);
    check("nothing from a loss", dividend_for(-500, 500) == 0);
    check("no shares, no dividend", dividend_for(1000, 0) == 0);
    check("never more than the sale", dividend_for(1000, 5000) == 1000);
    check("big sales don't overflow", dividend_for(2000000000LL, 1000) == 2000000000LL);
    // Holders' dividends never add up to more than the sale
    long long net = 777, paid = 0;
    int holders[] = { 333, 333, 334 };
    for(int i = 0; i < 3; i++) paid += dividend_for(net, holders[i]);
    check("holders of all shares get no more than the sale", paid <= net);

    // The exchange (#469)
    {
        ShareOrder_t offers[] = { {1, 100, 10}, {2, 50, 12}, {3, 200, 20} };
        ShareOrder_t bids[] = { {7, 120, 15}, {8, 100, 11} };
        long long credits[] = { 100000, 100000 };
        ShareFill_t f[8];
        int n = shares_cross(offers, 3, bids, 2, credits, f, 8);
        check("the highest bid buys the cheapest offer first, at its price",
              n >= 1 && f[0].offer == 0 && f[0].bid == 0 && f[0].units == 100 && f[0].price == 10);
        check("then the next cheapest it can meet", n >= 2 && f[1].offer == 1 && f[1].units == 20 && f[1].price == 12);
        check("an offer above every bid doesn't sell", offers[2].units == 200);
        check("a bid below what's left doesn't buy", n == 2 && bids[1].units == 100);
        check("the buyer paid what it bought", credits[0] == 100000 - 100 * 10 - 20 * 12);
    }
    {
        ShareOrder_t offers[] = { {1, 100, 10} };
        ShareOrder_t bids[] = { {7, 100, 50} };
        long long credits[] = { 255 };
        ShareFill_t f[4];
        int n = shares_cross(offers, 1, bids, 1, credits, f, 4);
        check("a buyer gets only what its credits pay for", n == 1 && f[0].units == 25 && credits[0] == 5);
    }
    {
        ShareOrder_t offers[] = { {7, 100, 10}, {1, 100, 11} };
        ShareOrder_t bids[] = { {7, 50, 20} };
        long long credits[] = { 100000 };
        ShareFill_t f[4];
        int n = shares_cross(offers, 2, bids, 1, credits, f, 4);
        check("a company doesn't buy from itself", n == 1 && f[0].offer == 1 && offers[0].units == 100);
    }
    check("the House asks 30 days of dividends", house_share_price(100000) == 3000);
    check("and at least 1 credit", house_share_price(0) == 1 && house_share_price(10) == 1);

    if(failures) { printf("test_shares: %d failed\n", failures); return 1; }
    printf("test_shares: all passed\n");
    return 0;
}
