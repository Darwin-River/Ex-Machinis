/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Drone wakes from memory (#694 stage 4 step 4, #432, WAKE_HEAP=1).

  The game engine's commands (rows of `commands`: a drone's resume, a
  player's mail, a script piece, <launched>...) kept in memory, so the engine
  finds the next ones to run without asking MySQL to sort every due command
  (db.c's batch queries). Only each command's id, drone and due time are
  kept; the rows stay in MySQL, and a command is read by id before it runs,
  as before.

  An entry is in one of four places:
   - waiting: a min-heap keyed by the game time it may run from (its
     deliver_at, or later while it waits for its drone's action);
   - ready: due, keyed (tier, id) as the batch queries order them:
     tier 0 for the House's station drones' commands, 1 for a loading
     drone's commands but its resumes (no release_vm), 2 for the rest;
   - unstamped: no deliver_at yet (a player's command the light-speed
     stamping hasn't timed): looked up again before each batch;
   - recheck: due but held for another reason (a new script waits while
     the drone's running one has a resume queued): looked at again at the
     next batch, as the query would;
   - held: a resume whose drone's action still has events to settle, once
     the next of them is about due: the next batch asks only whether that
     action still has any (the query's hold for it), and checks the resume
     in full once it hasn't.

  Keys are never later than the truth: a command due later than its key
  (a delayed resume), or of a lower tier than its key (a drone that has
  launched since), is found so when it is looked at, and put back with its
  real key. So taking the ready commands in key order, checking each with
  MySQL and putting back those whose key was wrong, gives exactly the
  query's order.

  No database code here (test_wake_heap.c tests it alone): db_wake.c reads
  and checks; db.c and db_e.c stage what their transactions write, and the
  stage applies when that connection commits.

******************************************************************************/

#ifndef __EM_WAKE_HEAP_MODULE__
#define __EM_WAKE_HEAP_MODULE__

#include <limits.h>

#include "event_heap.h"

/* deliver_at IS NULL */
#define WAKE_UNSTAMPED LLONG_MIN

/* What the batch queries order by (flags) */
#define WAKE_RESUME   1   /* code LIKE '<resume command>%' */
#define WAKE_STATION  2   /* its drone's station_id IS NOT NULL */
#define WAKE_LOADING  4   /* its drone exists and release_vm IS NULL */
/* What isn't known yet: the earliest it could be (a key never too late) */
#define WAKE_UNKNOWN_DRONE (WAKE_STATION | WAKE_LOADING)

/* Where an entry is */
#define WAKE_NOWHERE   0   /* taken out for a check */
#define WAKE_WAITING   1
#define WAKE_READY     2
#define WAKE_UNSTAMPED_LIST 3
#define WAKE_RECHECK   4
#define WAKE_HELD_DUE  5

typedef struct
{
    int id;                 /* commands.id; 0: an empty slot */
    int agent_id;
    long long deliver_at;   /* WAKE_UNSTAMPED for NULL */
    unsigned char flags;
    unsigned char where;
    unsigned char held;     /* waiting for its drone's action, not its deliver_at */
    int action;             /* that action, while held */
    unsigned int confirmed; /* the batch that found it runnable */
} WakeEntry_t;

typedef struct
{
    WakeEntry_t* table;     /* open addressing by id */
    int table_count;        /* a power of two */
    int count;
    EventHeap_t waiting;    /* (wake game time, 0, id) */
    EventHeap_t ready;      /* (tier, 0, id) */
    int* unstamped;
    int unstamped_n, unstamped_size;
    int* recheck;
    int recheck_n, recheck_size;
    int* held_due;
    int held_due_n, held_due_size;
    int shards, shard;      /* only drones with agent_id % shards == shard */
} WakeHeap_t;

/* 0, 1 or 2: the batch queries' order (station drones, loading, the rest) */
int wake_tier(unsigned int flags);

void wake_heap_init(WakeHeap_t* heap, int shards, int shard);
void wake_heap_free(WakeHeap_t* heap);

/* This shard's drone (the queries' "c.agent_id % shards = shard") */
int wake_heap_mine(const WakeHeap_t* heap, int agent_id);

WakeEntry_t* wake_heap_find(WakeHeap_t* heap, int id);
static inline int wake_heap_count(const WakeHeap_t* heap) { return heap->count; }

/* Adds a command, or replaces what is known of it (never twice): waiting
   from deliver_at, or unstamped. 1 added, 0 replaced, -1 out of memory,
   -2 another shard's (left out) */
int wake_heap_put(WakeHeap_t* heap, int id, int agent_id, long long deliver_at, unsigned int flags);

/* Forgets a command: 1 when it was in */
int wake_heap_remove(WakeHeap_t* heap, int id);

/* Where it goes next (it must be in) */
void wake_heap_to_ready(WakeHeap_t* heap, WakeEntry_t* e);
/* held_action: the action a held resume waits for (0: it waits for its
   own time) */
void wake_heap_to_waiting(WakeHeap_t* heap, WakeEntry_t* e, long long from, int held_action);
void wake_heap_to_unstamped(WakeHeap_t* heap, WakeEntry_t* e);
void wake_heap_to_recheck(WakeHeap_t* heap, WakeEntry_t* e);

/* Moves into ready everything waiting with a key <= now (held resumes
   into held instead), and everything to recheck: how many moved */
int wake_heap_release(WakeHeap_t* heap, long long now);

/* The first ready entry, or NULL; taking it out leaves it WAKE_NOWHERE */
WakeEntry_t* wake_heap_peek_ready(WakeHeap_t* heap);
WakeEntry_t* wake_heap_pop_ready(WakeHeap_t* heap);

/* The unstamped ids (compacted first): count, and *ids */
int wake_heap_unstamped(WakeHeap_t* heap, const int** ids);
/* The held resumes whose action's next event is about due (compacted) */
int wake_heap_held_due(WakeHeap_t* heap, const int** ids);

/* A drone's resumes, as db_delay_resume_commands and
   db_delete_resume_commands change them: how many */
int wake_heap_delay_resumes(WakeHeap_t* heap, int agent_id, long long seconds);
int wake_heap_remove_resumes(WakeHeap_t* heap, int agent_id);
/* A drone took a turn: what it waited for may have changed, so its held
   entries are looked at again at the next batch. How many */
int wake_heap_drone_turned(WakeHeap_t* heap, int agent_id);
/* Its drone's flags as known from another of its commands (else
   WAKE_UNKNOWN_DRONE); id: a command of the drone, looked at first */
unsigned int wake_heap_drone_flags(WakeHeap_t* heap, int id, int agent_id);

/******************************* THE ENGINE'S HEAP ***************************/

/* The one heap of this program. Off (the default, and always in
   bin/events-engine), the functions below do nothing. engine.c turns it on
   with WAKE_HEAP=1 */
void wake_heap_enable(int shards, int shard);
int wake_heap_on(void);
WakeHeap_t* wake_heap_global(void);

/* What a connection's transaction did to `commands`, applied in order when
   it commits and forgotten if it rolls back (until then no one else sees
   it). owner is the connection */
void wake_heap_stage_put(const void* owner, int id, int agent_id, long long deliver_at, unsigned int flags);
void wake_heap_stage_remove(const void* owner, int id);
void wake_heap_stage_remove_resumes(const void* owner, int agent_id);
void wake_heap_stage_delay_resumes(const void* owner, int agent_id, long long seconds);
void wake_heap_stage_turned(const void* owner, int agent_id);
/* The connection committed: how many commands came in */
int wake_heap_stage_commit(const void* owner);
void wake_heap_stage_discard(const void* owner);

/* WAKE_HEAP=1 in the environment */
int wake_heap_flag_on(void);

#endif
