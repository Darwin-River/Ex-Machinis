/*
 * The processor tax (#544): the arithmetic. See compute.h.
 */
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

long long compute_pay(long long credits, long long owed)
{
    if(owed <= 0 || credits <= 0)
        return 0;
    return credits < owed ? credits : owed;
}
