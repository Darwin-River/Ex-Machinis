/*
 * The processor tax (#544): the arithmetic. See compute.h.
 */
#include <math.h>
#include "compute.h"

long long compute_instructions_per_credit(long long per_million)
{
    if(per_million <= 0)
        return 0;
    long long n = 1000000 / per_million;
    return n > 0 ? n : 1;
}

long long compute_credits_due(long long unbilled, long long per_credit, long long* billed)
{
    *billed = 0;
    if(unbilled <= 0 || per_credit <= 0)
        return 0;
    long long credits = unbilled / per_credit;
    *billed = credits * per_credit;
    return credits;
}

double compute_congestion(double scale, double target, double cap)
{
    if(!(cap > 1) || !(scale > 0) || !(target > 0) || scale >= target)
        return 1.0;
    double f = target / scale;
    return f > cap ? cap : f;
}

double compute_company_congestion(double congestion, long long day_instructions, long long full_at)
{
    if(!(congestion > 1))
        return 1.0;
    if(full_at <= 0)
        return congestion;
    double share = day_instructions <= 0 ? 0 : (double)day_instructions / (double)full_at;
    if(share > 1) share = 1;
    return 1 + (congestion - 1) * share;
}

long long compute_congested_rate(long long base, double congestion)
{
    if(base <= 0)
        return 0;
    return llround((double)base * (congestion > 1 ? congestion : 1.0));
}

long long compute_pay(long long credits, long long owed)
{
    if(owed <= 0 || credits <= 0)
        return 0;
    return credits < owed ? credits : owed;
}
