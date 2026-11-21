/* Drone wakes from memory (#694 stage 4 step 4): see wake_heap.h */
#include <stdlib.h>
#include <string.h>

#include "wake_heap.h"

int wake_tier(unsigned int flags)
{
    // db.c's batch: the House's station drones' commands, then a loading
    // drone's commands but its resumes, then the rest; each by id
    if(flags & WAKE_STATION) return 0;
    if((flags & WAKE_LOADING) && !(flags & WAKE_RESUME)) return 1;
    return 2;
}

void wake_heap_init(WakeHeap_t* heap, int shards, int shard)
{
    memset(heap, 0, sizeof(*heap));
    event_heap_init(&heap->waiting);
    event_heap_init(&heap->ready);
    heap->shards = shards > 1 ? shards : 1;
    heap->shard = shard;
}

void wake_heap_free(WakeHeap_t* heap)
{
    free(heap->table);
    free(heap->unstamped);
    free(heap->recheck);
    free(heap->held_due);
    event_heap_free(&heap->waiting);
    event_heap_free(&heap->ready);
    memset(heap, 0, sizeof(*heap));
}

int wake_heap_mine(const WakeHeap_t* heap, int agent_id)
{
    // MySQL's % as C's for the non-negative ids drones have
    return heap->shards <= 1 || (agent_id >= 0 && agent_id % heap->shards == heap->shard);
}

/******************************* THE TABLE BY ID *****************************/

static unsigned int home_of(const WakeHeap_t* heap, int id)
{
    return ((unsigned int)id * 2654435761u) & (unsigned int)(heap->table_count - 1);
}

static int slot_of(const WakeHeap_t* heap, int id)
{
    if(!heap->table_count || id <= 0)
        return -1;
    unsigned int mask = heap->table_count - 1;
    for(unsigned int s = home_of(heap, id); ; s = (s + 1) & mask) {
        if(!heap->table[s].id) return -1;
        if(heap->table[s].id == id) return (int)s;
    }
}

WakeEntry_t* wake_heap_find(WakeHeap_t* heap, int id)
{
    int s = slot_of(heap, id);
    return s < 0 ? NULL : &heap->table[s];
}

static WakeEntry_t* table_insert(WakeHeap_t* heap, const WakeEntry_t* e)
{
    unsigned int mask = heap->table_count - 1;
    unsigned int s = home_of(heap, e->id);
    while(heap->table[s].id)
        s = (s + 1) & mask;
    heap->table[s] = *e;
    return &heap->table[s];
}

static int table_grow(WakeHeap_t* heap)
{
    int count = heap->table_count ? heap->table_count * 2 : 1024;
    WakeEntry_t* old = heap->table;
    int old_count = heap->table_count;
    WakeEntry_t* table = calloc((size_t)count, sizeof(WakeEntry_t));
    if(!table)
        return -1;
    heap->table = table;
    heap->table_count = count;
    for(int i = 0; i < old_count; i++)
        if(old[i].id)
            table_insert(heap, &old[i]);
    free(old);
    return 0;
}

/* Empties slot s, moving later entries of its run back (no tombstones) */
static void table_clear(WakeHeap_t* heap, int s)
{
    unsigned int mask = heap->table_count - 1;
    unsigned int hole = (unsigned int)s;
    memset(&heap->table[hole], 0, sizeof(WakeEntry_t));
    for(unsigned int j = (hole + 1) & mask; heap->table[j].id; j = (j + 1) & mask) {
        unsigned int home = home_of(heap, heap->table[j].id);
        int between = (hole <= j) ? (home > hole && home <= j) : (home > hole || home <= j);
        if(!between) {
            heap->table[hole] = heap->table[j];
            memset(&heap->table[j], 0, sizeof(WakeEntry_t));
            hole = j;
        }
    }
}

/******************************* WHERE AN ENTRY IS ***************************/

/* Out of where it is (the lists are compacted lazily: an id there whose
   entry is elsewhere is skipped) */
static void take_out(WakeHeap_t* heap, WakeEntry_t* e)
{
    if(e->where == WAKE_WAITING) event_heap_remove(&heap->waiting, e->id);
    else if(e->where == WAKE_READY) event_heap_remove(&heap->ready, e->id);
    e->where = WAKE_NOWHERE;
    e->held = 0;
    e->action = 0;
}

static int list_add(int** list, int* n, int* size, int id)
{
    if(*n == *size) {
        int grown = *size ? *size * 2 : 64;
        int* l = realloc(*list, sizeof(int) * (size_t)grown);
        if(!l) return -1;
        *list = l;
        *size = grown;
    }
    (*list)[(*n)++] = id;
    return 0;
}

void wake_heap_to_ready(WakeHeap_t* heap, WakeEntry_t* e)
{
    take_out(heap, e);
    e->where = WAKE_READY;
    event_heap_push(&heap->ready, e->id, wake_tier(e->flags), 0);
}

void wake_heap_to_waiting(WakeHeap_t* heap, WakeEntry_t* e, long long from, int held_action)
{
    take_out(heap, e);
    e->where = WAKE_WAITING;
    e->held = held_action ? 1 : 0;
    e->action = held_action;
    event_heap_push(&heap->waiting, e->id, from, 0);
}

void wake_heap_to_unstamped(WakeHeap_t* heap, WakeEntry_t* e)
{
    int was = e->where;
    take_out(heap, e);
    e->where = WAKE_UNSTAMPED_LIST;
    if(was != WAKE_UNSTAMPED_LIST)
        list_add(&heap->unstamped, &heap->unstamped_n, &heap->unstamped_size, e->id);
}

void wake_heap_to_recheck(WakeHeap_t* heap, WakeEntry_t* e)
{
    int was = e->where;
    take_out(heap, e);
    e->where = WAKE_RECHECK;
    if(was != WAKE_RECHECK)
        list_add(&heap->recheck, &heap->recheck_n, &heap->recheck_size, e->id);
}

/* Where a command with this deliver_at goes */
static void place(WakeHeap_t* heap, WakeEntry_t* e)
{
    if(e->deliver_at == WAKE_UNSTAMPED)
        wake_heap_to_unstamped(heap, e);
    else
        wake_heap_to_waiting(heap, e, e->deliver_at, 0);
}

int wake_heap_put(WakeHeap_t* heap, int id, int agent_id, long long deliver_at, unsigned int flags)
{
    if(id <= 0)
        return 0;
    if(!wake_heap_mine(heap, agent_id))
        return -2;
    WakeEntry_t* e = wake_heap_find(heap, id);
    int added = 0;
    if(!e) {
        // At most half full, so runs stay short
        if((heap->count + 1) * 2 > heap->table_count && table_grow(heap) != 0)
            return -1;
        WakeEntry_t fresh;
        memset(&fresh, 0, sizeof(fresh));
        fresh.id = id;
        e = table_insert(heap, &fresh);
        heap->count++;
        added = 1;
    }
    e->agent_id = agent_id;
    e->deliver_at = deliver_at;
    e->flags = (unsigned char)flags;
    e->confirmed = 0;
    place(heap, e);
    return added;
}

int wake_heap_remove(WakeHeap_t* heap, int id)
{
    int s = slot_of(heap, id);
    if(s < 0)
        return 0;
    take_out(heap, &heap->table[s]);
    table_clear(heap, s);
    heap->count--;
    return 1;
}

int wake_heap_release(WakeHeap_t* heap, long long now)
{
    int moved = 0;
    const EventHeapEntry_t* top;
    EventHeapEntry_t got;
    while((top = event_heap_peek(&heap->waiting)) && top->game_t <= now) {
        event_heap_pop(&heap->waiting, &got);
        WakeEntry_t* e = wake_heap_find(heap, got.id);
        if(!e) continue;
        if(e->held) {
            // Still held, as far as is known: only its action is asked about
            e->where = WAKE_HELD_DUE;
            list_add(&heap->held_due, &heap->held_due_n, &heap->held_due_size, e->id);
            continue;
        }
        e->where = WAKE_NOWHERE;
        wake_heap_to_ready(heap, e);
        moved++;
    }
    int n = heap->recheck_n;
    heap->recheck_n = 0;
    for(int i = 0; i < n; i++) {
        WakeEntry_t* e = wake_heap_find(heap, heap->recheck[i]);
        if(!e || e->where != WAKE_RECHECK) continue;
        e->where = WAKE_NOWHERE;
        wake_heap_to_ready(heap, e);
        moved++;
    }
    return moved;
}

WakeEntry_t* wake_heap_peek_ready(WakeHeap_t* heap)
{
    const EventHeapEntry_t* top = event_heap_peek(&heap->ready);
    return top ? wake_heap_find(heap, top->id) : NULL;
}

WakeEntry_t* wake_heap_pop_ready(WakeHeap_t* heap)
{
    EventHeapEntry_t got;
    while(event_heap_pop(&heap->ready, &got)) {
        WakeEntry_t* e = wake_heap_find(heap, got.id);
        if(!e) continue;
        e->where = WAKE_NOWHERE;
        return e;
    }
    return NULL;
}

int wake_heap_held_due(WakeHeap_t* heap, const int** ids)
{
    int kept = 0;
    for(int i = 0; i < heap->held_due_n; i++) {
        WakeEntry_t* e = wake_heap_find(heap, heap->held_due[i]);
        if(e && e->where == WAKE_HELD_DUE) {
            e->where = WAKE_NOWHERE + 100;   // seen once: no duplicates
            heap->held_due[kept++] = heap->held_due[i];
        }
    }
    for(int i = 0; i < kept; i++)
        wake_heap_find(heap, heap->held_due[i])->where = WAKE_HELD_DUE;
    heap->held_due_n = kept;
    *ids = heap->held_due;
    return kept;
}

int wake_heap_unstamped(WakeHeap_t* heap, const int** ids)
{
    int kept = 0;
    for(int i = 0; i < heap->unstamped_n; i++) {
        WakeEntry_t* e = wake_heap_find(heap, heap->unstamped[i]);
        if(e && e->where == WAKE_UNSTAMPED_LIST)
            heap->unstamped[kept++] = heap->unstamped[i];
    }
    heap->unstamped_n = kept;
    *ids = heap->unstamped;
    return kept;
}

/******************************* A DRONE'S COMMANDS **************************/

/* The ids of a drone's entries (resumes only, or all): a scan of the table,
   for the few statements that change them by drone */
static int drone_ids(WakeHeap_t* heap, int agent_id, int resumes_only, int** out)
{
    int n = 0, size = 0;
    *out = NULL;
    for(int s = 0; s < heap->table_count; s++) {
        WakeEntry_t* e = &heap->table[s];
        if(!e->id || e->agent_id != agent_id) continue;
        if(resumes_only && !(e->flags & WAKE_RESUME)) continue;
        if(list_add(out, &n, &size, e->id) != 0) break;
    }
    return n;
}

int wake_heap_delay_resumes(WakeHeap_t* heap, int agent_id, long long seconds)
{
    int* ids;
    int n = drone_ids(heap, agent_id, 1, &ids), changed = 0;
    for(int i = 0; i < n; i++) {
        WakeEntry_t* e = wake_heap_find(heap, ids[i]);
        // "... AND deliver_at IS NOT NULL"
        if(!e || e->deliver_at == WAKE_UNSTAMPED) continue;
        e->deliver_at += seconds;
        e->confirmed = 0;
        place(heap, e);
        changed++;
    }
    free(ids);
    return changed;
}

int wake_heap_remove_resumes(WakeHeap_t* heap, int agent_id)
{
    int* ids;
    int n = drone_ids(heap, agent_id, 1, &ids), removed = 0;
    for(int i = 0; i < n; i++)
        removed += wake_heap_remove(heap, ids[i]);
    free(ids);
    return removed;
}

int wake_heap_drone_turned(WakeHeap_t* heap, int agent_id)
{
    int* ids;
    int n = drone_ids(heap, agent_id, 0, &ids), moved = 0;
    for(int i = 0; i < n; i++) {
        WakeEntry_t* e = wake_heap_find(heap, ids[i]);
        if(e && ((e->where == WAKE_WAITING && e->held) || e->where == WAKE_HELD_DUE)) {
            wake_heap_to_recheck(heap, e);
            moved++;
        }
    }
    free(ids);
    return moved;
}

unsigned int wake_heap_drone_flags(WakeHeap_t* heap, int id, int agent_id)
{
    WakeEntry_t* e = wake_heap_find(heap, id);
    if(e && e->agent_id == agent_id)
        return e->flags & (WAKE_STATION | WAKE_LOADING);
    return WAKE_UNKNOWN_DRONE;
}

/******************************* THE ENGINE'S HEAP ***************************/

static WakeHeap_t g_heap;
static int g_on = 0;

void wake_heap_enable(int shards, int shard)
{
    if(!g_on) {
        wake_heap_init(&g_heap, shards, shard);
        g_on = 1;
    }
}

int wake_heap_on(void)
{
    return g_on;
}

WakeHeap_t* wake_heap_global(void)
{
    return g_on ? &g_heap : NULL;
}

/* Staged changes, per connection (the turns' and settlement's) */
#define WAKE_OP_PUT             1
#define WAKE_OP_REMOVE          2
#define WAKE_OP_REMOVE_RESUMES  3
#define WAKE_OP_DELAY_RESUMES   4
#define WAKE_OP_TURNED          5
typedef struct
{
    int op;
    int id;
    int agent_id;
    long long t;
    unsigned int flags;
} WakeOp_t;

#define WAKE_STAGES 4
typedef struct
{
    const void* owner;
    WakeOp_t* ops;
    int count;
    int size;
} WakeStage_t;
static WakeStage_t g_stages[WAKE_STAGES];

static WakeStage_t* stage_for(const void* owner, int create)
{
    for(int i = 0; i < WAKE_STAGES; i++)
        if(g_stages[i].owner == owner)
            return &g_stages[i];
    if(!create)
        return NULL;
    for(int i = 0; i < WAKE_STAGES; i++)
        if(!g_stages[i].owner) {
            g_stages[i].owner = owner;
            return &g_stages[i];
        }
    return NULL;
}

static void stage_op(const void* owner, int op, int id, int agent_id, long long t, unsigned int flags)
{
    if(!g_on || !wake_heap_mine(&g_heap, agent_id))
        return;
    WakeStage_t* stage = stage_for(owner, 1);
    if(!stage)
        return;   // the reads find it
    if(stage->count == stage->size) {
        int size = stage->size ? stage->size * 2 : 16;
        WakeOp_t* ops = realloc(stage->ops, sizeof(WakeOp_t) * (size_t)size);
        if(!ops)
            return;   // the reads find it
        stage->ops = ops;
        stage->size = size;
    }
    stage->ops[stage->count++] = (WakeOp_t){ op, id, agent_id, t, flags };
}

void wake_heap_stage_put(const void* owner, int id, int agent_id, long long deliver_at, unsigned int flags)
{
    if(id > 0)
        stage_op(owner, WAKE_OP_PUT, id, agent_id, deliver_at, flags);
}

void wake_heap_stage_remove(const void* owner, int id)
{
    // A removal needs no drone: any shard's id is only in its own heap
    if(!g_on || id <= 0)
        return;
    WakeEntry_t* e = wake_heap_find(&g_heap, id);
    stage_op(owner, WAKE_OP_REMOVE, id, e ? e->agent_id : g_heap.shard, 0, 0);
}

void wake_heap_stage_remove_resumes(const void* owner, int agent_id)
{
    stage_op(owner, WAKE_OP_REMOVE_RESUMES, 0, agent_id, 0, 0);
}

void wake_heap_stage_delay_resumes(const void* owner, int agent_id, long long seconds)
{
    stage_op(owner, WAKE_OP_DELAY_RESUMES, 0, agent_id, seconds, 0);
}

void wake_heap_stage_turned(const void* owner, int agent_id)
{
    stage_op(owner, WAKE_OP_TURNED, 0, agent_id, 0, 0);
}

int wake_heap_stage_commit(const void* owner)
{
    if(!g_on)
        return 0;
    WakeStage_t* stage = stage_for(owner, 0);
    if(!stage)
        return 0;
    int added = 0;
    for(int i = 0; i < stage->count; i++) {
        WakeOp_t* op = &stage->ops[i];
        switch(op->op) {
        case WAKE_OP_PUT:
            if(wake_heap_put(&g_heap, op->id, op->agent_id, op->t, op->flags) == 1) added++;
            break;
        case WAKE_OP_REMOVE:          wake_heap_remove(&g_heap, op->id); break;
        case WAKE_OP_REMOVE_RESUMES:  wake_heap_remove_resumes(&g_heap, op->agent_id); break;
        case WAKE_OP_DELAY_RESUMES:   wake_heap_delay_resumes(&g_heap, op->agent_id, op->t); break;
        case WAKE_OP_TURNED:          wake_heap_drone_turned(&g_heap, op->agent_id); break;
        }
    }
    stage->count = 0;
    return added;
}

void wake_heap_stage_discard(const void* owner)
{
    if(!g_on)
        return;
    WakeStage_t* stage = stage_for(owner, 0);
    if(stage)
        stage->count = 0;
}

int wake_heap_flag_on(void)
{
    const char* v = getenv("WAKE_HEAP");
    return v && atoi(v) == 1;
}
