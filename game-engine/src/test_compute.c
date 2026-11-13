/*
 * Checks the processor tax's arithmetic (#544)
 */
#include <stdio.h>
#include <math.h>
#include "compute.h"

static int failures = 0;
static void check(const char* what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

int main(void)
{
    long long billed = 0;
    check("15 credits a million: a credit per 66,666 instructions", compute_instructions_per_credit(15) == 66666);
    check("0 turns the tax off", compute_instructions_per_credit(0) == 0);
    check("a huge rate still bills a credit an instruction", compute_instructions_per_credit(5000000) == 1);
    check("a million instructions at 15 a million: 15 credits",
          compute_credits_due(1000000, 66666, &billed) == 15 && billed == 999990);
    check("less than a credit's worth waits for the next bill",
          compute_credits_due(66665, 66666, &billed) == 0 && billed == 0);
    check("nothing billed with the tax off", compute_credits_due(1000000, 0, &billed) == 0 && billed == 0);
    check("a company that can pay pays all it owes", compute_pay(500, 120) == 120);
    check("one that can't pays what it has", compute_pay(40, 120) == 40);
    check("and nothing from below zero", compute_pay(-5, 120) == 0 && compute_pay(0, 120) == 0);
    // The congestion tax (#620)
    check("at the target pace: no congestion", compute_congestion(1800, 1800, 10) == 1.0);
    check("faster than the target (set by hand): none", compute_congestion(3600, 1800, 10) == 1.0);
    check("twice as slow: twice the rate", compute_congestion(900, 1800, 10) == 2.0);
    check("21 times slower: capped at 10", compute_congestion(85, 1800, 10) == 10.0);
    check("a cap of 1 or less turns it off", compute_congestion(85, 1800, 1) == 1.0 && compute_congestion(85, 1800, 0) == 1.0);
    check("an unknown scale: none", compute_congestion(0, 1800, 10) == 1.0 && compute_congestion(85, 0, 10) == 1.0);
    check("15 at 2.5 times: 38", compute_congested_rate(15, 2.5) == 38);
    check("15 at 10 times: 150", compute_congested_rate(15, 10) == 150);
    check("the tax off stays off", compute_congested_rate(0, 10) == 0);
    check("a fleet thinking 27 million a day pays all of it", compute_company_congestion(10, 27000000, 5000000) == 10.0);
    check("a lone drone at 0.3 million pays a little of it", fabs(compute_company_congestion(10, 300000, 5000000) - 1.54) < 1e-9);
    check("one that didn't think pays none", compute_company_congestion(10, 0, 5000000) == 1.0);
    check("no congestion: nothing extra for anyone", compute_company_congestion(1, 27000000, 5000000) == 1.0);
    check("full_at 0: everyone pays all of it", compute_company_congestion(10, 1, 0) == 10.0);
    if(failures) { printf("test_compute: %d failed\n", failures); return 1; }
    printf("test_compute: all passed\n");
    return 0;
}
