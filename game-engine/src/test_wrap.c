/*
 * Checks forth_wrap_line (wrap.c): long lines of player code are split at
 * spaces so the FORTH VM, which reads 80 characters per line, runs them as
 * written. The VM checks run the engine's own FORTH image. Run with: make test
 */
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "embed.h"
#include "embed_util.h"
#include "wrap.h"
#include "trace.h"

void engine_trace(TraceLevel_t level, const char *trace, ...)
{
    (void)level;
    (void)trace;
}

static int failures = 0;

static void check(const char *what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

/* Every output line fits */
static int lines_fit(const char *text, size_t max)
{
    size_t len = 0;
    for(; *text; text++) {
        len = (*text == '\n') ? 0 : len + 1;
        if(len > max) return 0;
    }
    return 1;
}

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

/* Runs code (lines separated by '\n') on a fresh VM and returns its output */
static const char *run(const char *code)
{
    static char buf[8192];
    embed_t *h = embed_new();
    if(!h || embed_default(h) != 0) {
        printf("FAIL unable to create a VM\n");
        exit(1);
    }
    embed_opt_t o = *embed_opt_get(h);
    o.put = put_cb;
    o.options |= EMBED_VM_QUITE_ON;
    embed_opt_set(h, &o);

    out_len = 0;
    out_text[0] = 0;
    snprintf(buf, sizeof(buf), "%s\n", code);
    embed_eval(h, buf);
    embed_free(h);
    return out_text;
}

/* Wraps each line of code like the engine does */
static const char *wrap(const char *line)
{
    static char buf[8192];
    if(forth_wrap_line(line, FORTH_MAX_LINE, buf, sizeof(buf)) != WRAP_OK)
        return "";
    return buf;
}

int main(void)
{
    char out[4096];
    char sum[1024] = "0";
    char words[1024] = "";

    /* A long sum: 0 100 + 100 + ... (30 times) . => 3000 */
    for(int i = 0; i < 30; i++) strcat(sum, " 100 +");
    strcat(sum, " .");

    check("short lines are left alone",
          forth_wrap_line("2 3 + .", FORTH_MAX_LINE, out, sizeof(out)) == WRAP_OK && strcmp(out, "2 3 + .") == 0);

    check("long lines are split into lines that fit",
          forth_wrap_line(sum, FORTH_MAX_LINE, out, sizeof(out)) == WRAP_OK && strchr(out, '\n') && lines_fit(out, FORTH_MAX_LINE));

    /* Strings stay whole */
    snprintf(words, sizeof(words), "%s .\" a string that must stay on one line\" cr", sum);
    check("strings stay on one line",
          forth_wrap_line(words, FORTH_MAX_LINE, out, sizeof(out)) == WRAP_OK && strstr(out, ".\" a string that must stay on one line\"") && lines_fit(out, FORTH_MAX_LINE));

    /* Defining words stay with their name */
    snprintf(words, sizeof(words), "%s : double 2 * ; 4 double .", sum);
    check("a defining word stays with its name",
          forth_wrap_line(words, FORTH_MAX_LINE, out, sizeof(out)) == WRAP_OK && strstr(out, ": double"));

    /* Comments are dropped from long lines */
    snprintf(words, sizeof(words), "%s ( a comment ) \\ and a line comment that goes on and on", sum);
    check("comments are dropped from long lines",
          forth_wrap_line(words, FORTH_MAX_LINE, out, sizeof(out)) == WRAP_OK && !strstr(out, "comment"));

    /* A string longer than a line can't be split */
    snprintf(words, sizeof(words), ".\" %s\"", "this string is far too long to fit on one line of the FORTH input buffer at all");
    check("a string longer than a line is refused",
          forth_wrap_line(words, FORTH_MAX_LINE, out, sizeof(out)) == WRAP_TOO_LONG);

    /* The real VM: the long line breaks unwrapped, and works wrapped */
    check("the VM runs a short line", strstr(run("2 3 + ."), "5") != NULL);
    check("unwrapped, the VM gets a long line wrong", strstr(run(sum), "3000") == NULL);
    check("wrapped, the VM gets the long line right", strstr(run(wrap(sum)), "3000") != NULL);

    snprintf(words, sizeof(words), "%s : double 2 * ; 4 double . : hi .\" all done\" ; hi .( printed too)", sum);
    const char *result = run(wrap(words));
    check("wrapped, definitions and strings work",
          strstr(result, "3000") && strstr(result, "8") && strstr(result, "all done") && strstr(result, "printed too") && !strchr(result, '?'));

    if(failures) {
        printf("%d check(s) failed\n", failures);
        return 1;
    }
    printf("All wrap checks passed\n");
    return 0;
}
