/*
 * Checks the drones' commands kept in memory (wake_heap.c, #694 stage 4
 * step 4): due commands come out in the batch queries' order (station
 * drones', then loading drones' but their resumes, then the rest; each by
 * id), each command is in once and its key is replaced, not duplicated,
 * removal works from anywhere, a shard keeps only its drones, and staged
 * changes wait for their connection's commit. Run with: make test
 */
#include <stdio.h>
#include <stdlib.h>

#include "wake_heap.h"

static int failures = 0;
static void check(const char *what, int ok)
{
    printf("%s %s\n", ok ? "ok  " : "FAIL", what);
    if(!ok) failures++;
}

static unsigned int rng = 4242;
static unsigned int next_rand(void) { rng = rng * 1103515245u + 12345u; return (rng >> 8) & 0xffffff; }

/* Pops every ready entry: 1 when the ids come as want */
static int pops_as(WakeHeap_t* h, const int* want, int n)
{
    int ok = 1;
    for(int i = 0; i < n; i++) {
        WakeEntry_t* e = wake_heap_pop_ready(h);
        if(!e || e->id != want[i]) ok = 0;
    }
    return ok && wake_heap_pop_ready(h) == NULL;
}

typedef struct { int tier; int id; } Key_t;
static int key_cmp(const void* a, const void* b)
{
    const Key_t* x = a; const Key_t* y = b;
    if(x->tier != y->tier) return x->tier - y->tier;
    return x->id - y->id;
}

int main(void)
{
    WakeHeap_t h;

    // The tiers
    check("tier: a station drone's command first", wake_tier(WAKE_STATION) == 0 && wake_tier(WAKE_STATION | WAKE_RESUME) == 0);
    check("tier: a loading drone's command next", wake_tier(WAKE_LOADING) == 1);
    check("tier: but not its resume", wake_tier(WAKE_LOADING | WAKE_RESUME) == 2);
    check("tier: the rest", wake_tier(0) == 2 && wake_tier(WAKE_RESUME) == 2);

    // Order and ties: (tier, id), whatever the due time
    wake_heap_init(&h, 1, 0);
    wake_heap_put(&h, 50, 1, 100, 0);
    wake_heap_put(&h, 10, 2, 90, WAKE_RESUME);
    wake_heap_put(&h, 70, 3, 80, WAKE_STATION | WAKE_RESUME);
    wake_heap_put(&h, 60, 4, 70, WAKE_LOADING);
    wake_heap_put(&h, 20, 5, 60, WAKE_LOADING | WAKE_RESUME);
    wake_heap_put(&h, 65, 6, 50, WAKE_STATION);
    wake_heap_put(&h, 99, 7, 500, WAKE_STATION);         // not due
    wake_heap_put(&h, 98, 8, WAKE_UNSTAMPED, WAKE_STATION); // not timed
    check("nothing ready before release", wake_heap_peek_ready(&h) == NULL);
    check("release moves the due ones", wake_heap_release(&h, 100) == 6);
    int order1[] = { 65, 70, 60, 10, 20, 50 };
    check("ready: station by id, loading, then the rest by id", pops_as(&h, order1, 6));
    check("not due, not timed: still waiting", wake_heap_find(&h, 99)->where == WAKE_WAITING
          && wake_heap_find(&h, 98)->where == WAKE_UNSTAMPED_LIST);
    check("due at its time exactly", wake_heap_release(&h, 499) == 0 && wake_heap_release(&h, 500) == 1);
    const int* ids;
    check("the untimed are listed", wake_heap_unstamped(&h, &ids) == 1 && ids[0] == 98);
    wake_heap_free(&h);

    // Each command once: a new key replaces the old
    wake_heap_init(&h, 1, 0);
    check("put adds", wake_heap_put(&h, 5, 1, 100, 0) == 1 && wake_heap_count(&h) == 1);
    check("put again replaces", wake_heap_put(&h, 5, 1, 300, 0) == 0 && wake_heap_count(&h) == 1);
    check("the new key holds", wake_heap_release(&h, 299) == 0 && event_heap_count(&h.waiting) == 1);
    check("timed later from unstamped, once", wake_heap_put(&h, 6, 1, WAKE_UNSTAMPED, 0) == 1
          && wake_heap_put(&h, 6, 1, 50, 0) == 0 && wake_heap_unstamped(&h, &ids) == 0);
    check("then due", wake_heap_release(&h, 60) == 1 && wake_heap_pop_ready(&h)->id == 6);
    WakeEntry_t* e = wake_heap_find(&h, 6);
    wake_heap_to_ready(&h, e);
    wake_heap_to_ready(&h, wake_heap_find(&h, 6));
    check("ready twice is once", event_heap_count(&h.ready) == 1);
    // A wrong tier is put right in place
    e = wake_heap_find(&h, 6);
    e->flags = WAKE_STATION;
    wake_heap_to_ready(&h, e);
    check("re-keyed, not duplicated", event_heap_count(&h.ready) == 1 && h.ready.items[0].game_t == 0);
    wake_heap_free(&h);

    // Removal from anywhere
    wake_heap_init(&h, 1, 0);
    for(int i = 1; i <= 40; i++)
        wake_heap_put(&h, i, i, i <= 20 ? 10 : (i <= 30 ? 1000 : WAKE_UNSTAMPED), 0);
    wake_heap_release(&h, 10);
    check("remove a ready one", wake_heap_remove(&h, 7) == 1);
    check("remove a waiting one", wake_heap_remove(&h, 25) == 1);
    check("remove an untimed one", wake_heap_remove(&h, 33) == 1 && wake_heap_unstamped(&h, &ids) == 9);
    check("remove one not there", wake_heap_remove(&h, 7) == 0 && wake_heap_remove(&h, 77) == 0);
    check("the rest are all there", wake_heap_count(&h) == 37 && event_heap_count(&h.ready) == 19
          && event_heap_count(&h.waiting) == 9);
    for(int i = 1; i <= 40; i++)
        if(i != 7 && i != 25 && i != 33 && !wake_heap_find(&h, i)) check("found after removals", 0);
    wake_heap_free(&h);

    // Held for an action; a drone's turn; to recheck; delayed and deleted resumes
    wake_heap_init(&h, 1, 0);
    wake_heap_put(&h, 1, 9, 10, WAKE_RESUME);
    wake_heap_put(&h, 2, 9, 10, 0);
    wake_heap_put(&h, 3, 8, 10, WAKE_RESUME);
    wake_heap_release(&h, 10);
    e = wake_heap_pop_ready(&h);
    wake_heap_to_waiting(&h, e, 5000, 1);   // its action's next event
    check("held: waits", wake_heap_release(&h, 100) == 0 && wake_heap_find(&h, 1)->where == WAKE_WAITING);
    check("held: its drone's turn sends it to recheck", wake_heap_drone_turned(&h, 9) == 1
          && wake_heap_find(&h, 1)->where == WAKE_RECHECK);
    check("recheck: ready at the next release", wake_heap_release(&h, 100) == 1 && wake_heap_find(&h, 1)->where == WAKE_READY);
    check("a turn moves only held ones", wake_heap_drone_turned(&h, 9) == 0);
    // Held until its action's next event: then only the action is asked about
    e = wake_heap_find(&h, 3);
    wake_heap_to_waiting(&h, e, 200, 77);
    check("held: not due before its action's event", wake_heap_release(&h, 199) == 0);
    wake_heap_release(&h, 200);
    check("held: then listed to ask about its action, not ready", wake_heap_find(&h, 3)->where == WAKE_HELD_DUE
          && wake_heap_held_due(&h, &ids) == 1 && ids[0] == 3 && wake_heap_find(&h, 3)->action == 77);
    check("held: listed once", wake_heap_held_due(&h, &ids) == 1);
    wake_heap_to_ready(&h, wake_heap_find(&h, 3));
    check("held: let go when the action is done", wake_heap_held_due(&h, &ids) == 0
          && wake_heap_find(&h, 3)->where == WAKE_READY && wake_heap_find(&h, 3)->action == 0);
    check("delay: a drone's resumes", wake_heap_delay_resumes(&h, 9, 300) == 1 && wake_heap_find(&h, 1)->deliver_at == 310
          && wake_heap_find(&h, 1)->where == WAKE_WAITING && wake_heap_find(&h, 2)->where == WAKE_READY);
    check("delete: a drone's resumes", wake_heap_remove_resumes(&h, 9) == 1 && !wake_heap_find(&h, 1)
          && wake_heap_find(&h, 2) && wake_heap_find(&h, 3));
    check("drone flags from its command", wake_heap_drone_flags(&h, 2, 9) == 0
          && wake_heap_drone_flags(&h, 99, 9) == WAKE_UNKNOWN_DRONE);
    wake_heap_free(&h);

    // Shards: only this shard's drones
    wake_heap_init(&h, 2, 1);
    check("shard: another's drone is left out", wake_heap_put(&h, 1, 4, 10, 0) == -2 && wake_heap_count(&h) == 0);
    check("shard: its own is in", wake_heap_put(&h, 2, 5, 10, 0) == 1 && wake_heap_count(&h) == 1);
    wake_heap_free(&h);

    // Large: keys that are early (a drone that has launched since, a
    // delayed resume) are put right when looked at, and what comes out is
    // exactly the sort of the true keys of the runnable commands
    enum { N = 50000 };
    Key_t* truth = malloc(sizeof(Key_t) * (N + 1));
    unsigned char* true_flags = malloc(N + 1);
    long long* true_due = malloc(sizeof(long long) * (N + 1));
    unsigned char* runnable = malloc(N + 1);
    wake_heap_init(&h, 1, 0);
    for(int id = 1; id <= N; id++) {
        unsigned int f = next_rand() % 8;
        true_flags[id] = (unsigned char)f;
        true_due[id] = next_rand() % 2000;
        runnable[id] = (next_rand() % 5) != 0;
        // The memory's key is never later: same or a better tier, same or earlier time
        unsigned int known = f | ((next_rand() % 4) == 0 ? WAKE_STATION : 0);
        long long known_due = true_due[id] - ((next_rand() % 3) == 0 ? (long long)(next_rand() % 500) : 0);
        wake_heap_put(&h, id, id, known_due, known);
    }
    long long now = 1500;
    wake_heap_release(&h, now);
    int out_n = 0, ok = 1, checks = 0;
    Key_t* out = malloc(sizeof(Key_t) * N);
    for(;;) {
        WakeEntry_t* top = wake_heap_peek_ready(&h);
        if(!top) break;
        if(top->confirmed == 1) {
            top = wake_heap_pop_ready(&h);
            out[out_n].tier = wake_tier(top->flags);
            out[out_n++].id = top->id;
            continue;
        }
        // The check, as db_wake.c's: true flags and due time
        top = wake_heap_pop_ready(&h);
        checks++;
        int id = top->id;
        top->flags = true_flags[id];
        top->deliver_at = true_due[id];
        if(true_due[id] > now) wake_heap_to_waiting(&h, top, true_due[id], 0);
        else if(!runnable[id]) wake_heap_to_recheck(&h, top);
        else { top->confirmed = 1; wake_heap_to_ready(&h, top); }
    }
    int want_n = 0;
    for(int id = 1; id <= N; id++)
        if(true_due[id] <= now && runnable[id]) {
            truth[want_n].tier = wake_tier(true_flags[id]);
            truth[want_n++].id = id;
        }
    qsort(truth, (size_t)want_n, sizeof(Key_t), key_cmp);
    if(out_n != want_n) ok = 0;
    for(int i = 0; ok && i < out_n; i++)
        if(out[i].id != truth[i].id) ok = 0;
    check("large: early keys put right give the queries' exact order", ok && checks > 0);
    int held_n = h.recheck_n;
    check("large: the held wait to be looked at again", held_n > 0 && wake_heap_release(&h, now) == held_n
          && event_heap_count(&h.ready) == held_n);
    wake_heap_free(&h);
    free(truth); free(true_flags); free(true_due); free(runnable); free(out);

    // The engine's memory: off until enabled; staged changes wait for a commit
    int owner_a = 0, owner_b = 0;
    check("global: off by default", !wake_heap_on() && wake_heap_global() == NULL);
    wake_heap_stage_put(&owner_a, 1, 1, 10, 0);
    check("global: staging while off does nothing", wake_heap_stage_commit(&owner_a) == 0);
    wake_heap_enable(2, 0);
    WakeHeap_t* g = wake_heap_global();
    wake_heap_stage_put(&owner_a, 2, 10, 10, WAKE_RESUME);
    wake_heap_stage_put(&owner_a, 3, 11, 10, 0);     // shard 1's: left out
    wake_heap_stage_put(&owner_b, 4, 12, 10, 0);
    check("global: staged commands aren't in yet", g && wake_heap_count(g) == 0);
    check("global: a commit adds that connection's, this shard's", wake_heap_stage_commit(&owner_a) == 1
          && wake_heap_count(g) == 1 && wake_heap_find(g, 2) && !wake_heap_find(g, 3));
    wake_heap_stage_discard(&owner_b);
    check("global: a rollback forgets that connection's", wake_heap_stage_commit(&owner_b) == 0 && !wake_heap_find(g, 4));
    // In order: a resume put, then delayed, then the command run deleted
    wake_heap_stage_put(&owner_a, 6, 10, 20, WAKE_RESUME);
    wake_heap_stage_delay_resumes(&owner_a, 10, 100);
    wake_heap_stage_remove(&owner_a, 2);
    check("global: removal and delay wait for the commit too", wake_heap_find(g, 2) && !wake_heap_find(g, 6));
    wake_heap_stage_commit(&owner_a);
    check("global: applied in order", !wake_heap_find(g, 2) && wake_heap_find(g, 6) && wake_heap_find(g, 6)->deliver_at == 120);
    wake_heap_stage_remove_resumes(&owner_b, 10);
    wake_heap_stage_discard(&owner_b);
    check("global: a rolled-back delete deletes nothing", wake_heap_find(g, 6) != NULL);
    wake_heap_stage_put(&owner_a, 6, 10, 20, WAKE_RESUME);
    wake_heap_stage_commit(&owner_a);
    check("global: staged twice is in once, with the new key", wake_heap_count(g) == 1 && wake_heap_find(g, 6)->deliver_at == 20);
    wake_heap_stage_remove_resumes(&owner_a, 10);
    wake_heap_stage_commit(&owner_a);
    check("global: a drone's resumes deleted at commit", wake_heap_count(g) == 0);

    if(failures) {
        printf("%d check(s) failed\n", failures);
        return 1;
    }
    printf("wake heap: all checks passed\n");
    return 0;
}
