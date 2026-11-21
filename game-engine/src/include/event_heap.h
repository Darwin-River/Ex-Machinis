/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Due events from memory (#694 stage 4 step 3, #432, EVENT_HEAP=1).

  A min-heap of the events still to settle, ordered exactly as the events
  engine's batch query orders them: game_t, then action (NULL first, as
  MySQL sorts it), then id. Each entry is an event's id and that key; the
  rows themselves stay in MySQL. An index by id keeps each event in the heap
  once, and lets one be removed.

  The heap has no database code (test_event_heap.c tests it alone). db_e.c
  fills it and takes due events from it; db.c and db_e.c stage the events
  they insert, and the stage goes into the heap when that connection
  commits (see event_heap_stage).

******************************************************************************/

#ifndef __EM_EVENT_HEAP_MODULE__
#define __EM_EVENT_HEAP_MODULE__

#include <limits.h>

/* An events row with a NULL action sorts first, as in MySQL's ORDER BY */
#define EVENT_HEAP_NULL_ACTION LLONG_MIN

typedef struct
{
    long long game_t;
    long long action;   /* EVENT_HEAP_NULL_ACTION for NULL */
    int id;
} EventHeapEntry_t;

typedef struct
{
    EventHeapEntry_t* items;   /* the heap, items[0] first */
    int count;
    int size;
    int* slots;                /* open addressing: id -> heap index + 1, 0 empty */
    int slot_count;            /* a power of two */
} EventHeap_t;

/* -1, 0 or 1: a before b, same, after (game_t, action, id) */
int event_heap_compare(const EventHeapEntry_t* a, const EventHeapEntry_t* b);

void event_heap_init(EventHeap_t* heap);
void event_heap_free(EventHeap_t* heap);

/* Adds an event: 1 when added, 0 when it was in already (its key is left
   as it was), -1 when out of memory */
int event_heap_push(EventHeap_t* heap, int id, long long game_t, long long action);

/* The first event, or NULL when empty */
const EventHeapEntry_t* event_heap_peek(const EventHeap_t* heap);

/* Takes the first event out: 1 when there was one */
int event_heap_pop(EventHeap_t* heap, EventHeapEntry_t* out);

/* Takes an event out wherever it is: 1 when it was in */
int event_heap_remove(EventHeap_t* heap, int id);

int event_heap_contains(const EventHeap_t* heap, int id);

static inline int event_heap_count(const EventHeap_t* heap) { return heap->count; }

/******************************* THE ENGINE'S HEAP ***************************/

/* The one heap of this program. Off (the default, and always in
   bin/events-engine) the functions below do nothing. settle.c turns it on
   when EVENT_HEAP=1 and EVENTS_IN_ENGINE=1 and this engine settles. */
void event_heap_enable(void);
int event_heap_on(void);
EventHeap_t* event_heap_global(void);

/* An event a connection has inserted (its id from mysql_insert_id). It
   goes into the heap when that connection commits, and is forgotten if it
   rolls back: until then other transactions can't see it. owner is the
   connection. Staging one twice, or one that has been settled since, does
   no harm: settlement drops an event whose row isn't pending. */
void event_heap_stage(const void* owner, int id, long long game_t, long long action);
/* The connection committed: its staged events go into the heap */
int event_heap_stage_commit(const void* owner);
/* The connection rolled back */
void event_heap_stage_discard(const void* owner);

/* EVENT_HEAP=1 in the environment */
int event_heap_flag_on(void);

#endif
