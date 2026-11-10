/*
 * Shares and dividends (#467, part of the stock market #466): the
 * arithmetic. No database: db_wreck.c pays them.
 */
#ifndef __SHARES_H__
#define __SHARES_H__

/* Every company starts with this many shares; offerings of new shares
   (#485) add to its count */
#define SHARES_PER_COMPANY 1000

/* What `units` of a company's `total` shares are paid from a sale's net
   income (after sales tax): net x units / total, rounded down. Nothing from
   a loss or for no shares. */
long long dividend_for(long long net, long long units, long long total);

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
   rate of the company's sales income after tax over the last game day, for
   a company with `total` shares, at least 1 */
long long house_share_price(long long net_sales_last_day, long long total);

/* Offerings of new shares (#485). Why one is refused (0: it goes through) */
#define OFFER_OK            0
#define OFFER_BAD           1   /* no units, no price, a minimum out of range, or a deadline out of range */
#define OFFER_TOO_MANY      2   /* more new shares than the company already has */
#define OFFER_ALREADY_OPEN  3   /* the company already has an open offering */
#define OFFER_MAX_DAYS     30   /* the longest deadline, in game days */

/* Whether a company with `count` shares, and an open offering or not, can
   offer up to `units` new shares, at least `min` of them (1 to units, #516),
   at `price` for `days` game days */
int offer_check(long long units, long long min, long long price, long long days, long long count, int open);

/* How many new shares an offering issues at its deadline (#516): what was
   committed, if that reached its minimum; 0 if not (everyone is refunded) */
long long offer_issues(long long committed, long long min);

/* Why a commitment is refused (0: it goes through) */
#define COMMIT_OK           0
#define COMMIT_NO_OFFERING  1   /* no open offering, or it's the drone's own company's */
#define COMMIT_TOO_MANY     2   /* more than the offering has left */
#define COMMIT_NO_CREDITS   3   /* the investor can't pay */

/* Whether `units` can be committed to an offering with `left` shares not yet
   committed, at `price` each, by an investor with `credits` */
int commit_check(long long units, long long left, long long price, long long credits);

#endif
