/* embed.c traces through the engine's engine_trace; outside the engine the
   machine supplies a do-nothing one (#324 step 19) */
#ifndef MACHINE_SHIM_H
#define MACHINE_SHIM_H
#define TRACE_LEVEL_ALWAYS 0
#define TRACE_LEVEL_DEBUG 3
void engine_trace(int level, const char *trace, ...);
#endif
