/*
 * Checks the clock floor (clock_floor.c, #324 step 20): the clock slows when
 * the engines fall behind, never below the floor, speeds back up to the
 * target when they catch up, and the world is full only when it is behind
 * at the floor. Run with: make test
 */
#include <math.h>
#include <stdio.h>
#include <stdlib.h>

#include "clock_floor.h"

static int failures = 0;
static void check(const char *what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}
static int near(double a, double b) { return fabs(a - b) < 1e-9; }

int main(void)
{
    ClockRule_t rule = { 24, 8, 60, 15 };
    int full = 0;

    check("keeping up: the clock stays at the target", near(clock_floor_next(24, 5, &rule, &full), 24) && !full);
    check("behind: it slows by a quarter", near(clock_floor_next(24, 90, &rule, &full), 18) && !full);
    check("in between: it holds", near(clock_floor_next(18, 30, &rule, &full), 18) && !full);
    check("never below the floor", near(clock_floor_next(9, 90, &rule, &full), 8) && !full);
    check("behind at the floor: the world is full", near(clock_floor_next(8, 90, &rule, &full), 8) && full);
    check("in between, it stays full", near(clock_floor_next(8, 30, &rule, &full), 8) && full);
    check("caught up: no longer full, speeding up", near(clock_floor_next(8, 5, &rule, &full), 10) && !full);
    check("never above the target", near(clock_floor_next(22, 5, &rule, &full), 24));

    ClockRule_t fixed = { 60, 0, 60, 15 };
    check("no floor: the clock never floats", near(clock_floor_next(60, 1000, &fixed, &full), 60) && !full);
    ClockRule_t odd = { 24, 30, 60, 15 };
    check("a floor above the target: it never floats", near(clock_floor_next(24, 1000, &odd, &full), 24) && !full);
    check("a scale changed by hand goes back to the target", near(clock_floor_next(3600, 5, &fixed, &full), 60));

    check("nothing waiting: no lag", near(clock_floor_lag(0, 100, 60, 0), 0));
    check("600 waiting at 10 a second: 60 s behind", near(clock_floor_lag(600, 600, 60, 0), 60));
    check("waiting and none ran: the whole period", near(clock_floor_lag(5, 0, 60, 0), 60));
    check("a late event counts when it's later", near(clock_floor_lag(600, 600, 60, 120), 120));

    if(failures) {
        printf("test_clock_floor: %d failed\n", failures);
        return 1;
    }
    printf("test_clock_floor: all passed\n");
    return 0;
}
