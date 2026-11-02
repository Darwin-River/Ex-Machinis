/* Times score.fth's run on the engine's FORTH VM (the Machine page's
   native build): instructions, and nanoseconds an instruction (#380) */
#define _POSIX_C_SOURCE 200809L
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include "machine.h"
void engine_trace(int level, const char *trace, ...) { (void)level; (void)trace; }
static double secs(void) { struct timespec t; clock_gettime(CLOCK_MONOTONIC, &t); return t.tv_sec + t.tv_nsec / 1e9; }
int main(void)
{
    FILE *f = fopen("score.fth", "r");
    char line[256];
    machine_init();
    while(fgets(line, sizeof line, f))
        if(machine_eval(line) < 0) { fprintf(stderr, "failed: %s", line); return 1; }
    fclose(f);
    if(machine_prepare("run") < 0) { fprintf(stderr, "no run\n"); return 1; }
    double t0 = secs();
    while(!machine_done())
        machine_step(1000000);
    double t = secs() - t0;
    printf("forth: %ld instructions for 1,000 rounds of scoring 8 products (%ld a round), %.1f ms, %.1f M instructions a second (stepped one at a time, as the Machine page does; the engine runs about 80 M a second)\n",
           machine_count(), machine_count() / 1000, t * 1000, machine_count() / t / 1e6);
    return 0;
}
