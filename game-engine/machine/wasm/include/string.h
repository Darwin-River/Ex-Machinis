/* WebAssembly build of the machine (#324 step 19): the few string functions
   the VM uses (wasm/libc.c) */
#include <stddef.h>
void *memcpy(void *d, const void *s, size_t n);
void *memset(void *d, int c, size_t n);
size_t strlen(const char *s);
char *strcpy(char *d, const char *s);
char *strcat(char *d, const char *s);
