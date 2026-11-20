/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: The events-engine binary's answers to the shared modules
               (db_common.c, game_time.c, orbits.c), which call engine_trace,
               engine_get_db_connection and db_init. In the game engine those
               are engine.c's and db.c's; here they hand on to the events
               engine's ee_ copies. Only bin/events-engine links this file:
               the Makefile's link-check leaves it out (#694 stage 4).

******************************************************************************/

#include <stdarg.h>

#include "engine_e.h"
#include "db.h"
#include "db_e.h"

void engine_trace(TraceLevel_t level, const char *trace, ... )
{
    va_list valist;

    va_start(valist, trace);
    ee_engine_vtrace(level, trace, valist);
    va_end(valist);
}

DbConnection_t* engine_get_db_connection()
{
    return ee_engine_get_db_connection();
}

ErrorCode_t db_init(DbConnection_t* connection)
{
    return ee_db_init(connection);
}
