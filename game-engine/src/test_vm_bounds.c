/*
 * Checks that FORTH VM code can never read or write outside the VM's core
 * memory (issue #322). The core is given a guard area after its last cell;
 * hand-made programs that push past the top of memory, or call with the
 * return stack at zero, and many random programs must all leave the guard
 * untouched. Run with: make test
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "embed.h"
#include "embed_util.h"
#include "trace.h"

void engine_trace(TraceLevel_t level, const char *trace, ...)
{
    (void)level;
    (void)trace;
}

#define GUARD_CELLS 32768u        /* a 16-bit address can reach 65535 */
#define GUARD_VALUE 0xA5A5u
#define MAX_STEPS   20000         /* instructions per program */

static int failures = 0;

static void check(const char *what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

static int put_cb(int ch, void *file)   { (void)file; return ch; }
static int get_cb(void *file, int *nd)  { (void)file; *nd = 0; return -1; }

static int yield_cb(void *param)
{
    long *steps = (long*)param;
    return ++(*steps) > MAX_STEPS;
}

static cell_t *mem;          /* core plus guard area */
static embed_t *vm;
static long steps;

static void reset_vm(void)
{
    memset(mem, 0, EMBED_CORE_SIZE * sizeof(cell_t));
    for(size_t i = EMBED_CORE_SIZE; i < EMBED_CORE_SIZE + GUARD_CELLS; i++)
        mem[i] = GUARD_VALUE;

    embed_opt_t o = embed_opt_default();
    o.put = put_cb;
    o.get = get_cb;
    o.save = NULL;           /* no image saving to disk */
    o.callback = NULL;
    o.yield = yield_cb;
    o.yields = &steps;
    embed_opt_set(vm, &o);
    steps = 0;
}

static int guard_intact(void)
{
    for(size_t i = EMBED_CORE_SIZE; i < EMBED_CORE_SIZE + GUARD_CELLS; i++)
        if(mem[i] != GUARD_VALUE)
            return 0;
    return 1;
}

/* Registers: cell 0 pc, 1 top of stack, 2 return stack, 3 data stack;
   cell 5 is the core size in cells the VM checks against */
static void set_registers(cell_t pc, cell_t rp, cell_t sp)
{
    mem[0] = pc;
    mem[1] = 0;
    mem[2] = rp;
    mem[3] = sp;
    mem[5] = (cell_t)EMBED_CORE_SIZE;
}

int main(void)
{
    vm = embed_new();
    if(!vm) {
        printf("FAIL unable to create a VM\n");
        return 1;
    }
    void *own_core = vm->m;
    mem = calloc(EMBED_CORE_SIZE + GUARD_CELLS, sizeof(cell_t));
    vm->m = mem;

    /* A call with the return stack at zero: the return address used to be
       written to cell 0xFFFF */
    reset_vm();
    set_registers(64, 0, 100);
    mem[64] = 0x4000 | 64;              /* call 64, forever */
    embed_vm(vm);
    check("call with an empty return stack stays in memory", guard_intact());

    /* Pushing literals with the data stack at the top of memory: the push
       used to land one cell past the end */
    reset_vm();
    set_registers(64, 200, EMBED_CORE_SIZE - 1);
    for(int i = 0; i < 8; i++)
        mem[64 + i] = 0x8000 | i;       /* literal i */
    embed_vm(vm);
    check("literal pushes at the top of memory stay in memory", guard_intact());

    /* Setting the stack pointers from the stack, then storing through them */
    reset_vm();
    set_registers(64, 200, 100);
    mem[64] = 0x8000 | 1;               /* literal 1 */
    mem[65] = 0x6000 | (10 << 8);       /* invert: t = fffe */
    mem[66] = 0x6000 | (20 << 8) | 0x0080 | 0x0001; /* sp = 7fff, sp+1, store t at [sp] */
    embed_vm(vm);
    check("a store after moving the data stack pointer stays in memory", guard_intact());

    reset_vm();
    set_registers(64, 200, 100);
    mem[64] = 0x8000 | 1;               /* literal 1 */
    mem[65] = 0x6000 | (10 << 8);       /* invert: t = fffe */
    mem[66] = 0x6000 | (21 << 8) | 0x0040 | 0x000C; /* rp = 7fff, rp+1, store t at [rp] */
    embed_vm(vm);
    check("a store after moving the return stack pointer stays in memory", guard_intact());

    /* Random programs: any instruction mix, any starting registers */
    srand(322);
    int random_ok = 1;
    for(int run = 0; run < 300 && random_ok; run++) {
        reset_vm();
        for(size_t i = 0; i < EMBED_CORE_SIZE; i++)
            mem[i] = (cell_t)((rand() << 8) ^ rand());
        mem[5] = (cell_t)EMBED_CORE_SIZE;
        embed_vm(vm);
        random_ok = guard_intact();
    }
    check("300 random programs stay in memory", random_ok);

    vm->m = own_core;
    embed_free(vm);
    free(mem);

    if(failures) {
        printf("%d check(s) failed\n", failures);
        return 1;
    }
    printf("All VM memory checks passed\n");
    return 0;
}
