/*
 * House drop stations (#324 step 10): the arithmetic of clearing a batch.
 * No database: db_station.c does the rest.
 */
#ifndef __STATION_H__
#define __STATION_H__

/* A batch lasts this long (game seconds): offers made during it clear
   together when it ends */
#define STATION_BATCH_SECONDS   3600

/* When a batch is short (the budget or the supply can't cover it all), no
   company gets more than this share of what is filled (percent) */
#define STATION_COMPANY_CAP_PCT 50

/* Which way goods go */
#define STATION_BUYS  1   /* players offer goods; the station pays */
#define STATION_SELLS 2   /* players order goods; they pay the station */

/* The game time a batch that is open at game time now ends */
long long station_batch_end(long long now);

/* The price a station pays per unit when a batch offers it `offered` units
   in all: base, halving for each daily_demand units (published curve),
   never below 1 */
int station_buy_price(int base, long long offered, long long daily_demand);

/* Shares out `available` units among n requests (units[i] from company[i]):
   everyone gets all they asked for when there is enough; otherwise each
   gets a share in proportion to what it asked for, no company more than
   STATION_COMPANY_CAP_PCT of `available`, and what that leaves over goes
   to the requests that want more. Fills fill[i]; returns the
   total filled (never more than available). */
long long station_share(const long long* units, const int* company, int n, long long available, long long* fill);

#endif
