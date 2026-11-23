/* The engine's own event ids (#694 stage 4 step 6, event_ids.h): the
   statements' text, the flag and the NOTICE, without a database */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "event_ids.h"

static int failures = 0;

#define CHECK(cond, ...) do { if(!(cond)) { failures++; printf("FAIL %s:%d: ", __FILE__, __LINE__); \
    printf(__VA_ARGS__); printf("\n"); } } while(0)

static void test_placeholders(void)
{
    char buf[64 + EVENT_IDS_CHUNK * 5];
    int n = event_ids_placeholder_sql(buf, sizeof(buf), 1);
    CHECK(n > 0 && strcmp(buf, "INSERT INTO events (game_t) VALUES (-1)") == 0, "one row: [%s]", buf);
    n = event_ids_placeholder_sql(buf, sizeof(buf), 3);
    CHECK(n == (int)strlen(buf) && strcmp(buf, "INSERT INTO events (game_t) VALUES (-1),(-1),(-1)") == 0,
          "three rows: [%s]", buf);
    // As many rows as the chunk fits in the engine's buffer
    n = event_ids_placeholder_sql(buf, sizeof(buf), EVENT_IDS_CHUNK);
    CHECK(n > 0 && n == (int)strlen(buf), "a full chunk");
    int rows = 0;
    for(const char* p = buf; (p = strstr(p, "(-1)")); p++) rows++;
    CHECK(rows == EVENT_IDS_CHUNK, "a full chunk has %d rows", rows);
    // Out of range, or no room
    CHECK(event_ids_placeholder_sql(buf, sizeof(buf), 0) == -1, "no rows");
    CHECK(event_ids_placeholder_sql(buf, sizeof(buf), EVENT_IDS_CHUNK + 1) == -1, "more than a chunk");
    char small[40];
    CHECK(event_ids_placeholder_sql(small, sizeof(small), 2) == -1, "too small a buffer");
    CHECK(event_ids_placeholder_sql(buf, 39, 1) == -1,
          "one byte short");
    CHECK(event_ids_placeholder_sql(buf, 40, 1) == 39, "just room");
}

static void test_explicit(void)
{
    char column[8], value[24];
    event_ids_explicit(0, column, sizeof(column), value, sizeof(value));
    CHECK(column[0] == '\0' && value[0] == '\0', "AUTO_INCREMENT: nothing added");
    event_ids_explicit(-5, column, sizeof(column), value, sizeof(value));
    CHECK(column[0] == '\0' && value[0] == '\0', "no negative ids");
    event_ids_explicit(123297705, column, sizeof(column), value, sizeof(value));
    CHECK(strcmp(column, "id, ") == 0 && strcmp(value, "123297705, ") == 0, "an id: [%s] [%s]", column, value);
    event_ids_explicit(2147483647, column, sizeof(column), value, sizeof(value));
    CHECK(strcmp(value, "2147483647, ") == 0, "the largest: [%s]", value);
    // The INSERT built as db_insert_event builds it: unchanged without an id
    char sql[200];
    event_ids_explicit(0, column, sizeof(column), value, sizeof(value));
    snprintf(sql, sizeof(sql), "INSERT INTO events (%stimestamp, game_t) VALUES(%sNOW(), 5)", column, value);
    CHECK(strcmp(sql, "INSERT INTO events (timestamp, game_t) VALUES(NOW(), 5)") == 0, "off: [%s]", sql);
    event_ids_explicit(42, column, sizeof(column), value, sizeof(value));
    snprintf(sql, sizeof(sql), "INSERT INTO events (%stimestamp, game_t) VALUES(%sNOW(), 5)", column, value);
    CHECK(strcmp(sql, "INSERT INTO events (id, timestamp, game_t) VALUES(42, NOW(), 5)") == 0, "on: [%s]", sql);
}

static void test_flag(void)
{
    unsetenv("ENGINE_OWNS_EVENTS");
    event_ids_flag_reset();
    CHECK(!event_ids_flag_on(), "off by default");
    setenv("ENGINE_OWNS_EVENTS", "1", 1);
    CHECK(!event_ids_flag_on(), "read once");
    event_ids_flag_reset();
    CHECK(event_ids_flag_on(), "on");
    setenv("ENGINE_OWNS_EVENTS", "0", 1);
    event_ids_flag_reset();
    CHECK(!event_ids_flag_on(), "0 is off");
    setenv("ENGINE_OWNS_EVENTS", "yes", 1);
    event_ids_flag_reset();
    CHECK(!event_ids_flag_on(), "only 1 is on");
    unsetenv("ENGINE_OWNS_EVENTS");
    event_ids_flag_reset();
}

static void test_notice(void)
{
    CHECK(event_ids_minute_due(1000) == 0, "the first call starts the minute");
    CHECK(event_ids_minute_due(60999) == 0, "not yet");
    CHECK(event_ids_minute_due(61000) == 1, "a minute");
    CHECK(event_ids_minute_due(61001) == 0, "and the next starts");

    EventIdStats_t* s = event_ids_stats();
    s->inserted = 7; s->reservations = 3; s->ids = 7; s->auto_inserted = 2; s->fallbacks = 1;
    s->check_ranges = 3; s->check_inserts = 9; s->check_inversions = 1;
    char line[600];
    event_ids_notice(line, sizeof(line), 1, 0);
    CHECK(strstr(line, "NOTICE: ENGINE_OWNS_EVENTS=1") && strstr(line, "[7] events inserted") &&
          strstr(line, "([3] reservations of [7] ids)") && strstr(line, "[2] by AUTO_INCREMENT") &&
          strstr(line, "[1] reservations failed") && !strstr(line, "check:"), "on: %s", line);
    CHECK(s->inserted == 0 && s->ids == 0 && s->check_inserts == 0, "the counts start again");
    s->check_inserts = 9; s->check_inversions = 1;
    event_ids_notice(line, sizeof(line), 0, 1);
    CHECK(strstr(line, "ENGINE_OWNS_EVENTS=0") && strstr(line, "check:") &&
          strstr(line, "[9] inserts, [1] below"), "check build: %s", line);
    char tiny[20];
    event_ids_notice(tiny, sizeof(tiny), 1, 1);
    CHECK(strlen(tiny) == sizeof(tiny) - 1, "cut, not overrun");
}

int main(void)
{
    test_placeholders();
    test_explicit();
    test_flag();
    test_notice();
    if(failures) {
        printf("test_event_ids: %d failed\n", failures);
        return 1;
    }
    printf("test_event_ids: all passed\n");
    return 0;
}
