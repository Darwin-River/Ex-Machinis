/*
 * Checks the processor tax's arithmetic (#544)
 */
#include <stdio.h>
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
    if(failures) { printf("test_compute: %d failed\n", failures); return 1; }
    printf("test_compute: all passed\n");
    return 0;
}
