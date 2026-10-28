/*
 * Wrecks, plunder (#324 step 12) and the sales tax (#380): the arithmetic. No database:
 * db_wreck.c does the rest.
 */
#ifndef __WRECK_H__
#define __WRECK_H__

/* A plunderer's share of `units` of a resource aboard a wreck: `percent` of
   them, rounded down, plus the odd part-unit with the matching chance. The
   chance is fixed for each wreck and resource, so a single part (an engine,
   say) comes away whole from about half of the wrecks. */
long long wreck_share(long long units, int percent, int wreck, int resource);

/* The sales tax on a sale (#380): permille thousandths of what the buyer
   pays, to the nearest credit. The seller pays it out of the sale. */
long long sales_tax(long long gross, int permille);

#endif
