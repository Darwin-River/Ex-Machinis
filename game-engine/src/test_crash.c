/*
 * Checks the launched entry point and crash handling (crash.c, #324 step 5)
 * on the engine's own FORTH image: the engine runs "' launched catch", so a
 * launched word that returns normally reports 0, one that crashes reports its
 * error code (also after pausing across many instruction slices), and one
 * that loops forever just keeps using slices. Then the restart policy: waits
 * double, and too many crashes in a row make a wreck. Run with: make test
 */
#include <stdio.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>

#include "crash.h"
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
static int yield_cb(void *param) { (void)param; return vm_meter_step(); }

/* Defines words on a fresh VM, then runs "' launched catch" the way the
   engine does: in slices of budget instructions (0 = no limit), resuming
   until it ends or max_slices have run. Returns the launched result, or
   1000 when it was still running. */
static int run_launched(const char *defs, long long budget, int max_slices, int *slices)
{
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

    vm_meter_start(0);
    embed_eval(h, defs);

    const char *input = "' launched catch\n";
    int r = 0, n = 0;
    bool paused;
    do {
        vm_meter_start(budget);
        embed_opt_t old = *embed_opt_get(h), with_input = old;
        with_input.get = embed_sgetc_cb;
        with_input.in = (void*)&input;
        embed_opt_set(h, &with_input);
        r = embed_vm(h);
        embed_opt_set(h, &old);
        n++;
        paused = budget > 0 && vm_meter_used() >= budget;
    } while(paused && n < max_slices);

    if(slices) *slices = n;
    int result = 1000;
    if(!paused) {
        size_t depth = embed_depth(h);
        m_t top = 0;
        if(depth) embed_pop(h, &top);
        result = crash_launched_result(r, depth, (short)top);
    }
    embed_free(h);
    return result;
}

int main(void)
{
    int slices = 0;
    const char *spin = ": spin 0 begin 1+ dup 5000 = until drop ; ";
    char defs[256];

    check("a launched word that returns reports 0",
          run_launched(": launched 1 2 + drop ;\n", 0, 1, NULL) == 0);
    check("dividing by zero is caught (-10)",
          run_launched(": launched 1 0 / ;\n", 0, 1, NULL) == -10);
    check("a throw is caught with its code",
          run_launched(": launched -77 throw ;\n", 0, 1, NULL) == -77);
    check("abort counts as a crash",
          run_launched(": launched abort ;\n", 0, 1, NULL) < 0);
    check("emptying the stack under catch is a stack underflow",
          run_launched(": launched drop drop drop ;\n", 0, 1, NULL) == CRASH_STACK_UNDERFLOW);
    check("values left on the stack don't hide a normal return",
          run_launched(": launched 1 2 ;\n", 0, 1, NULL) == 0);

    snprintf(defs, sizeof(defs), "%s: launched spin 7 throw ;\n", spin);
    check("a crash after pausing across slices is still caught",
          run_launched(defs, 500, 1000, &slices) == 7 && slices > 10);
    snprintf(defs, sizeof(defs), "%s: launched spin ;\n", spin);
    check("a normal return after pausing across slices reports 0",
          run_launched(defs, 500, 1000, &slices) == 0 && slices > 10);
    check("a launched word that loops forever keeps running, slice after slice",
          run_launched(": launched begin 0 until ;\n", 500, 50, &slices) == 1000 && slices == 50);

    long long wait = 0;
    check("the first crash restarts after the base wait",
          crash_next_step(1, 5, 3600, &wait) == CRASH_RESTART && wait == 3600);
    check("the wait doubles with each crash in a row",
          crash_next_step(2, 5, 3600, &wait) == CRASH_RESTART && wait == 7200 &&
          crash_next_step(4, 5, 3600, &wait) == CRASH_RESTART && wait == 28800);
    check("the limit-th crash in a row makes a wreck",
          crash_next_step(5, 5, 3600, &wait) == CRASH_WRECK && wait == 0);
    check("a limit of 0 never wrecks, and long waits don't overflow",
          crash_next_step(100, 0, 3600, &wait) == CRASH_RESTART && wait == (3600LL << 30));

    if(failures) {
        printf("%d check(s) failed\n", failures);
        return 1;
    }
    printf("All crash handling checks passed\n");
    return 0;
}
