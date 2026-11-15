/*
 * Seedlings (#637): the database side of their trading rules. See
 * db_seedling.h and seedling.h.
 */
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "db.h"
#include "db_seedling.h"
#include "engine.h"
#include "seedling.h"
#include "trace.h"

/* The first column of the first row as a number (dflt when none or on error) */
static long long sd_value(long long dflt, const char* fmt, ...)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = engine_get_db_connection();
    va_list args;

    va_start(args, fmt);
    vsnprintf(query_text, DB_MAX_SQL_QUERY_LEN, fmt, args);
    va_end(args);

    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return dflt;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    long long value = (row && row[0]) ? atoll(row[0]) : dflt;
    if(res) mysql_free_result(res);
    return value;
}

/* Both sides of a transfer in one query: each drone's company, and whether it
   is a seedling or the House */
static void sd_sides(int from, int to, SeedlingSide_t* a, SeedlingSide_t* b)
{
    SeedlingSide_t none = {0, 0, 0};
    *a = none;
    *b = none;
    DbConnection_t* connection = engine_get_db_connection();
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "SELECT a.agent_id, c.company_id, c.seedling, c.house FROM agents a "
        "JOIN companies c ON c.company_id = a.company_id WHERE a.agent_id IN (%d, %d)", from, to);
    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    MYSQL_ROW row;
    while(res && (row = mysql_fetch_row(res))) {
        SeedlingSide_t side = {row[1] ? atoi(row[1]) : 0, row[2] ? atoi(row[2]) : 0, row[3] ? atoi(row[3]) : 0};
        int drone = row[0] ? atoi(row[0]) : 0;
        if(drone == from) *a = side;
        if(drone == to) *b = side;
    }
    if(res) mysql_free_result(res);
}

ErrorCode_t db_seedling_transfer_outcome(Event_t* from, Event_t* to, int place, int* outcome, int* buy_company)
{
    *outcome = 0;
    *buy_company = 0;
    SeedlingSide_t a, b;
    sd_sides(from->drone_id, to->drone_id, &a, &b);
    if(!a.seedling && !b.seedling)
        return ENGINE_OK;

    long long units = llabs((long long)to->new_quantity);
    long long stock = 0, bought = 0;
    int permille = 100;
    if(b.seedling && a.house && a.company != b.company) {
        // What the House has on sale here (its sell order's units above the
        // stock it keeps, as the market shows them), plus what this seedling
        // has bought today: the day's cap doesn't shrink as it buys (#651)
        bought = sd_value(0, "SELECT units FROM seedling_buys WHERE company_id = %d AND place = %d AND resource = %d "
                             "AND day = %lld", b.company, place, to->resource_id, (long long)(to->game_t / 86400));
        stock = bought + sd_value(0, "SELECT GREATEST(IFNULL((SELECT h.units FROM holdings h WHERE h.drone = o.drone "
                                     "AND h.resource = o.resource AND h.installed = 0 AND h.locked = 0), 0) - o.quantity, 0) "
                                     "FROM latest_orders o WHERE o.drone = %d AND o.resource = %d AND o.event_type = %d",
                                     from->drone_id, from->resource_id, SELL_EVENT_TYPE);
        permille = (int)sd_value(100, "SELECT seedling_buy_permille FROM house_rules WHERE id = 1");
    }
    *outcome = seedling_transfer_outcome(a, b, units, stock, bought, permille);
    if(*outcome != 0)
        engine_trace(TRACE_LEVEL_ALWAYS,
            "Transfer for action [%d] refused (%d): drone [%d] (company %d) to drone [%d] (company %d), "
            "a seedling trades only with the House, up to its share of the stock (%lld units, %lld bought today)",
            from->action_id, *outcome, from->drone_id, a.company, to->drone_id, b.company, stock, bought);
    else if(b.seedling && a.house && a.company != b.company)
        *buy_company = b.company;
    return ENGINE_OK;
}

ErrorCode_t db_seedling_record_buy(int company, Event_t* to, int place)
{
    if(company <= 0)
        return ENGINE_OK;
    long long units = llabs((long long)to->new_quantity);
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "INSERT INTO seedling_buys (company_id, place, resource, day, units) VALUES (%d, %d, %d, %lld, %lld) "
        "ON DUPLICATE KEY UPDATE units = units + VALUES(units)",
        company, place, to->resource_id, (long long)(to->game_t / 86400), units);
    DbConnection_t* connection = engine_get_db_connection();
    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    return ENGINE_OK;
}

int db_order_lock_outcome(Event_t* event)
{
    if(event->event_type != SELL_EVENT_TYPE && event->event_type != BUY_EVENT_TYPE)
        return 0;
    DbConnection_t* connection = engine_get_db_connection();
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        // The lock runs from when the order took its present terms: posting
        // the same order again doesn't restart it (#650)
        "SELECT o.price, o.quantity, (SELECT MIN(e.game_t) FROM events e WHERE e.drone = o.drone "
        "AND e.resource = o.resource AND e.event_type = o.event_type AND e.outcome = 1 "
        "AND e.id > IFNULL((SELECT MAX(x.id) FROM events x WHERE x.drone = o.drone AND x.resource = o.resource "
        "AND x.event_type = o.event_type AND x.outcome = 1 "
        "AND (x.new_credits <> o.price OR x.new_quantity <> o.quantity)), 0)) "
        "FROM latest_orders o "
        "JOIN agents a ON a.agent_id = o.drone JOIN companies c ON c.company_id = a.company_id "
        "WHERE o.drone = %d AND o.resource = %d AND o.event_type = %d AND o.is_open = 1 "
        "AND (c.seedling = 1 OR c.owner_id IS NOT NULL)",
        event->drone_id, event->resource_id, event->event_type);
    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return 0;
    }
    MYSQL_RES* res = mysql_store_result(connection->hndl);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    int outcome = 0;
    if(row && row[0] && row[1] && row[2]) {
        long long lock = sd_value(12, "SELECT order_lock_hours FROM house_rules WHERE id = 1") * 3600;
        outcome = order_lock_outcome(event->event_type == BUY_EVENT_TYPE, atoll(row[0]), atoll(row[1]),
                                     (long long)event->new_credits, (long long)event->new_quantity,
                                     (long long)event->game_t - atoll(row[2]), lock);
        if(outcome)
            engine_trace(TRACE_LEVEL_ALWAYS, "Order event [%d] of drone [%d] refused: its order of %s game hours "
                "can't yet be withdrawn, cut or worsened", event->event_id, event->drone_id, row[2]);
    }
    if(res) mysql_free_result(res);
    return outcome;
}

long long db_seedling_skim(int company, long long credits, long long game_t)
{
    // Only a seedling (debt 0 otherwise); its cap in thousandths of its debt
    long long debt = sd_value(0, "SELECT IF(seedling = 1, debt, 0) FROM companies WHERE company_id = %d", company);
    if(debt <= 0)
        return 0;
    int permille = (int)sd_value(0, "SELECT seedling_credit_cap_permille FROM house_rules WHERE id = 1");
    long long over = seedling_over_cap(credits, debt, permille);
    if(over <= 0)
        return 0;
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    snprintf(query_text, DB_MAX_SQL_QUERY_LEN,
        "INSERT INTO seedling_skims (company_id, game_t, amount) VALUES (%d, %lld, %lld)", company, game_t, over);
    DbConnection_t* connection = engine_get_db_connection();
    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return 0;
    }
    engine_trace(TRACE_LEVEL_ALWAYS, "Seedling [%d] over its cap: %lld credits go to the bank", company, over);
    return over;
}
