#ifndef __EM_VM_METER_MODULE__
#define __EM_VM_METER_MODULE__

/******************************************************************************
 * Instruction metering (#324 step 3). The VM calls vm_meter_step() before
 * every instruction it runs. A slice of a drone's script ends when it has
 * used its instruction budget, so the same script always runs the same
 * instructions per slice, whatever the server's speed. Queries and actions
 * cost a flat number of instructions (vm_meter_charge), since their real
 * cost is database work. The count is what power (fuel) will be charged on.
 *****************************************************************************/

/** Starts a new slice: zero used, and a budget (0 means no limit, for the
 *  engine's own set-up code) */
void vm_meter_start(long long budget);

/** Counts one instruction; returns 1 once the budget is used up */
int vm_meter_step(void);

/** Adds a flat cost (a query or an action) */
void vm_meter_charge(long long instructions);

/** Instructions (and flat costs) used in this slice */
long long vm_meter_used(void);

/** 1 when this slice has used its whole budget */
int vm_meter_exhausted(void);

/** Power (#324 step 8): whole units of fuel owed for a drone's instructions,
    given how many are already paid for and the instructions per fuel unit.
    The rest carries over to the next charge. */
long long vm_meter_fuel_due(long long instructions, long long paid, long long per_fuel);

/** A slice's budget (#380 round 33): a drone still loading its released
    script may use what is left of load_limit, all in one slice (but never
    less than a normal slice); any other slice gets the normal budget. */
long long vm_meter_slice_budget(int loading, long long used_so_far, long long load_limit, long long slice);

/** What a Lua drone's count hook does (#508). With its slice used up it
    pauses, unless it is inside a function called from C (a table.sort
    comparator, a string.gsub replacement), where Lua can't pause: then it
    runs on, and pauses once it is back in its own code. `unpausable` is
    how much it has run on past its slice; past `cap` it is stopped, so a
    loop inside a comparator can't hold the engine. */
#define VM_HOOK_GO        0   /* carry on */
#define VM_HOOK_PAUSE     1   /* pause until its next turn */
#define VM_HOOK_TOO_LONG  2   /* ran on too long where it can't pause: an error */
int vm_meter_hook(int exhausted, int can_pause, long long unpausable, long long cap);

#endif
