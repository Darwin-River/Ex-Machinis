/******************************************************************************
 * Instruction metering: see vm_meter.h
 *****************************************************************************/
#include "vm_meter.h"

static long long g_budget = 0;
static long long g_used = 0;

void vm_meter_start(long long budget)
{
    g_budget = budget > 0 ? budget : 0;
    g_used = 0;
}

int vm_meter_step(void)
{
    g_used++;
    return vm_meter_exhausted();
}

void vm_meter_charge(long long instructions)
{
    if(instructions > 0)
        g_used += instructions;
}

long long vm_meter_used(void)
{
    return g_used;
}

int vm_meter_exhausted(void)
{
    return g_budget > 0 && g_used >= g_budget;
}

long long vm_meter_fuel_due(long long instructions, long long paid, long long per_fuel)
{
    if(per_fuel <= 0 || instructions <= paid)
        return 0;
    return (instructions - paid) / per_fuel;
}

int vm_meter_hook(int exhausted, int can_pause, long long unpausable, long long cap)
{
    if(!exhausted)
        return VM_HOOK_GO;
    if(can_pause)
        return VM_HOOK_PAUSE;
    return unpausable > cap ? VM_HOOK_TOO_LONG : VM_HOOK_GO;
}

long long vm_meter_slice_budget(int loading, long long used_so_far, long long load_limit, long long slice)
{
    if(!loading || load_limit <= used_so_far)
        return slice;
    long long left = load_limit - used_so_far;
    return left > slice ? left : slice;
}
