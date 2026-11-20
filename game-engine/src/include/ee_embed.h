/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: The events engine's passes, as another program runs them
               (#694 stage 4). bin/events-engine runs them in ee_run; with
               EVENTS_IN_ENGINE=1 the game engine runs them between turns
               (settle.c). This header names no engine's types, so the game
               engine's own files can include it beside engine.h.

******************************************************************************/

#ifndef __EM_EE_EMBED_MODULE__
#define __EM_EE_EMBED_MODULE__

#include "common_types.h"
#include "trace.h"

/* Gets the events engine ready for its passes (what ee_run does before its loop) */
void ee_init();

/* One pass of the events engine (the body of ee_run's loop, without its
   sleep): a batch of due events, then the stations, the share exchange, the
   processor tax and the events archive when they are due. *found: the
   number of due events the batch read */
ErrorCode_t ee_pass(int* found);

/* The events engine inside another program: reads conf/events-engine.conf
   under plat_home (and the environment), writes its log lines to trace with
   an "[events] " prefix, and sets up its database connection without
   opening it. Installs no signal handlers. */
ErrorCode_t ee_embed_init(const char* plat_home, TraceHndl_t trace);

/* Opens the connection if it isn't, and takes the events engine's lock
   (db_common.c's "events-engine" named lock) without waiting; once held,
   does the events engine's start-up work (frees stuck tools, loads the tool
   locks). ENGINE_OK when the lock is held. Run it with the connection
   current for the shared modules (see settle.c) */
ErrorCode_t ee_embed_claim(void);

/* Whether the lock is held (ee_embed_claim succeeded) */
int ee_embed_claimed(void);

/* The settlement connection: the events engine's own, never the turns' */
DbConnection_t* ee_embed_connection(void);

/* Is this the embedded events engine's connection? (db_init asks, so a
   reconnect makes it as the events engine would) */
int ee_embed_owns(const DbConnection_t* connection);

/* Closes the connection (and so gives up the lock) */
void ee_embed_stop(void);

/* bin/events-engine with EVENTS_IN_ENGINE=1: logs once that the game engine
   settles, then rests until it is stopped (it must not exit: Docker would
   restart it in a loop). Returns the exit code */
int ee_rest(int argc, char** argv);

/* The events engine's batch size (OUTCOME_EVENT_BATCH_SIZE) */
int engine_get_outcome_events_batch_size();

#endif
