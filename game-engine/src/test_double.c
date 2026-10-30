/*
 * Checks the double-cell words of the standard word library (vm_library.c,
 * #324 step 9) on the engine's own FORTH image: sums, differences, products
 * and comparisons give the right answers past 32,767 and 65,535, where single
 * cells wrap. Run with: make test
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "embed.h"
#include "embed_util.h"
#include "trace.h"
#include "vm_library.h"

void engine_trace(TraceLevel_t level, const char *trace, ...)
{
    (void)level;
    (void)trace;
}

static int failures = 0;
static char out_text[4096];
static size_t out_len;

static int put_cb(int ch, void *file)
{
    (void)file;
    if(out_len + 1 < sizeof(out_text)) {
        out_text[out_len++] = (char)ch;
        out_text[out_len] = 0;
    }
    return ch;
}

/* Runs code on a fresh VM with the library loaded; returns what it printed
   (. prints a space before the number, d. a space after it) */
static const char *run(const char *code)
{
    static char buf[1024];
    embed_t *h = embed_new();
    if(!h || embed_default(h) != 0) {
        printf("FAIL unable to create a VM\n");
        exit(1);
    }
    embed_opt_t o = *embed_opt_get(h);
    o.put = put_cb;
    o.options |= EMBED_VM_QUITE_ON;
    embed_opt_set(h, &o);

    embed_eval(h, "only forth definitions system +order\n");
    vm_library_load(h);
    /* The game words, with stand-ins for the engine's callbacks */
    embed_eval(h, ": perform ( id -- ior ) drop -11 ; : query ( a n id -- r ) drop 2drop -3 ;\n"
                  ": report ( -- ) ; : dummy ( -- ) ;\n");
    vm_library_load_game(h);
    embed_eval(h, "only forth definitions\n");   /* as players see it */
    out_len = 0;
    out_text[0] = 0;
    snprintf(buf, sizeof(buf), "%s\n", code);
    embed_eval(h, buf);
    embed_free(h);
    return out_text;
}

static void check(const char *what, const char *code, const char *expected)
{
    const char *got = run(code);
    int ok = strcmp(got, expected) == 0;
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) {
        printf("     %s -> [%s], expected [%s]\n", code, got, expected);
        failures++;
    }
}

int main(void)
{
    /* 32767 + 1 wraps in one cell; as doubles it doesn't */
    check("a single cell wraps past 32,767", "32767 1 + .", " -32768");
    check("d+ carries past 32,767", "32767 s>d 1 s>d d+ d.", "32768 ");
    check("d+ carries past 65,535", "65535 0 1 0 d+ d.", "65536 ");
    check("d+ adds large balances", "40000 0 40000 0 d+ d.", "80000 ");
    check("d- borrows across cells", "0 1 1 0 d- d.", "65535 ");
    check("d- goes negative", "1 0 2 0 d- d.", "-1 ");
    check("d- goes negative across cells", "0 0 0 1 d- d.", "-65536 ");
    check("dnegate and dabs", "100 0 dnegate dup 0< . dabs d.", " -1100 ");
    check("m* gives a double product (overflow-safe)", "300 300 m* d.", "90000 ");
    check("m* keeps the sign", "-300 300 m* d.", "-90000 ");
    check("um* multiplies unsigned cells", "40000 3 um* ud.", "120000 ");
    check("m+ adds a single to a double", "65535 0 1 m+ d.", "65536 ");
    check("d< compares past 65,535", "1 1 2 0 d< . 2 0 1 1 d< .", " 0 -1");
    check("d< compares signs", "-1 -1 1 0 d< .", " -1");
    check("du< compares unsigned", "-1 -1 1 0 du< .", " 0");
    check("d= and d0=", "5 1 5 1 d= . 0 0 d0= .", " -1 -1");
    check("dmax and dmin", "1 1 2 0 dmax d. 1 1 2 0 dmin d.", "65537 2 ");
    check("2swap and 2over", "1 2 3 4 2swap . . . . 1 2 3 4 2over . . . . . .", " 2 1 4 3 2 1 4 3 2 1");
    check("2@ reads a double stored high cell first", "variable v 0 , 1 v ! 2 v cell+ ! v 2@ d.", "65538 ");
    check("a balance past 32,767 adds up", "20000 0 20000 0 d+ 20000 0 d+ d.", "60000 ");

    /* The VM reads a line of at most 80 characters: a longer library line
       is cut short without an error (#380: ?buy-order went missing) */
    {
        const char *sources[] = { vm_library_source, vm_library_game_source };
        int longest = 0;
        for(int i = 0; i < 2; i++) for(const char *line = sources[i]; *line; ) {
            const char *end = strchr(line, '\n');
            int n = end ? (int)(end - line) : (int)strlen(line);
            if(n > longest) longest = n;
            line += n + (end ? 1 : 0);
        }
        int ok = longest < 80;
        printf("%s every word library line fits the VM's 80 columns (longest %d)\n", ok ? "ok  " : "FAIL", longest);
        if(!ok) failures++;
    }

    /* Orders the library remembers (?sell-order, ?buy-order) */
    check("order-note and order-same?",
        "50 10 2011 order-note 60 5 2011 negate order-note 52 10 2011 order-note\n"
        "52 10 2011 order-same? . 50 10 2011 order-same? .\n"
        "60 5 2011 negate order-same? . 60 5 2011 order-same? .", " -1 0 -1 0");

    if(failures) {
        printf("%d check(s) failed\n", failures);
        return 1;
    }
    printf("All double-cell checks passed\n");
    return 0;
}
