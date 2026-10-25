/*
 * Prints a step-by-step trace of words run on the machine (#324 step 19):
 * the native build of machine.c. The website's WebAssembly build must give
 * the same trace (test/compare.mjs); CI compares them.
 * Usage: trace_machine  (reads cases from cases.txt in the current folder)
 */
#include <stdio.h>
#include <string.h>

#include "machine.h"

void engine_trace(int level, const char *trace, ...) { (void)level; (void)trace; }

static void trace_case(const char *setup, const char *word)
{
    machine_init();
    machine_eval(setup);
    int r = machine_prepare(word);
    printf("case %s: prepare %d\n", word, r);
    if(r < 0)
        return;
    for(int i = 0; i < 100000 && !machine_done(); i++) {
        printf("%ld pc=%04x ins=%04x d=%d", machine_count(), machine_pc(), machine_instruction(), machine_depth());
        for(int s = 0; s < machine_depth() && s < 4; s++)
            printf(" %d", machine_stack(s));
        printf(" r=%d\n", machine_rdepth());
        machine_step(1);
    }
    printf("done %d count %ld depth %d top %d output [%s]\n", machine_done(), machine_count(), machine_depth(),
           machine_depth() ? machine_stack(0) : 0, machine_output());

    /* The same word in one run, as the page's Run button does it */
    machine_init();
    machine_eval(setup);
    machine_prepare(word);
    int ran = machine_step(1000000);
    printf("run %d count %ld done %d\n", ran, machine_count(), machine_done());
}

int main(void)
{
    static char setup[8192], word[128], line[8192];
    FILE *f = fopen("cases.txt", "r");
    if(!f) { printf("no cases.txt\n"); return 1; }
    /* Each case: a line "word", then setup lines up to a line "--" */
    while(fgets(word, sizeof word, f)) {
        word[strcspn(word, "\n")] = 0;
        if(!word[0]) continue;
        setup[0] = 0;
        while(fgets(line, sizeof line, f) && strcmp(line, "--\n") != 0)
            strcat(setup, line);
        trace_case(setup, word);
    }
    fclose(f);
    return 0;
}
