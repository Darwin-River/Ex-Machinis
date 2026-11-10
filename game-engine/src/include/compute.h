/*
 * The processor tax (#544): what a company owes for its drones' thinking.
 * Pure arithmetic, checked by test_compute.c; db_compute.c bills it.
 */
#ifndef COMPUTE_H
#define COMPUTE_H

/* While a company owes processor tax, a drone whose turn ran out thinks
   again only this many game seconds later (its waits and actions are as
   usual) */
#define COMPUTE_THROTTLE_SECONDS 3600

/* Instructions that make one credit of tax at `per_million` credits a
   million (at least 1); 0 when the tax is off */
long long compute_instructions_per_credit(long long per_million);

/* Whole credits a drone owes for `unbilled` instructions; *billed is set to
   the instructions those credits cover (the rest carries over) */
long long compute_credits_due(long long unbilled, long long per_credit, long long* billed);

/* What a company pays now from `credits` toward `owed` (its debt and the new
   bill): all of it if it can, else what it has (never below 0) */
long long compute_pay(long long credits, long long owed);

#endif
