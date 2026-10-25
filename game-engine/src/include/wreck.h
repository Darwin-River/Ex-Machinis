/*
 * Wrecks, plunder and taxes (#324 step 12): the arithmetic. No database:
 * db_wreck.c does the rest.
 */
#ifndef __WRECK_H__
#define __WRECK_H__

/* A plunderer's share of `units` of a resource aboard a wreck: `percent` of
   them, rounded down, plus the odd part-unit with the matching chance. The
   chance is fixed for each wreck and resource, so a single part (an engine,
   say) comes away whole from about half of the wrecks. */
long long wreck_share(long long units, int percent, int wreck, int resource);

/* One tax bill: flat, plus permille thousandths of the player's combined
   credits (never less than flat) */
long long tax_bill(int flat, int permille, long long combined_credits);

/* How many whole tax periods have passed since a company's period started */
long long tax_periods_due(long long now, long long taxed_t, int period);

#endif
