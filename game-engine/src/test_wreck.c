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
    // Salvage (#929): decay of 2% of what is left a game day
    check("a new wreck is whole", wreck_condition(0) == 1000 && wreck_condition(86399) == 1000);
    check("a day takes 2%", wreck_condition(86400) == 980 && wreck_condition(2 * 86400) == 960);
    check("20 days leave about two thirds", wreck_condition(20 * 86400) >= 660 && wreck_condition(20 * 86400) <= 680);
    check("modules are equipment, kits and goods aren't",
          wreck_equipment(5001) && wreck_equipment(5231) && !wreck_equipment(5901) && !wreck_equipment(2011));
    check("a whole wreck gives whole modules", salvage_whole(7, 5001, 1, 1000));
    check("a rotten one none", !salvage_whole(7, 5001, 1, 0));
    int came_whole = 0;
    for(int w = 1; w <= 1000; w++) came_whole += salvage_whole(w, 5201, 1, 700);
    check("at 70% about 70% come whole", came_whole > 600 && came_whole < 800);
    check("scrap: up to a run, less decay", salvage_scrap(40, 1000, 10) == 10 && salvage_scrap(40, 500, 10) == 5);
    check("scrap: never more than is left", salvage_scrap(3, 1000, 10) == 3 && salvage_scrap(0, 1000, 10) == 0);
    check("a hull holds its mass in scrap, at least 10", hull_scrap(40) == 40 && hull_scrap(2) == 10);
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
