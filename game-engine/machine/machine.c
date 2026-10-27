/*
 * The Machine view (#324 step 19). See machine.h.
 */
#include <string.h>

#include "embed.h"
#include "machine.h"
#include "vm_library.h"
#include "wrap.h"

#define SHADOW 7
#define INPUT_SIZE 16384
#define OUTPUT_SIZE 8192

static m_t core[EMBED_CORE_SIZE];
static embed_t vm;
static char input[INPUT_SIZE];
static char output[OUTPUT_SIZE];
static int output_len;
static long steps_left;
static long count;
static m_t halt_cell;
/* Live data (#380): with live on, a query while stepping pauses the word
   with its id taken off the stack; the page runs it on the game's data,
   writes the rows into memory and answers (machine_query_answer) */
static int live;
static int stepping;
static int pending_query;
static int pending_perform;
#define QUERY_INSTRUCTIONS 1000
#define ACTION_INSTRUCTIONS 1000

/* What a word prints */
static int put_cb(int ch, void *file)
{
    (void)file;
    if(output_len < OUTPUT_SIZE - 1) {
        output[output_len++] = (char)ch;
        output[output_len] = 0;
    }
    return ch;
}

/* Stops the VM once its steps are used (0: no limit) */
static int yield_cb(void *param)
{
    (void)param;
    if(steps_left == 0)
        return 0;
    if(steps_left == 1)
        return 1;
    steps_left--;
    count++;
    return 0;
}

/* The engine's callback words do nothing here: no game around the machine.
   perform leaves -11 (no such protocol) and query -3 (no such query). */
static int callback_cb(embed_t *h, void *param)
{
    (void)param;
    m_t which = 0, ignored = 0;
    embed_pop(h, &which);
    if(which == 0) {            /* perform: protocol -- ior */
        if(live && stepping) {
            /* The page takes the protocol's arguments, as the engine
               does, and leaves -11: nothing acts in the game here */
            m_t id = 0;
            embed_pop(h, &id);
            pending_perform = (int)id ? (int)id : -1;
            count += ACTION_INSTRUCTIONS - 1;
            steps_left = 1;
            return 0;
        }
        embed_pop(h, &ignored);
        embed_push(h, (m_t)-11);
    } else if(which == 1) {     /* query: R L id -- n */
        if(live && stepping) {
            m_t id = 0;
            embed_pop(h, &id);
            pending_query = (int)id ? (int)id : -1;
            count += QUERY_INSTRUCTIONS - 1;   /* as the engine meters it */
            steps_left = 1;                     /* stop after this one */
            return 0;
        }
        embed_pop(h, &ignored);
        embed_pop(h, &ignored);
        embed_pop(h, &ignored);
        embed_push(h, (m_t)-3);
    }
    return 0;
}

static m_t mr(m_t addr) { return vm.o.read(&vm, addr); }

int machine_init(void)
{
    static const char *names[] = { "perform", "query", "report", "dummy" };
    char line[80];

    memset(core, 0, sizeof(core));
    vm.m = core;
    int r = embed_default(&vm);
    if(r < 0)
        return r;
    vm.o.put = put_cb;
    vm.o.yield = yield_cb;
    vm.o.callback = callback_cb;
    vm.o.options |= EMBED_VM_QUITE_ON;
    steps_left = 0;

    /* As vm_add_callbacks (vm.c) does for a drone */
    if((r = embed_eval(&vm, "only forth definitions system +order\n")) < 0)
        return r;
    for(unsigned i = 0; i < sizeof(names) / sizeof(names[0]); i++) {
        strcpy(line, ": ");
        strcat(line, names[i]);
        strcat(line, " ");
        char digit[2] = { (char)('0' + i), 0 };
        strcat(line, digit);
        strcat(line, " vm ; -2 cells allot ' vm chars ,\n");
        if((r = embed_eval(&vm, line)) < 0)
            return r;
    }
    if((r = vm_library_load(&vm)) < 0)
        return r;
    if((r = embed_eval(&vm, vm_library_game_source)) < 0)
        return r;
    embed_reset(&vm);

    /* A cell that branches to itself: where the stepped word returns to */
    if((r = embed_eval(&vm, "create machine-halt 0 ,\n")) < 0)
        return r;
    embed_reset(&vm);
    if((r = embed_eval(&vm, "' machine-halt >body\n")) < 0)
        return r;
    m_t body = 0;
    embed_pop(&vm, &body);
    halt_cell = body >> 1;
    vm.o.write(&vm, halt_cell, halt_cell);   /* branch to itself */
    embed_reset(&vm);
    return 0;
}

int machine_eval(const char *text)
{
    output_len = 0;
    output[0] = 0;
    steps_left = 0;
    int r = embed_eval(&vm, text);
    return r;
}

int machine_prepare(const char *word)
{
    pending_query = 0;
    pending_perform = 0;
    char line[96];
    if(strlen(word) > 64)
        return -13;
    /* The word's execution token, then catch's: it runs inside catch, as
       the engine runs launched, so an error ends it with its code on top
       instead of throwing into the interpreter */
    strcpy(line, "' ");
    strcat(line, word);
    strcat(line, " ' catch\n");
    output_len = 0;
    output[0] = 0;
    steps_left = 0;
    m_t depth_before = (m_t)embed_depth(&vm);
    int r = embed_eval(&vm, line);
    if(r < 0 || (m_t)embed_depth(&vm) != depth_before + 2) {
        embed_reset(&vm);
        return -13;
    }
    m_t xt = 0;
    embed_pop(&vm, &xt);   /* catch's; the word's stays on the stack for it */

    /* Call catch: the return address is the halt cell, then jump to its code */
    m_t rp = mr(2);
    vm.o.write(&vm, --rp, (m_t)(halt_cell << 1));
    vm.o.write(&vm, 2, rp);
    vm.o.write(&vm, 0, (m_t)(xt >> 1));
    count = 0;
    return 0;
}

int machine_step(int n)
{
    if(n <= 0 || machine_done())
        return 0;
    /* One instruction at a time, so the count stops when the word returns
       (at the halt cell, which would otherwise spin) */
    long before = count;
    stepping = 1;
    while(n-- > 0 && !machine_done() && !pending_query && !pending_perform) {
        steps_left = 2;
        embed_vm(&vm);
    }
    stepping = 0;
    steps_left = 0;
    return (int)(count - before);
}

void machine_set_live(int on)  { live = on; }
int machine_query_pending(void) { return pending_query; }
int machine_perform_pending(void) { return pending_perform; }

void machine_query_answer(int code)
{
    pending_query = 0;
    pending_perform = 0;
    embed_push(&vm, (m_t)code);
}

int machine_pop(void)
{
    m_t value = 0;
    embed_pop(&vm, &value);
    return (s_t)value;
}

/* Bytes as the engine reads and writes them (embed_read_byte, embed.c):
   the even byte of a cell is its low half */
int machine_read_byte(int addr)
{
    m_t cell = mr((m_t)(addr >> 1));
    return (addr & 1) ? cell >> 8 : cell & 0xFF;
}

void machine_write_byte(int addr, int value)
{
    m_t cell = mr((m_t)(addr >> 1));
    cell = (addr & 1) ? (m_t)((cell & 0x00FF) | ((value & 0xFF) << 8)) : (m_t)((cell & 0xFF00) | (value & 0xFF));
    vm.o.write(&vm, (m_t)(addr >> 1), cell);
}

int machine_done(void)        { return mr(0) == halt_cell; }
long machine_count(void)      { return count; }
int machine_pc(void)          { return mr(0); }
int machine_cell(int addr)    { return mr((m_t)addr); }
int machine_instruction(void) { return mr(mr(0)); }
int machine_depth(void)       { return (int)(m_t)(mr(3) - mr(3 + SHADOW)); }
int machine_rdepth(void)      { return (int)(m_t)(mr(2 + SHADOW) - mr(2)); }
const char *machine_output(void) { return output; }
char *machine_input(void)     { return input; }

int machine_stack(int i)
{
    int depth = machine_depth();
    if(i < 0 || i >= depth)
        return 0;
    if(i == 0)
        return (s_t)mr(1);                    /* the top is kept in t */
    return (s_t)mr(mr(3) - (i - 1));
}

int machine_rstack(int i)
{
    if(i < 0 || i >= machine_rdepth())
        return 0;
    return mr(mr(2) + i);
}

/* A line as the engine passes it to a drone (#380): over FORTH_MAX_LINE
   characters, re-wrapped at spaces by the engine's own forth_wrap_line, so
   the checker and the Machine read what a drone reads. Writes into the
   input buffer, after the text; returns its address, or 0 when a string
   or word can't be split to fit (the engine refuses the script) */
char *machine_wrap(const char *line)
{
    static char wrapped[INPUT_SIZE];
    int r = forth_wrap_line(line, FORTH_MAX_LINE, wrapped, sizeof(wrapped));
    if(r == WRAP_TOO_LONG)
        return 0;
    if(r != WRAP_OK)
        return (char *)line;
    return wrapped;
}
