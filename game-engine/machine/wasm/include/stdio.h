/* WebAssembly build of the machine (#324 step 19): no C library; only the
   names the VM's headers mention */
#include <stddef.h>
typedef struct machine_no_file FILE;
int snprintf(char *s, size_t n, const char *format, ...);
