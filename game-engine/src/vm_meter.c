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
