/*
 * Checks the arithmetic of plunder and taxes (wreck.c, #324 step 12).
 * Run with: make test
 */
#include <stdio.h>

#include "wreck.h"

static int failures = 0;
static void check(const char *what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

int main(void)
{
    check("half of 10 units is 5", wreck_share(10, 50, 7, 1100) == 5);
    check("half of 11 units is 5 or 6", wreck_share(11, 50, 7, 1100) == 5 || wreck_share(11, 50, 7, 1100) == 6);
    check("nothing aboard, nothing taken", wreck_share(0, 50, 7, 1100) == 0);
    check("a share of 0% takes nothing", wreck_share(10, 0, 7, 1100) == 0);
    check("a share of 100% takes all", wreck_share(10, 100, 7, 1100) == 10);
    check("the same wreck and resource always give the same", wreck_share(1, 50, 42, 5001) == wreck_share(1, 50, 42, 5001));

    // A single part comes away whole from about half of the wrecks
    int whole = 0;
    for(int w = 1; w <= 1000; w++)
        whole += (int)wreck_share(1, 50, w, 5001);
    check("a single part comes away from about half of 1,000 wrecks", whole > 400 && whole < 600);

    check("a bill is the flat tax plus thousandths of the credits", tax_bill(20, 5, 10000) == 70);
    check("no credits: just the flat tax", tax_bill(20, 5, 0) == 20);
    check("debt doesn't lower the bill", tax_bill(20, 5, -5000) == 20);

    check("no period has passed yet", tax_periods_due(1000, 900, 86400) == 0);
    check("one period has passed", tax_periods_due(86400 + 900, 900, 86400) == 1);
    check("three periods passed while the engine was down", tax_periods_due(3 * 86400 + 950, 900, 86400) == 3);
    check("no period length, no bills", tax_periods_due(100000, 0, 0) == 0);

    printf("%s\n", failures ? "test_wreck: FAILED" : "test_wreck: all passed");
    return failures ? 1 : 0;
}
