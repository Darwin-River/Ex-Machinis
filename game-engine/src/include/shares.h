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

/* Dividends from profit (#603): a company's profit for a game day first
   makes up the losses carried forward (`carry`, 0 or below); what is left
   above 0 is shared among its holders, and the carry is then 0. A day that
   leaves the total at 0 or below pays nothing and carries it. Returns what
   is shared out (0 or more) and sets `*carry_after`. */
long long dividend_pool(long long carry, long long profit, long long* carry_after);

/* The days a profit history is logged for, without paying, when a company is
   first settled (#603): share prices need a profit history from the start */
#define DIVIDEND_LOG_DAYS 7

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
#define SHARE_OVER_CAP    6   /* a company selling its own shares would leave others holding too many (#599) */

/* Whether `units` shares can change hands at `price` each against a
   standing order. A pull buys from a sell order: the seller must keep
   `limit` (its order's kept minimum). A push sells into a buy order: the
   buyer may hold at most `limit` (its wanted maximum). */
int share_trade_check(int side, long long units, long long price, long long limit,
                      long long seller_held, long long buyer_held, long long buyer_credits);

/* The House's asking price a share (#469): 30 game days of dividends at the
   rate of the company's profit a day (#603; its sales after tax until then),
   for a company with `total` shares, at least 1 */
long long house_share_price(long long net_sales_last_day, long long total);

/* Offerings of new shares (#485). Why one is refused (0: it goes through) */
#define OFFER_OK            0
#define OFFER_BAD           1   /* no units, no price, a minimum out of range, or a deadline out of range */
#define OFFER_TOO_MANY      2   /* more new shares than the company already has */
#define OFFER_ALREADY_OPEN  3   /* the company already has an open offering */
#define OFFER_OVER_CAP      4   /* it would leave others holding too many of the company's shares (#599) */
#define OFFER_MAX_DAYS     30   /* the longest deadline, in game days */

/* The most of a company other companies may come to hold, in thousandths
   (#599): an offering, or a company selling its own shares, that would take
   them past it is refused, so a company keeps control of half its income.
   Round 62: one script's bug offered shares on every trip and sold most of
   two good companies (Tobias 3: from 1,000 shares to 8,150) */
#define SHARES_MAX_OUTSIDE_PERMILLE 500

/* Whether others holding `outside` of a company's `count` shares is past
   SHARES_MAX_OUTSIDE_PERMILLE */
int shares_over_cap(long long outside, long long count);

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
