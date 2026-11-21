/* Due events from memory (#694 stage 4 step 3): see event_heap.h */
#include <stdlib.h>
#include <string.h>

#include "event_heap.h"

int event_heap_compare(const EventHeapEntry_t* a, const EventHeapEntry_t* b)
{
    // ORDER BY game_t ASC, action ASC, id ASC (db_e.c's batch query)
    if(a->game_t != b->game_t) return a->game_t < b->game_t ? -1 : 1;
    if(a->action != b->action) return a->action < b->action ? -1 : 1;
    if(a->id != b->id) return a->id < b->id ? -1 : 1;
    return 0;
}

void event_heap_init(EventHeap_t* heap)
{
    memset(heap, 0, sizeof(*heap));
}

void event_heap_free(EventHeap_t* heap)
{
    free(heap->items);
    free(heap->slots);
    memset(heap, 0, sizeof(*heap));
}

/******************************* THE INDEX BY ID *****************************/

static unsigned int slot_home(const EventHeap_t* heap, int id)
{
    return ((unsigned int)id * 2654435761u) & (unsigned int)(heap->slot_count - 1);
}

/* The slot holding id, or -1 */
static int slot_find(const EventHeap_t* heap, int id)
{
    if(!heap->slot_count)
        return -1;
    unsigned int mask = heap->slot_count - 1;
    for(unsigned int s = slot_home(heap, id); ; s = (s + 1) & mask) {
        int v = heap->slots[s];
        if(!v)
            return -1;
        if(heap->items[v - 1].id == id)
            return (int)s;
    }
}

static void slot_put(EventHeap_t* heap, int id, int index)
{
    unsigned int mask = heap->slot_count - 1;
    unsigned int s = slot_home(heap, id);
    while(heap->slots[s])
        s = (s + 1) & mask;
    heap->slots[s] = index + 1;
}

/* Empties slot s, moving later entries of its run back (no tombstones) */
static void slot_clear(EventHeap_t* heap, int s)
{
    unsigned int mask = heap->slot_count - 1;
    unsigned int hole = (unsigned int)s;
    heap->slots[hole] = 0;
    for(unsigned int j = (hole + 1) & mask; heap->slots[j]; j = (j + 1) & mask) {
        unsigned int home = slot_home(heap, heap->items[heap->slots[j] - 1].id);
        // Can the entry at j move into the hole? Only if its home isn't
        // cyclically in (hole, j]
        int between = (hole <= j) ? (home > hole && home <= j) : (home > hole || home <= j);
        if(!between) {
            heap->slots[hole] = heap->slots[j];
            heap->slots[j] = 0;
            hole = j;
        }
    }
}

/* The entry copied to heap position index from the end: its slot still
   points at the end, which still holds a copy, so it is found by id */
static void slot_point(EventHeap_t* heap, int index)
{
    int s = slot_find(heap, heap->items[index].id);
    if(s >= 0)
        heap->slots[s] = index + 1;
}

static int slots_grow(EventHeap_t* heap)
{
    int count = heap->slot_count ? heap->slot_count * 2 : 1024;
    int* slots = calloc((size_t)count, sizeof(int));
    if(!slots)
        return -1;
    free(heap->slots);
    heap->slots = slots;
    heap->slot_count = count;
    for(int i = 0; i < heap->count; i++)
        slot_put(heap, heap->items[i].id, i);
    return 0;
}

/******************************* THE HEAP ************************************/

/* Moves items[a] and items[b], and their slots */
static void swap_items(EventHeap_t* heap, int a, int b)
{
    // Find both slots before the items move (slots find by the item's id)
    int sa = slot_find(heap, heap->items[a].id);
    int sb = slot_find(heap, heap->items[b].id);
    EventHeapEntry_t t = heap->items[a];
    heap->items[a] = heap->items[b];
    heap->items[b] = t;
    if(sa >= 0) heap->slots[sa] = b + 1;
    if(sb >= 0) heap->slots[sb] = a + 1;
}

static void sift_up(EventHeap_t* heap, int i)
{
    while(i > 0) {
        int parent = (i - 1) / 2;
        if(event_heap_compare(&heap->items[i], &heap->items[parent]) >= 0)
            break;
        swap_items(heap, i, parent);
        i = parent;
    }
}

static void sift_down(EventHeap_t* heap, int i)
{
    for(;;) {
        int l = 2 * i + 1, r = l + 1, least = i;
        if(l < heap->count && event_heap_compare(&heap->items[l], &heap->items[least]) < 0) least = l;
        if(r < heap->count && event_heap_compare(&heap->items[r], &heap->items[least]) < 0) least = r;
        if(least == i)
            break;
        swap_items(heap, i, least);
        i = least;
    }
}

int event_heap_contains(const EventHeap_t* heap, int id)
{
    return slot_find(heap, id) >= 0;
}

int event_heap_push(EventHeap_t* heap, int id, long long game_t, long long action)
{
    if(slot_find(heap, id) >= 0)
        return 0;
    if(heap->count == heap->size) {
        int size = heap->size ? heap->size * 2 : 1024;
        EventHeapEntry_t* items = realloc(heap->items, sizeof(EventHeapEntry_t) * (size_t)size);
        if(!items)
            return -1;
        heap->items = items;
        heap->size = size;
    }
    // At most half full, so runs stay short
    if((heap->count + 1) * 2 > heap->slot_count && slots_grow(heap) != 0)
        return -1;
    int i = heap->count++;
    heap->items[i] = (EventHeapEntry_t){ game_t, action, id };
    slot_put(heap, id, i);
    sift_up(heap, i);
    return 1;
}

const EventHeapEntry_t* event_heap_peek(const EventHeap_t* heap)
{
    return heap->count ? &heap->items[0] : NULL;
}

/* Takes out the entry at heap position i */
static void take_at(EventHeap_t* heap, int i)
{
    int s = slot_find(heap, heap->items[i].id);
    if(s >= 0)
        slot_clear(heap, s);
    int last = --heap->count;
    if(i == last)
        return;
    heap->items[i] = heap->items[last];
    slot_point(heap, i);
    // The moved entry may belong above or below
    if(i > 0 && event_heap_compare(&heap->items[i], &heap->items[(i - 1) / 2]) < 0)
        sift_up(heap, i);
    else
        sift_down(heap, i);
}

int event_heap_pop(EventHeap_t* heap, EventHeapEntry_t* out)
{
    if(!heap->count)
        return 0;
    if(out)
        *out = heap->items[0];
    take_at(heap, 0);
    return 1;
}

int event_heap_remove(EventHeap_t* heap, int id)
{
    int s = slot_find(heap, id);
    if(s < 0)
        return 0;
    take_at(heap, heap->slots[s] - 1);
    return 1;
}

/******************************* THE ENGINE'S HEAP ***************************/

static EventHeap_t g_heap;
static int g_on = 0;

void event_heap_enable(void)
{
    if(!g_on) {
        event_heap_init(&g_heap);
        g_on = 1;
    }
}

int event_heap_on(void)
{
    return g_on;
}

EventHeap_t* event_heap_global(void)
{
    return g_on ? &g_heap : NULL;
}

/* Staged inserts, per connection (the turns' and settlement's) */
#define EVENT_HEAP_STAGES 4
typedef struct
{
    const void* owner;
    EventHeapEntry_t* items;
    int count;
    int size;
} EventHeapStage_t;
static EventHeapStage_t g_stages[EVENT_HEAP_STAGES];

static EventHeapStage_t* stage_for(const void* owner, int create)
{
    for(int i = 0; i < EVENT_HEAP_STAGES; i++)
        if(g_stages[i].owner == owner)
            return &g_stages[i];
    if(!create)
        return NULL;
    for(int i = 0; i < EVENT_HEAP_STAGES; i++)
        if(!g_stages[i].owner) {
            g_stages[i].owner = owner;
            return &g_stages[i];
        }
    return NULL;
}

void event_heap_stage(const void* owner, int id, long long game_t, long long action)
{
    if(!g_on || id <= 0)
        return;
    EventHeapStage_t* stage = stage_for(owner, 1);
    if(!stage)
        return;   // the sweep finds it
    if(stage->count == stage->size) {
        int size = stage->size ? stage->size * 2 : 64;
        EventHeapEntry_t* items = realloc(stage->items, sizeof(EventHeapEntry_t) * (size_t)size);
        if(!items)
            return;   // the sweep finds it
        stage->items = items;
        stage->size = size;
    }
    stage->items[stage->count++] = (EventHeapEntry_t){ game_t, action, id };
}

int event_heap_stage_commit(const void* owner)
{
    if(!g_on)
        return 0;
    EventHeapStage_t* stage = stage_for(owner, 0);
    if(!stage)
        return 0;
    int added = 0;
    for(int i = 0; i < stage->count; i++)
        if(event_heap_push(&g_heap, stage->items[i].id, stage->items[i].game_t, stage->items[i].action) == 1)
            added++;
    stage->count = 0;
    return added;
}

void event_heap_stage_discard(const void* owner)
{
    if(!g_on)
        return;
    EventHeapStage_t* stage = stage_for(owner, 0);
    if(stage)
        stage->count = 0;
}

int event_heap_flag_on(void)
{
    const char* v = getenv("EVENT_HEAP");
    return v && atoi(v) == 1;
}
