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

/* The share exchange (#469): the two sides of the book */
#define SHARE_OFFER 1   /* sell */
#define SHARE_BID   2   /* buy */

/* The share exchange (#469): a standing order at Earth */
typedef struct
{
    int company;          /* who placed it */
    long long units;      /* still wanted (an offer: capped by the shares held) */
    long long price;      /* credits a share: least it sells for / most it pays */
} ShareOrder_t;

/* A fill: offer `offer` sells `units` to bid `bid` at `price` a share */
typedef struct
{
    int offer;
    int bid;
    long long units;
    long long price;
} ShareFill_t;

/* Clears one company's shares. Offers are sorted cheapest first and bids
   highest first (ties: the order they arrived). Each bid in turn buys from
   the cheapest offers whose price it meets, at the seller's price, as far
   as credits[bid] lasts (spent here); a company doesn't buy from itself.
   Offers' and bids' units are spent too. Fills go to out (up to max);
   returns how many. */
int shares_cross(ShareOrder_t* offers, int n_offers, ShareOrder_t* bids, int n_bids,
                 long long* credits, ShareFill_t* out, int max);

/* The House's asking price a share (#469): 30 game days of dividends at the
   rate of the company's sales income after tax over the last game day,
   at least 1 */
long long house_share_price(long long net_sales_last_day);

#endif
