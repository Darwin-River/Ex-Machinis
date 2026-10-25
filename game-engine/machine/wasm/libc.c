/* WebAssembly build of the machine (#324 step 19): the string functions the
   VM uses, as there is no C library */
#include <string.h>

void *memcpy(void *d, const void *s, size_t n)
{
    unsigned char *dp = d;
    const unsigned char *sp = s;
    while(n--) *dp++ = *sp++;
    return d;
}

void *memset(void *d, int c, size_t n)
{
    unsigned char *dp = d;
    while(n--) *dp++ = (unsigned char)c;
    return d;
}

size_t strlen(const char *s)
{
    size_t n = 0;
    while(s[n]) n++;
    return n;
}

char *strcpy(char *d, const char *s)
{
    char *r = d;
    while((*d++ = *s++)) {}
    return r;
}

char *strcat(char *d, const char *s)
{
    strcpy(d + strlen(d), s);
    return d;
}

void engine_trace(int level, const char *trace, ...) { (void)level; (void)trace; }
