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

#endif
