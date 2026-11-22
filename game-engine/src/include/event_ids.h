/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: The engine takes its events' ids itself (#694 stage 4 step 6,
               ENGINE_OWNS_EVENTS=1).

  Every writer of `events` (both engines' turns, settlement, the website,
  procedures) takes ids from the one AUTO_INCREMENT counter of the table, at
  the moment it writes. Much depends on that order: the holdings and
  latest_orders triggers keep a drone's newest event by id (migration 0032),
  the archive keeps the latest of each kind by id, and protocol.c delays an
  action until after the drones' latest event so that, per drone, ids keep
  game-time order.

  With the flag on, the game engine reserves the ids of the events it is
  about to insert from that same counter, then inserts them with those ids.
  A reservation inserts placeholder rows (game_t -1, everything else NULL)
  on a connection of its own (READ COMMITTED), notes their ids and rolls
  them back: InnoDB never gives an auto-increment value twice, so the ids
  are this engine's, and every later writer's ids are higher. (Inside the
  caller's transaction, under REPEATABLE READ, the rollback left gap locks
  on the end of the table until that transaction ended, and the website's
  inserts deadlocked with settlement.) The ids are taken at the moment the
  insert would have taken them (in a turn, after the order locks and the
  delay), so the order per drone is the order of today. Step 7 (write-behind)
  needs exactly this: an event's id while the event is still in memory.

  This file has no database code (test_event_ids.c tests it alone); the
  reservation itself is db_reserve_event_ids (db_common.c).

  A check build (make CHECK_EVENT_IDS=1) also verifies, at every reservation,
  that the placeholders took consecutive ids, and after every event insert,
  with the flag off or on, that no event of the drone already has a higher
  id; the NOTICE each minute gives the counts.

******************************************************************************/

#ifndef __EM_EVENT_IDS_MODULE__
#define __EM_EVENT_IDS_MODULE__

#include <stddef.h>

/* At most this many placeholder rows in one statement */
#define EVENT_IDS_CHUNK 500

/* 1 when ENGINE_OWNS_EVENTS=1 (read once) */
int event_ids_flag_on(void);

/* For tests: forget the flag read, so the next call reads it again */
void event_ids_flag_reset(void);

/* The placeholder insert for n rows (1..EVENT_IDS_CHUNK) into buf: its
   length, or -1 when n is out of range or buf too small */
int event_ids_placeholder_sql(char* buf, size_t len, int n);

/* The text an INSERT INTO events gets for an id given (> 0): "id, " for the
   column list and "<id>, " for the values; both empty for 0 (MySQL's
   AUTO_INCREMENT, as before) */
void event_ids_explicit(int id, char* column, size_t column_len, char* value, size_t value_len);

/* The counts behind the NOTICE */
typedef struct
{
    long long reservations;     /* reservation statements run */
    long long ids;              /* ids reserved */
    long long inserted;         /* events inserted with an id of ours */
    long long auto_inserted;    /* events inserted by AUTO_INCREMENT (flag off, or a fallback) */
    long long fallbacks;        /* reservations that failed: AUTO_INCREMENT instead */
    long long duplicates;       /* inserts refused for a duplicate id (never expected) */
    long long check_ranges;     /* check build: reservations checked */
    long long check_gaps;       /* check build: ... whose ids were not consecutive */
    long long check_inserts;    /* check build: inserts checked */
    long long check_inversions; /* check build: ... with a higher id of the drone already there */
} EventIdStats_t;

EventIdStats_t* event_ids_stats(void);

/* The NOTICE line for the minute just ended into buf, and the counts start
   again. on: the flag; check: a check build */
void event_ids_notice(char* buf, size_t len, int on, int check);

/* 1 when a minute has passed since the last NOTICE (now_ms: a monotonic clock) */
int event_ids_minute_due(long long now_ms);

#endif
