/*
 * Crash handling for launched drones (#324 step 5): what a finished run of
 * "' launched catch" means, and what happens after a crash. No database.
 */
#ifndef __CRASH_H__
#define __CRASH_H__

#include <stddef.h>

/* The error code when launched leaves catch nothing to report (it emptied
   the stack below catch's frame): FORTH's "stack underflow" */
#define CRASH_STACK_UNDERFLOW (-4)

typedef enum
{
    CRASH_RESTART = 0,   // restart from the clean copy after a wait
    CRASH_WRECK          // too many crashes in a row: the drone is a wreck
} CrashStep_t;

/* The result of a run of "' launched catch" that has ended: 0 when launched
   returned normally, else the error code. vm_result is what the VM returned
   (negative when it halted, e.g. on abort or a fault), depth the data stack
   depth and top its top cell (catch's code). */
int crash_launched_result(int vm_result, size_t depth, int top);

/* What to do after crash number in_row in a row (1 for the first): restart
   after *wait game seconds (base_wait, doubling each time), or wreck once
   in_row reaches limit. */
CrashStep_t crash_next_step(int in_row, int limit, long long base_wait, long long* wait);

#endif
