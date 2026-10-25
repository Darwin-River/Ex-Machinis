/*
 * Checks the clearing arithmetic of House drop stations (station.c, #324
 * step 10): one price per batch from the published curve, everyone filled
 * when there is enough, pro rata shares with a cap per company when there
 * isn't. Run with: make test
 */
#include <stdio.h>

#include "station.h"

static int failures = 0;
static void check(const char *what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

int main(void)
{
    long long fill[8];

    check("a batch open at 10:20 ends at 11:00", station_batch_end(10 * 3600 + 1200) == 11 * 3600);
    check("a batch that starts on the hour ends an hour later", station_batch_end(7200) == 10800);

    check("an empty batch gets the base price", station_buy_price(10, 0, 200) == 10);
    check("the price halves when a batch offers the daily demand", station_buy_price(10, 200, 200) == 5);
    check("and halves again at twice the daily demand", station_buy_price(100, 400, 200) == 25);
    check("half the daily demand: base / sqrt 2", station_buy_price(100, 100, 200) == 71);
    check("the price never falls below 1", station_buy_price(10, 100000, 200) == 1);

    {   /* enough for everyone */
        long long u[] = {10, 20, 5}; int c[] = {1, 2, 3};
        long long f = station_share(u, c, 3, 100, fill);
        check("with enough, everyone gets all they asked for", f == 35 && fill[0] == 10 && fill[1] == 20 && fill[2] == 5);
    }
    {   /* short: pro rata */
        long long u[] = {30, 30, 40}; int c[] = {1, 2, 3};
        long long f = station_share(u, c, 3, 50, fill);
        check("when short, shares follow what each offered", fill[0] == 15 && fill[1] == 15 && fill[2] == 20 && f == 50);
    }
    {   /* short with a dominant company: capped at half */
        long long u[] = {90, 10}; int c[] = {1, 2};
        long long f = station_share(u, c, 2, 40, fill);
        check("no company gets more than half of a short batch; the rest goes to the others",
              fill[0] == 20 && fill[1] == 10 && f == 30);
    }
    {   /* one company split over two drones still counts once */
        long long u[] = {50, 50, 20}; int c[] = {1, 1, 2};
        station_share(u, c, 3, 60, fill);
        check("a company's drones share its cap; the other company gets all it asked for",
              fill[0] + fill[1] == 30 && fill[2] == 20);
    }
    {   /* nothing available */
        long long u[] = {5}; int c[] = {1};
        check("an empty budget fills nothing", station_share(u, c, 1, 0, fill) == 0 && fill[0] == 0);
    }

    if(failures) {
        printf("%d check(s) failed\n", failures);
        return 1;
    }
    printf("All drop station checks passed\n");
    return 0;
}
