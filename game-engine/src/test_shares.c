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

    // The exchange (#469): a pull buys from a sell order, a push sells into a buy order
    check("a pull within the seller's surplus goes through",
          share_trade_check(SHARE_SELL, 40, 5, 900, 1000, 0, 200) == SHARE_OK);
    check("a pull that would leave the seller below what it keeps is refused",
          share_trade_check(SHARE_SELL, 150, 5, 900, 1000, 0, 100000) == SHARE_BELOW_KEEP);
    check("a buyer who can't pay is refused", share_trade_check(SHARE_SELL, 40, 5, 900, 1000, 0, 199) == SHARE_NO_CREDITS);
    check("a push within what the buyer wants goes through",
          share_trade_check(SHARE_BUY, 50, 8, 100, 60, 40, 400) == SHARE_OK);
    check("a push past what the buyer wants is refused",
          share_trade_check(SHARE_BUY, 61, 8, 100, 100, 40, 100000) == SHARE_ABOVE_WANT);
    check("a seller can't push shares it doesn't hold",
          share_trade_check(SHARE_BUY, 50, 8, 100, 49, 0, 100000) == SHARE_NOT_HELD);
    check("nothing for nothing", share_trade_check(SHARE_SELL, 0, 5, 0, 10, 0, 100) == SHARE_NO_ORDER);
    check("the House asks 30 days of dividends", house_share_price(100000) == 3000);
    check("and at least 1 credit", house_share_price(0) == 1 && house_share_price(10) == 1);

    if(failures) { printf("test_shares: %d failed\n", failures); return 1; }
    printf("test_shares: all passed\n");
    return 0;
}
