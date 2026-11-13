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

/* The congestion tax (#620): while the clock runs slower than its target
   because the engines can't keep up, thinking costs more, by how many times
   slower it runs (target / scale), from 1 to at most `cap` (a cap of 1 or
   less, or an unknown scale: 1) */
double compute_congestion(double scale, double target, double cap);

/* A company's share of the congestion (#620): a company that thinks little
   isn't the load, so it pays only part of it. 1 + (congestion - 1) times its
   instructions over the last game day as a share of `full_at` (at most all
   of it); `full_at` 0 or less: everyone pays all of it */
double compute_company_congestion(double congestion, long long day_instructions, long long full_at);

/* The rate a million instructions at `congestion` times the `base` rate,
   rounded (0 stays 0) */
long long compute_congested_rate(long long base, double congestion);

#endif
