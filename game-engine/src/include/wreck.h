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

/* Salvage (#929). A wreck decays by 2% of what is left a game day: its
   condition, in thousandths, after age_seconds (1000 when new). */
int wreck_condition(long long age_seconds);

/* Is a resource a module (equipment a wreck keeps for salvage, not cargo
   for plunder): 5000 to 5899 (kits, 5900 and up, are cargo) */
int wreck_equipment(int resource);

/* Does a salvaged module come away whole: a chance of condition
   thousandths, fixed for each wreck, module and how many are left */
int salvage_whole(int wreck, int resource, long long left, int condition);

/* Scrap a salvage run gets from a hull with `left` units of scrap: up to
   `run`, less the wreck's decay. Never more than is left. */
long long salvage_scrap(long long left, int condition, int run);

/* Scrap a wreck's hull holds before any is salvaged: its hull's mass, at
   least 10 */
long long hull_scrap(long long hull_mass);

/* The sales tax on a sale (#380): permille thousandths of what the buyer
   pays, to the nearest credit. The seller pays it out of the sale. */
long long sales_tax(long long gross, int permille);

#endif
