/*
 * Checks when the game engine settles events between turns (settle_clock.c,
 * #694 stage 4 step 2): about every 50 real ms, at once after a full batch,
 * and never so often that turns starve. Run with: make test
 */
#include <stdio.h>
#include <stdlib.h>

#include "settle_clock.h"

static int failures = 0;
static void check(const char *what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

int main(void)
{
    SettleClock_t c = { SETTLE_INTERVAL_MS, 100 };

    check("the first pass is due at once", settle_clock_due(&c, 1000, 0) && settle_clock_wait(&c, 1000) == 0);

    // A quick pass with little to do: the next about 50 ms after it started
    settle_clock_done(&c, 1000, 1004, 3);
    check("a quick pass: not due 20 ms later", !settle_clock_due(&c, 1020, 0));
    check("a quick pass: due 50 ms after it started", settle_clock_due(&c, 1050, 0));
    check("resting: wait the rest of the 50 ms", settle_clock_wait(&c, 1020) == 30);
    check("resting: due at 50 ms", settle_clock_wait(&c, 1050) == 0);

    // A full batch: more events are due
    settle_clock_done(&c, 2000, 2010, 100);
    check("a full batch, turns waiting: turns get as long as the pass took", !settle_clock_due(&c, 2005 + 4, 0));
    check("a full batch, turns waiting: due once they have", settle_clock_due(&c, 2020, 0));
    check("a full batch, resting: due at once", settle_clock_due(&c, 2010, 1) && settle_clock_wait(&c, 2010) == 0);

    // A slow pass (an archive window, a busy database): turns get as long
    settle_clock_done(&c, 3000, 3200, 5);
    check("a slow pass: not due 100 ms after it ended, turns waiting", !settle_clock_due(&c, 3300, 0));
    check("a slow pass: due 200 ms after it ended", settle_clock_due(&c, 3400, 0));
    check("a slow pass, resting: due at once (nothing to starve)", settle_clock_due(&c, 3200, 1));

    // A clock that steps back can't make a negative pass
    settle_clock_done(&c, 4000, 3990, 0);
    check("a pass ending before it started takes no time", c.ended_ms == 4000);

    // The flag
    unsetenv("EVENTS_IN_ENGINE");
    check("EVENTS_IN_ENGINE unset: off", !settle_flag_on());
    setenv("EVENTS_IN_ENGINE", "0", 1);
    check("EVENTS_IN_ENGINE=0: off", !settle_flag_on());
    setenv("EVENTS_IN_ENGINE", "1", 1);
    check("EVENTS_IN_ENGINE=1: on", settle_flag_on());
    setenv("EVENTS_IN_ENGINE", "yes", 1);
    check("EVENTS_IN_ENGINE=yes: off (only 1 turns it on)", !settle_flag_on());

    if(failures) {
        printf("%d failed\n", failures);
        return 1;
    }
    printf("settle clock: all passed\n");
    return 0;
}
