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

    check("sales tax: 5% of 1,000 is 50", sales_tax(1000, 50) == 50);
    check("sales tax rounds to the nearest credit", sales_tax(30, 50) == 2 && sales_tax(29, 50) == 1);
    check("a sale of 9 at 5% pays nothing", sales_tax(9, 50) == 0);
    check("no rate, no sales tax", sales_tax(1000, 0) == 0);
    check("nothing paid, no sales tax", sales_tax(0, 50) == 0 && sales_tax(-100, 50) == 0);

    printf("%s\n", failures ? "test_wreck: FAILED" : "test_wreck: all passed");
    return failures ? 1 : 0;
}
