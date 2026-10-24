/*
 * Crash handling for launched drones (#324 step 5). See crash.h.
 */
#include "crash.h"

int crash_launched_result(int vm_result, size_t depth, int top)
{
    if(vm_result < 0)
        return vm_result;               // the VM halted (abort, a fault)
    if(depth == 0)
        return CRASH_STACK_UNDERFLOW;   // catch's code was lost
    return top;                          // catch's code: 0 = returned normally
}

CrashStep_t crash_next_step(int in_row, int limit, long long base_wait, long long* wait)
{
    if(wait)
        *wait = 0;
    if(limit > 0 && in_row >= limit)
        return CRASH_WRECK;

    // base_wait, 2 x base_wait, 4 x base_wait... (capped so it can't overflow)
    int doublings = in_row > 1 ? in_row - 1 : 0;
    if(doublings > 30)
        doublings = 30;
    if(wait)
        *wait = (base_wait > 0 ? base_wait : 0) << doublings;
    return CRASH_RESTART;
}
