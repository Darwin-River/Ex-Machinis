/*
 * Shares and dividends (#467, part of the stock market #466): the
 * arithmetic. No database: db_wreck.c pays them.
 */
#ifndef __SHARES_H__
#define __SHARES_H__

/* Every company has this many shares */
#define SHARES_PER_COMPANY 1000

/* What `units` shares are paid from a sale's net income (after sales tax):
   0.1% a share, rounded down. Nothing from a loss or for no shares. */
long long dividend_for(long long net, int units);

/* The share exchange (#469): shares trade the way goods do. The two
   sides of a standing order */
#define SHARE_SELL 1
#define SHARE_BUY  2

/* Why a pull or push of shares is refused (0: it goes through) */
#define SHARE_OK          0
#define SHARE_NO_ORDER    1   /* no such order (or it's the drone's own company's) */
#define SHARE_BELOW_KEEP  2   /* the seller would hold fewer than its sell order keeps */
#define SHARE_ABOVE_WANT  3   /* the buyer would hold more than its buy order wants */
#define SHARE_NO_CREDITS  4   /* the buyer can't pay */
#define SHARE_NOT_HELD    5   /* the seller doesn't hold that many */

/* Whether `units` shares can change hands at `price` each against a
   standing order. A pull buys from a sell order: the seller must keep
   `limit` (its order's kept minimum). A push sells into a buy order: the
   buyer may hold at most `limit` (its wanted maximum). */
int share_trade_check(int side, long long units, long long price, long long limit,
                      long long seller_held, long long buyer_held, long long buyer_credits);

/* The House's asking price a share (#469): 30 game days of dividends at the
   rate of the company's sales income after tax over the last game day,
   at least 1 */
long long house_share_price(long long net_sales_last_day);

#endif
