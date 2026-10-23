#ifndef __EM_WRAP_MODULE__
#define __EM_WRAP_MODULE__

#include <stddef.h>

/* Longest line the FORTH VM reads in one go (its input buffer holds 80) */
#define FORTH_MAX_LINE 79

#define WRAP_OK        0
#define WRAP_TOO_LONG -1 /* a string or word can't be split to fit */
#define WRAP_NO_SPACE -2 /* output buffer too small */

/**
 * Re-wraps one line of player code (no newline) into lines of at most max
 * characters, separated by '\n', splitting only between words.
 * Lines that already fit are copied unchanged.
 */
int forth_wrap_line(const char *line, size_t max, char *out, size_t out_size);

#endif
