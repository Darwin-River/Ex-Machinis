/*
 * Checks instruction metering (vm_meter.c, #324 step 3) on the engine's own
 * FORTH image: the same script always uses exactly the same number of
 * instructions, a slice stops exactly when its budget is used, flat costs
 * add up, and a budget of 0 means no limit. Run with: make test
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "embed.h"
#include "embed_util.h"
#include "trace.h"
#include "vm_meter.h"

void engine_trace(TraceLevel_t level, const char *trace, ...)
{
    (void)level;
    (void)trace;
}

static int failures = 0;

static void check(const char *what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

static int put_cb(int ch, void *file) { (void)file; return ch; }

/* The engine's yield callback, as far as metering goes */
static int yield_cb(void *param) { (void)param; return vm_meter_step(); }

/* Runs code on a fresh VM with the given budget; returns instructions used */
static long long run(const char *code, long long budget)
{
    static char buf[4096];
    embed_t *h = embed_new();
    if(!h || embed_default(h) != 0) {
        printf("FAIL unable to create a VM\n");
        exit(1);
    }
    embed_opt_t o = *embed_opt_get(h);
    o.put = put_cb;
    o.options |= EMBED_VM_QUITE_ON;
    o.yield = yield_cb;
    embed_opt_set(h, &o);

    snprintf(buf, sizeof(buf), "%s\n", code);
    vm_meter_start(budget);
    embed_eval(h, buf);
    long long used = vm_meter_used();
    embed_free(h);
    return used;
}

int main(void)
{
    const char *loop = ": spin 0 begin 1+ dup 5000 = until drop ; spin";

    long long first = run(loop, 0);
    long long second = run(loop, 0);
    check("a script uses some instructions", first > 5000);
    check("the same script uses exactly the same instructions every time", first == second);
    check("a longer loop uses more", run(": spin 0 begin 1+ dup 6000 = until drop ; spin", 0) > first);

    long long budget = first / 2;
    check("a slice stops exactly when its budget is used", run(loop, budget) == budget);
    check("and its exhausted flag is set", vm_meter_exhausted());

    vm_meter_start(0);
    check("a budget of 0 is never exhausted", !vm_meter_step() && !vm_meter_exhausted());

    vm_meter_start(10000);
    vm_meter_charge(1000);
    vm_meter_charge(1000);
    check("flat costs add to the count", vm_meter_used() == 2000);
    vm_meter_charge(8000);
    check("flat costs count against the budget", vm_meter_exhausted());

    /* Power (#324 step 8): whole fuel units owed, the rest carries over */
    check("no fuel is owed below one unit's worth of instructions",
          vm_meter_fuel_due(99999, 0, 100000) == 0);
    check("one unit is owed at 100,000 instructions",
          vm_meter_fuel_due(100000, 0, 100000) == 1);
    check("only whole units are charged; the rest waits for the next charge",
          vm_meter_fuel_due(350000, 100000, 100000) == 2);
    check("nothing is owed when everything is paid",
          vm_meter_fuel_due(500000, 500000, 100000) == 0);
    check("a price of 0 makes computing free",
          vm_meter_fuel_due(9999999, 0, 0) == 0);

    if(failures) {
        printf("%d check(s) failed\n", failures);
        return 1;
    }
    printf("All instruction metering checks passed\n");
    return 0;
}
