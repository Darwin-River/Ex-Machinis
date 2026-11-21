/*
 * Checks the heap of events still to settle (event_heap.c, #694 stage 4
 * step 3): it gives events in the batch query's order (game_t, action with
 * NULL first, id), keeps each once, removes from anywhere, and holds its
 * order over many. Run with: make test
 */
#include <stdio.h>
#include <stdlib.h>

#include "event_heap.h"

static int failures = 0;
static void check(const char *what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

/* Pops everything, checking each comes in order: 1 when all did */
static int drains_in_order(EventHeap_t* h, int expect)
{
    EventHeapEntry_t prev, e;
    int n = 0, ok = 1;
    while(event_heap_pop(h, &e)) {
        if(n && event_heap_compare(&prev, &e) >= 0) ok = 0;
        if(event_heap_contains(h, e.id)) ok = 0;
        prev = e;
        n++;
    }
    return ok && n == expect;
}

static unsigned int rng = 12345;
static unsigned int next_rand(void) { rng = rng * 1103515245u + 12345u; return (rng >> 8) & 0xffffff; }

static int qsort_cmp(const void* a, const void* b)
{
    return event_heap_compare((const EventHeapEntry_t*)a, (const EventHeapEntry_t*)b);
}

int main(void)
{
    EventHeap_t h;
    EventHeapEntry_t e;

    // Ordering: game_t first
    event_heap_init(&h);
    check("empty: nothing to peek or pop", event_heap_peek(&h) == NULL && !event_heap_pop(&h, &e));
    event_heap_push(&h, 10, 300, 1);
    event_heap_push(&h, 11, 100, 9);
    event_heap_push(&h, 12, 200, 5);
    check("first by game_t", event_heap_peek(&h) && event_heap_peek(&h)->id == 11);
    event_heap_pop(&h, &e);
    check("then the next game_t", e.id == 11 && event_heap_peek(&h)->id == 12);
    event_heap_free(&h);
    check("a freed heap is empty", event_heap_peek(&h) == NULL && !event_heap_pop(&h, &e));

    // Ties: same game_t goes by action, then by id; NULL actions first
    event_heap_init(&h);
    event_heap_push(&h, 7, 50, 20);
    event_heap_push(&h, 5, 50, 20);
    event_heap_push(&h, 9, 50, 3);
    event_heap_push(&h, 30, 50, EVENT_HEAP_NULL_ACTION);
    event_heap_push(&h, 1, 51, 1);
    int want[] = { 30, 9, 5, 7, 1 };
    int got_all = 1;
    for(int i = 0; i < 5; i++) {
        if(!event_heap_pop(&h, &e) || e.id != want[i]) got_all = 0;
    }
    check("ties: NULL action, then action, then id, then a later game_t", got_all);
    check("ties: empty after", !event_heap_pop(&h, &e));
    event_heap_free(&h);

    // Each event once
    event_heap_init(&h);
    check("push adds", event_heap_push(&h, 4, 10, 1) == 1);
    check("pushing it again doesn't", event_heap_push(&h, 4, 5, 1) == 0 && event_heap_count(&h) == 1);
    check("the first key stays", event_heap_peek(&h)->game_t == 10);
    event_heap_pop(&h, &e);
    check("once popped it can come back", event_heap_push(&h, 4, 10, 1) == 1);
    event_heap_free(&h);

    // Removal
    event_heap_init(&h);
    for(int i = 1; i <= 20; i++)
        event_heap_push(&h, i, 100 - i, i);
    check("remove from the middle", event_heap_remove(&h, 10) == 1 && !event_heap_contains(&h, 10));
    check("remove the first", event_heap_remove(&h, 20) == 1 && event_heap_peek(&h)->id == 19);
    check("remove the last", event_heap_remove(&h, 1) == 1);
    check("remove one not there", event_heap_remove(&h, 10) == 0 && event_heap_remove(&h, 99) == 0);
    check("the rest still there", event_heap_contains(&h, 2) && event_heap_contains(&h, 19) && event_heap_count(&h) == 17);
    check("the rest in order", drains_in_order(&h, 17));
    event_heap_free(&h);

    // Large counts, with ties, removals and re-pushes, against a sort
    enum { N = 200000 };
    EventHeapEntry_t* all = malloc(sizeof(EventHeapEntry_t) * N);
    char* removed = calloc(N + 1, 1);
    event_heap_init(&h);
    int pushed_ok = 1;
    for(int i = 0; i < N; i++) {
        // Few game times and actions: many ties
        all[i] = (EventHeapEntry_t){ (long long)(next_rand() % 5000), (next_rand() % 7) ? (long long)(next_rand() % 50) : EVENT_HEAP_NULL_ACTION, i + 1 };
        if(event_heap_push(&h, all[i].id, all[i].game_t, all[i].action) != 1) pushed_ok = 0;
    }
    check("large: all pushed", pushed_ok && event_heap_count(&h) == N);
    int removed_ok = 1, removed_n = 0;
    for(int i = 0; i < N / 10; i++) {
        int id = (int)(next_rand() % N) + 1;
        int r = event_heap_remove(&h, id);
        if(r != (removed[id] ? 0 : 1)) removed_ok = 0;
        if(r) { removed[id] = 1; removed_n++; }
    }
    check("large: removals", removed_ok && event_heap_count(&h) == N - removed_n);
    // Pushing duplicates of the ones left changes nothing
    int dup_ok = 1;
    for(int i = 0; i < N; i += 97)
        if(!removed[i + 1] && event_heap_push(&h, i + 1, 0, 0) != 0) dup_ok = 0;
    check("large: duplicates refused", dup_ok && event_heap_count(&h) == N - removed_n);
    // Pop half, then put back the popped, as a rolled-back batch does
    EventHeapEntry_t* popped = malloc(sizeof(EventHeapEntry_t) * N);
    int np = 0;
    for(int i = 0; i < (N - removed_n) / 2; i++)
        event_heap_pop(&h, &popped[np++]);
    for(int i = 0; i < np; i++)
        event_heap_push(&h, popped[i].id, popped[i].game_t, popped[i].action);
    check("large: popped and put back", event_heap_count(&h) == N - removed_n);
    // The order is the sort of what is left
    int nleft = 0;
    for(int i = 0; i < N; i++)
        if(!removed[all[i].id]) all[nleft++] = all[i];
    qsort(all, (size_t)nleft, sizeof(EventHeapEntry_t), qsort_cmp);
    int order_ok = 1;
    for(int i = 0; i < nleft; i++) {
        if(!event_heap_pop(&h, &e) || e.id != all[i].id) { order_ok = 0; break; }
    }
    check("large: comes out as ORDER BY game_t, action, id", order_ok && event_heap_count(&h) == 0);
    event_heap_free(&h);
    free(all); free(removed); free(popped);

    // The engine's heap: off until enabled; staged events wait for a commit
    int owner_a = 0, owner_b = 0;
    check("global: off by default", !event_heap_on() && event_heap_global() == NULL);
    event_heap_stage(&owner_a, 1, 10, 1);
    check("global: staging while off does nothing", event_heap_stage_commit(&owner_a) == 0);
    event_heap_enable();
    EventHeap_t* g = event_heap_global();
    event_heap_stage(&owner_a, 2, 10, 1);
    event_heap_stage(&owner_a, 3, 5, 1);
    event_heap_stage(&owner_b, 4, 1, 1);
    check("global: staged events aren't in the heap yet", g && event_heap_count(g) == 0);
    check("global: a commit adds that connection's", event_heap_stage_commit(&owner_a) == 2 && event_heap_count(g) == 2);
    check("global: in order", event_heap_peek(g)->id == 3);
    event_heap_stage_discard(&owner_b);
    check("global: a rollback forgets that connection's", event_heap_stage_commit(&owner_b) == 0 && !event_heap_contains(g, 4));
    event_heap_stage(&owner_b, 2, 10, 1);
    check("global: one already in isn't added twice", event_heap_stage_commit(&owner_b) == 0 && event_heap_count(g) == 2);

    if(failures) {
        printf("%d check(s) failed\n", failures);
        return 1;
    }
    printf("event heap: all checks passed\n");
    return 0;
}
