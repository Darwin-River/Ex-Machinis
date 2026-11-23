/* The engine takes its events' ids itself (#694 stage 4 step 6): see event_ids.h */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "event_ids.h"

static int g_flag = -1;
static EventIdStats_t g_stats;
static long long g_minute_at = -1;

int event_ids_flag_on(void)
{
    if(g_flag < 0) {
        const char* v = getenv("ENGINE_OWNS_EVENTS");
        g_flag = v && atoi(v) == 1;
    }
    return g_flag;
}

void event_ids_flag_reset(void)
{
    g_flag = -1;
}

int event_ids_placeholder_sql(char* buf, size_t len, int n)
{
    static const char head[] = "INSERT INTO events (game_t) VALUES ";
    static const char row[] = "(-1)";
    if(n < 1 || n > EVENT_IDS_CHUNK || !buf)
        return -1;
    // the head, n rows, n - 1 commas, the terminator
    size_t need = sizeof(head) - 1 + (size_t)n * (sizeof(row) - 1) + (size_t)(n - 1) + 1;
    if(need > len)
        return -1;
    char* p = buf;
    memcpy(p, head, sizeof(head) - 1);
    p += sizeof(head) - 1;
    for(int i = 0; i < n; i++) {
        if(i) *p++ = ',';
        memcpy(p, row, sizeof(row) - 1);
        p += sizeof(row) - 1;
    }
    *p = '\0';
    return (int)(p - buf);
}

void event_ids_explicit(int id, char* column, size_t column_len, char* value, size_t value_len)
{
    if(id > 0) {
        snprintf(column, column_len, "id, ");
        snprintf(value, value_len, "%d, ", id);
    } else {
        if(column_len) column[0] = '\0';
        if(value_len) value[0] = '\0';
    }
}

EventIdStats_t* event_ids_stats(void)
{
    return &g_stats;
}

void event_ids_notice(char* buf, size_t len, int on, int check)
{
    int n = snprintf(buf, len,
        "NOTICE: ENGINE_OWNS_EVENTS=%d: last minute [%lld] events inserted with ids this engine reserved "
        "([%lld] reservations of [%lld] ids), [%lld] by AUTO_INCREMENT, [%lld] reservations failed, "
        "[%lld] duplicate ids",
        on, g_stats.inserted, g_stats.reservations, g_stats.ids, g_stats.auto_inserted,
        g_stats.fallbacks, g_stats.duplicates);
    if(check && n > 0 && (size_t)n < len)
        snprintf(buf + n, len - n,
            "; check: [%lld] reservations, [%lld] not consecutive; [%lld] inserts, [%lld] below an id "
            "the drone already had",
            g_stats.check_ranges, g_stats.check_gaps, g_stats.check_inserts, g_stats.check_inversions);
    memset(&g_stats, 0, sizeof(g_stats));
}

int event_ids_minute_due(long long now_ms)
{
    if(g_minute_at < 0) {
        g_minute_at = now_ms;
        return 0;
    }
    if(now_ms - g_minute_at < 60000)
        return 0;
    g_minute_at = now_ms;
    return 1;
}
