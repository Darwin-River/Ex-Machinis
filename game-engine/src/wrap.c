/******************************************************************************
 * Splits long lines of player code so the FORTH VM can read them.
 *
 * The VM reads its input one line at a time into an 80-character buffer, so a
 * longer line would be cut mid-word. Lines are re-wrapped at spaces instead,
 * keeping together what must stay on one line:
 *   - strings: ." s" c" abort" up to the closing quote, and .( up to ")"
 *   - words that read the next word, such as : ' char create variable
 * Comments, ( ... ) and \ ..., are dropped from a line that has to be split,
 * since they would otherwise need to stay on one line too.
 *****************************************************************************/

#include <ctype.h>
#include <string.h>
#include <strings.h>

#include "wrap.h"

/* Words that read text up to a delimiter, and the delimiter */
static const struct { const char *word; char end; } parsing_strings[] = {
    { ".\"", '"' }, { "s\"", '"' }, { "c\"", '"' }, { "abort\"", '"' },
    { "$\"", '"' }, { ".(", ')' },
};

/* Words that read the next word: keep the two together */
static const char *parsing_words[] = {
    ":", "'", "[']", "char", "[char]", "postpone", "[compile]", "create",
    "variable", "constant", "value", "to", "is", "defer", "marker", "forget",
    "see", "2constant", "2variable", "user", "vocabulary",
};

#define COUNT(a) (sizeof(a) / sizeof((a)[0]))

static int is_space(char c)
{
    return c == ' ' || c == '\t';
}

static int token_is(const char *start, size_t len, const char *word)
{
    return strlen(word) == len && strncasecmp(start, word, len) == 0;
}

/* Appends a unit to the output, starting a new line when it would not fit */
static int append(char **out, char *out_end, size_t *line_len, size_t max,
                  const char *unit, size_t len)
{
    size_t need = len + (*line_len ? 1 : 0);

    if(*line_len && *line_len + need > max) {
        if(*out + 1 >= out_end) return -1;
        *(*out)++ = '\n';
        *line_len = 0;
        need = len;
    }

    if(*out + need >= out_end) return -1;

    if(*line_len) {
        *(*out)++ = ' ';
    }
    memcpy(*out, unit, len);
    *out += len;
    *line_len += need;
    **out = 0;

    return 0;
}

int forth_wrap_line(const char *line, size_t max, char *out, size_t out_size)
{
    char *out_end = out + out_size;
    size_t line_len = 0;
    const char *p = line;

    if(out_size == 0) return WRAP_NO_SPACE;
    out[0] = 0;

    if(strlen(line) <= max) {
        if(strlen(line) + 1 > out_size) return WRAP_NO_SPACE;
        strcpy(out, line);
        return WRAP_OK;
    }

    while(*p) {
        while(is_space(*p)) p++;
        if(!*p) break;

        const char *start = p;
        while(*p && !is_space(*p)) p++;
        size_t len = p - start;
        const char *end = p; /* end of the unit */

        /* Comments: drop them */
        if(token_is(start, len, "\\")) {
            break;
        }
        if(token_is(start, len, "(")) {
            const char *close = strchr(p, ')');
            p = close ? close + 1 : p + strlen(p);
            continue;
        }

        /* Strings: up to the delimiter */
        int matched = 0;
        for(size_t i = 0; i < COUNT(parsing_strings) && !matched; i++) {
            if(token_is(start, len, parsing_strings[i].word)) {
                const char *close = *p ? strchr(p + 1, parsing_strings[i].end) : NULL;
                end = close ? close + 1 : p + strlen(p);
                matched = 1;
            }
        }

        /* Words that read the next word */
        for(size_t i = 0; i < COUNT(parsing_words) && !matched; i++) {
            if(token_is(start, len, parsing_words[i])) {
                const char *next = p;
                while(is_space(*next)) next++;
                while(*next && !is_space(*next)) next++;
                end = next;
                matched = 1;
            }
        }

        p = end;
        len = end - start;

        if(len > max) return WRAP_TOO_LONG;
        if(append(&out, out_end, &line_len, max, start, len) != 0) return WRAP_NO_SPACE;
    }

    return WRAP_OK;
}
