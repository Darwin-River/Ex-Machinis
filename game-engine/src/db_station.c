/*
 * House drop stations (#324 step 10): recording offers and orders, and
 * clearing batches. See db_station.h.
 */
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "db.h"
#include "db_station.h"
#include "engine.h"
#include "game_time.h"
#include "station.h"
#include "trace.h"

#define MAX_BATCH_OFFERS 200

/* Runs one statement */
static ErrorCode_t st_exec(const char* fmt, ...)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = engine_get_db_connection();
    va_list args;

    va_start(args, fmt);
    vsnprintf(query_text, DB_MAX_SQL_QUERY_LEN, fmt, args);
    va_end(args);

    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    return ENGINE_OK;
}

/* Runs a SELECT; the caller frees the result (NULL on error) */
static MYSQL_RES* st_select(const char* fmt, ...)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    DbConnection_t* connection = engine_get_db_connection();
    va_list args;

    va_start(args, fmt);
    vsnprintf(query_text, DB_MAX_SQL_QUERY_LEN, fmt, args);
    va_end(args);

    if(mysql_query(connection->hndl, query_text)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return NULL;
    }
    return mysql_store_result(connection->hndl);
}

/* The first column of the first row as a number (dflt when none) */
static long long st_value(long long dflt, const char* fmt, ...)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    va_list args;

    va_start(args, fmt);
    vsnprintf(query_text, DB_MAX_SQL_QUERY_LEN, fmt, args);
    va_end(args);

    MYSQL_RES* res = st_select("%s", query_text);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    long long value = (row && row[0]) ? atoll(row[0]) : dflt;
    if(res) mysql_free_result(res);
    return value;
}

static long long st_today(void)
{
    long long since = game_time_now_seconds() - (long long)game_time_epoch();
    return since > 0 ? since / 86400 : 0;
}

ErrorCode_t db_station_record(Event_t* event)
{
    int side = (event->event_type == STATION_OFFER_EVENT_TYPE) ? STATION_BUYS : STATION_SELLS;
    int units = abs(event->new_quantity);
    long long batch_t = station_batch_end(game_time_now_seconds());

    MYSQL_RES* res = st_select(
        "SELECT s.id, a.company_id FROM agents a JOIN stations s ON s.object_id = a.object_id "
        "JOIN station_goods g ON g.station_id = s.id AND g.resource = %d AND g.side = %d AND g.from_day <= %lld "
        "WHERE a.agent_id = %d LIMIT 1",
        event->resource_id, side, st_today(), event->drone_id);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    int station = (row && row[0]) ? atoi(row[0]) : 0;
    int company = (row && row[1]) ? atoi(row[1]) : 0;
    if(res) mysql_free_result(res);

    if(!station || units <= 0) {
        engine_trace(TRACE_LEVEL_ALWAYS, "Drone [%d]: no House station here %s resource [%d]",
            event->drone_id, side == STATION_BUYS ? "buys" : "sells", event->resource_id);
        event->outcome = OUTCOME_NO_STATION;
        return ENGINE_LOGIC_ERROR;
    }

    ErrorCode_t result = st_exec(
        "INSERT INTO station_offers (station_id, side, resource, units, drone, company_id, action_id, batch_t) "
        "VALUES (%d, %d, %d, %d, %d, %d, %d, %lld)",
        station, side, event->resource_id, units, event->drone_id, company, event->action_id, batch_t);

    if(result == ENGINE_OK)
        engine_trace(TRACE_LEVEL_ALWAYS, "Drone [%d] %s [%d] units of resource [%d] at station [%d], batch clears at game time [%lld]",
            event->drone_id, side == STATION_BUYS ? "offers" : "orders", units, event->resource_id, station, batch_t);
    return result;
}

/* Changes a company's credits and records it as a credit event for the
   drone (type 6 or 7, new_credits = the new balance) */
static ErrorCode_t st_credit(int company, int drone, int action, int resource, long long amount)
{
    if(amount == 0)
        return ENGINE_OK;

    ErrorCode_t result = st_exec("UPDATE companies SET credits = credits + (%lld) WHERE company_id = %d", amount, company);
    long long balance = st_value(0, "SELECT credits FROM companies WHERE company_id = %d", company);

    Event_t ev;
    memset(&ev, 0, sizeof(ev));
    ev.event_type = amount > 0 ? INCREMENT_CREDITS_EVENT_TYPE : DECREMENT_CREDITS_EVENT_TYPE;
    ev.action_id = action;
    ev.logged = 1;
    ev.outcome = OUTCOME_OK;
    ev.drone_id = drone;
    ev.resource_id = resource;
    ev.new_quantity = NULL_VALUE;
    ev.new_credits = (int)balance;
    ev.new_location = NULL_VALUE;
    ev.new_transmission = NULL_VALUE;
    ev.new_cargo = NULL_VALUE;
    if(result == ENGINE_OK)
        result = db_insert_event_e(&ev);
    return result;
}

/* Puts units in a drone's hold: an action of the delivery protocol with one
   increment event, which the events engine processes like any other */
static ErrorCode_t st_deliver(int drone, int resource, int units, int mass)
{
    if(units <= 0)
        return ENGINE_OK;

    ErrorCode_t result = st_exec("INSERT INTO actions (drone, protocol, multiplier, aborted) VALUES (%d, %d, 1, 0)",
        drone, STATION_DELIVERY_PROTOCOL_ID);
    if(result != ENGINE_OK)
        return result;

    Event_t ev;
    memset(&ev, 0, sizeof(ev));
    ev.event_type = INCREMENT_INVENTORY_EVENT_TYPE;
    ev.action_id = (int)mysql_insert_id(engine_get_db_connection()->hndl);
    ev.logged = 1;
    ev.outcome = OUTCOME_NO_OUTCOME;
    ev.drone_id = drone;
    ev.resource_id = resource;
    ev.new_quantity = units;              // the change: the events engine works out the total
    ev.new_credits = NULL_VALUE;
    ev.new_location = NULL_VALUE;
    ev.new_transmission = NULL_VALUE;
    ev.new_cargo = units * mass;
    return db_insert_event_e(&ev);
}

/* Clears one batch (see db_station_clear_due) */
static ErrorCode_t st_clear_batch(int station, int resource, int side, long long batch_t)
{
    long long now = game_time_now_seconds();
    int ids[MAX_BATCH_OFFERS], drones[MAX_BATCH_OFFERS], companies[MAX_BATCH_OFFERS], actions[MAX_BATCH_OFFERS];
    long long units[MAX_BATCH_OFFERS], fill[MAX_BATCH_OFFERS];
    int n = 0;

    // Once only: a batch already recorded was cleared (and paid) before
    if(st_value(0, "SELECT COUNT(*) FROM station_batches WHERE station_id = %d AND resource = %d AND side = %d AND batch_t = %lld",
                station, resource, side, batch_t) > 0) {
        engine_trace(TRACE_LEVEL_ALWAYS, "Station [%d] batch [%lld] for resource [%d] was already cleared", station, batch_t, resource);
        return st_exec("UPDATE station_offers SET cleared_t = %lld WHERE station_id = %d AND resource = %d AND side = %d "
                       "AND batch_t = %lld AND cleared_t IS NULL", now, station, resource, side, batch_t);
    }

    // The batch's offers. An offer counts only if its action went through:
    // for an offer to sell, that means its goods left the hold (escrow)
    MYSQL_RES* res = st_select(
        "SELECT o.id, o.units, o.drone, o.company_id, o.action_id, "
        "IFNULL(a.aborted, 1) = 0 AND (o.side = %d OR EXISTS (SELECT 1 FROM events e WHERE e.action = o.action_id "
        "AND e.event_type = %d AND e.resource = o.resource AND e.outcome = %d)) "
        "FROM station_offers o LEFT JOIN actions a ON a.id = o.action_id "
        "WHERE o.station_id = %d AND o.resource = %d AND o.side = %d AND o.batch_t = %lld AND o.cleared_t IS NULL "
        "ORDER BY o.id LIMIT %d",
        STATION_SELLS, DECREMENT_INVENTORY_EVENT_TYPE, OUTCOME_OK,
        station, resource, side, batch_t, MAX_BATCH_OFFERS);
    MYSQL_ROW row;
    while(res && n < MAX_BATCH_OFFERS && (row = mysql_fetch_row(res))) {
        ids[n] = atoi(row[0]);
        units[n] = (row[5] && atoi(row[5])) ? atoll(row[1]) : 0;   // didn't go through: nothing to clear
        drones[n] = atoi(row[2]);
        companies[n] = atoi(row[3]);
        actions[n] = atoi(row[4]);
        n++;
    }
    if(res) mysql_free_result(res);

    int base = (int)st_value(0, "SELECT price FROM station_goods WHERE station_id = %d AND resource = %d AND side = %d",
                             station, resource, side);
    long long daily = st_value(0, "SELECT daily_units FROM station_goods WHERE station_id = %d AND resource = %d AND side = %d",
                               station, resource, side);
    int mass = (int)st_value(0, "SELECT mass FROM resources WHERE id = %d", resource);

    long long asked = 0;
    for(int i = 0; i < n; i++)
        asked += units[i];

    int price = 0;
    long long filled = 0;
    ErrorCode_t result = ENGINE_OK;

    if(side == STATION_BUYS) {
        // The budget, refilled for the time since its last refill
        long long cap = st_value(0, "SELECT budget_cap FROM stations WHERE id = %d", station);
        long long refill = st_value(0, "SELECT refill_per_day FROM stations WHERE id = %d", station);
        long long budget = st_value(cap, "SELECT budget FROM station_state WHERE station_id = %d", station);
        long long since = now - st_value(now, "SELECT refilled_t FROM station_state WHERE station_id = %d", station);
        if(since > 0)
            budget += (refill * since) / 86400;
        if(budget > cap)
            budget = cap;

        // One price for the batch, from the curve for all it was offered;
        // as many units as the budget pays for, shared out
        price = station_buy_price(base, asked, daily);
        long long affordable = price > 0 ? budget / price : 0;
        filled = station_share(units, companies, n, affordable, fill);

        long long spent = 0;
        for(int i = 0; i < n && result == ENGINE_OK; i++) {
            long long paid = fill[i] * price;
            spent += paid;
            result = st_credit(companies[i], drones[i], actions[i], resource, paid);
            if(result == ENGINE_OK)
                result = st_deliver(drones[i], resource, (int)(units[i] - fill[i]), mass);   // what it didn't buy
            if(result == ENGINE_OK)
                result = st_exec("UPDATE station_offers SET filled = %lld, price = %d, paid = %lld, cleared_t = %lld WHERE id = %d",
                                 fill[i], price, paid, now, ids[i]);
        }
        if(result == ENGINE_OK)
            result = st_exec("INSERT INTO station_state (station_id, budget, refilled_t) VALUES (%d, %lld, %lld) "
                             "ON DUPLICATE KEY UPDATE budget = VALUES(budget), refilled_t = VALUES(refilled_t)",
                             station, budget - spent, now);
    } else {
        // Selling: a batch's share of the daily supply, at the station's
        // price; each order as far as its company's credits and the drone's
        // free cargo space allow
        price = base;
        long long supply = daily / (86400 / STATION_BATCH_SECONDS);
        if(supply < 1)
            supply = 1;
        long long want[MAX_BATCH_OFFERS];
        for(int i = 0; i < n; i++) {
            long long credits = st_value(0, "SELECT credits FROM companies WHERE company_id = %d", companies[i]);
            for(int j = 0; j < i; j++)          // what this company's earlier orders in the batch may take
                if(companies[j] == companies[i])
                    credits -= units[j] * price;
            long long capacity = st_value(0, "SELECT h.cargo_capacity FROM agents a JOIN hulls h ON h.id = a.hull_type "
                                             "WHERE a.agent_id = %d", drones[i]);
            long long cargo = st_value(0, "SELECT IFNULL(SUM(i.units * r.mass), 0) FROM sqInventory i JOIN resources r "
                                          "ON r.id = i.resource WHERE i.drone = %d", drones[i]);
            want[i] = units[i];
            if(price > 0 && want[i] > credits / price)
                want[i] = credits > 0 ? credits / price : 0;
            if(mass > 0 && want[i] > (capacity - cargo) / mass)
                want[i] = capacity > cargo ? (capacity - cargo) / mass : 0;
        }
        filled = station_share(want, companies, n, supply, fill);
        for(int i = 0; i < n && result == ENGINE_OK; i++) {
            long long paid = fill[i] * price;
            result = st_credit(companies[i], drones[i], actions[i], resource, -paid);
            if(result == ENGINE_OK)
                result = st_deliver(drones[i], resource, (int)fill[i], mass);
            if(result == ENGINE_OK)
                result = st_exec("UPDATE station_offers SET filled = %lld, price = %d, paid = %lld, cleared_t = %lld WHERE id = %d",
                                 fill[i], price, paid, now, ids[i]);
        }
    }

    if(result == ENGINE_OK)
        result = st_exec("INSERT INTO station_batches (station_id, resource, side, batch_t, units, filled, price, cleared_t) "
                         "VALUES (%d, %d, %d, %lld, %lld, %lld, %d, %lld)",
                         station, resource, side, batch_t, asked, filled, price, now);

    engine_trace(TRACE_LEVEL_ALWAYS, "Station [%d] cleared batch [%lld] for resource [%d] (%s): [%lld] units asked, [%lld] filled at [%d] (result [%d])",
        station, batch_t, resource, side == STATION_BUYS ? "buying" : "selling", asked, filled, price, result);
    return result;
}

ErrorCode_t db_station_clear_due(void)
{
    int stations[50], resources[50], sides[50];
    long long batches[50];
    int n = 0;

    ErrorCode_t result = db_reconnect(engine_get_db_connection());
    if(result != ENGINE_OK)
        return result;

    MYSQL_RES* res = st_select(
        "SELECT DISTINCT o.station_id, o.resource, o.side, o.batch_t FROM station_offers o "
        "WHERE o.cleared_t IS NULL AND o.batch_t <= %lld "
        // wait until the batch's offers have settled (an offer's goods leave
        // the hold a second after it is made)
        "AND NOT EXISTS (SELECT 1 FROM station_offers p JOIN events e ON e.action = p.action_id AND e.outcome = %d "
        "  WHERE p.station_id = o.station_id AND p.resource = o.resource AND p.side = o.side AND p.batch_t = o.batch_t "
        "  AND p.cleared_t IS NULL) "
        "ORDER BY o.batch_t, o.station_id, o.resource, o.side LIMIT 50",
        game_time_now_seconds(), OUTCOME_NO_OUTCOME);
    MYSQL_ROW row;
    while(res && n < 50 && (row = mysql_fetch_row(res))) {
        stations[n] = atoi(row[0]);
        resources[n] = atoi(row[1]);
        sides[n] = atoi(row[2]);
        batches[n] = atoll(row[3]);
        n++;
    }
    if(res) mysql_free_result(res);

    for(int i = 0; i < n && result == ENGINE_OK; i++)
        result = st_clear_batch(stations[i], resources[i], sides[i], batches[i]);
    return result;
}
