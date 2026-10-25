/*
 * Wrecks, plunder and company end (#324 step 12). See db_wreck.h.
 */
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "db.h"
#include "db_wreck.h"
#include "engine.h"
#include "game_time.h"
#include "trace.h"
#include "wreck.h"

#define MAX_WRECK_ROWS     100
#define MAX_TAX_COMPANIES  200

/* Runs one statement */
static ErrorCode_t wr_exec(const char* fmt, ...)
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
static MYSQL_RES* wr_select(const char* fmt, ...)
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
static long long wr_value(long long dflt, const char* fmt, ...)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    va_list args;

    va_start(args, fmt);
    vsnprintf(query_text, DB_MAX_SQL_QUERY_LEN, fmt, args);
    va_end(args);

    MYSQL_RES* res = wr_select("%s", query_text);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    long long value = (row && row[0]) ? atoll(row[0]) : dflt;
    if(res) mysql_free_result(res);
    return value;
}

/* A new action of the plunder protocol for a drone; its ID in *action */
static ErrorCode_t wr_haul_action(int drone, int* action)
{
    ErrorCode_t result = wr_exec("INSERT INTO actions (drone, protocol, multiplier, aborted) VALUES (%d, %d, 1, 0)",
                                 drone, PLUNDER_HAUL_PROTOCOL_ID);
    *action = (int)mysql_insert_id(engine_get_db_connection()->hndl);
    return result;
}

/* An inventory change for the events engine to apply: units (+ or -) of a
   resource, in the drone's hold or among its installed or locked parts */
static ErrorCode_t wr_inventory_event(int action, int drone, int resource, int installed, int locked,
                                      long long units, int mass)
{
    Event_t ev;
    memset(&ev, 0, sizeof(ev));
    ev.event_type = units > 0 ? INCREMENT_INVENTORY_EVENT_TYPE : DECREMENT_INVENTORY_EVENT_TYPE;
    ev.action_id = action;
    ev.logged = 1;
    ev.outcome = OUTCOME_NO_OUTCOME;
    ev.drone_id = drone;
    ev.resource_id = resource;
    ev.installed = installed;
    ev.locked = locked;
    ev.new_quantity = (int)units;          // the change: the events engine works out the total
    ev.new_credits = NULL_VALUE;
    ev.new_location = NULL_VALUE;
    ev.new_transmission = NULL_VALUE;
    ev.new_cargo = (int)(units * mass);
    return db_insert_event_e(&ev);
}

ErrorCode_t db_plunder(Event_t* event)
{
    int plunderer = event->drone_id;
    int wreck = abs(event->new_quantity);
    long long now = game_time_now_seconds();

    // Is it a wreck, here, not yet plundered? Whose it is doesn't matter
    // (#367 step 1: own-player wrecks were refused before)
    MYSQL_RES* res = wr_select(
        "SELECT w.plundered_at IS NULL, w.object_id = p.object_id "
        "FROM agents w JOIN agents p ON p.agent_id = %d "
        "WHERE w.agent_id = %d AND w.agent_id <> p.agent_id AND w.wrecked_at IS NOT NULL",
        plunderer, wreck);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    int found = row != NULL;
    int unplundered = found && row[0] && atoi(row[0]);
    int here = found && row[1] && atoi(row[1]);
    if(res) mysql_free_result(res);

    if(!found || !unplundered || !here) {
        engine_trace(TRACE_LEVEL_ALWAYS, "Drone [%d]: no unplundered wreck [%d] here", plunderer, wreck);
        event->outcome = OUTCOME_NO_WRECK;
        return ENGINE_LOGIC_ERROR;
    }

    // Once only
    ErrorCode_t result = wr_exec("UPDATE agents SET plundered_at = %lld, plundered_by = %d "
                                 "WHERE agent_id = %d AND plundered_at IS NULL", now, plunderer, wreck);
    if(result != ENGINE_OK)
        return result;

    int share = (int)wr_value(50, "SELECT plunder_share FROM house_rules WHERE id = 1");

    // Everything aboard: the latest total of each resource in the hold and
    // among its installed and locked parts
    int resources[MAX_WRECK_ROWS], installed[MAX_WRECK_ROWS], locked[MAX_WRECK_ROWS], masses[MAX_WRECK_ROWS];
    long long units[MAX_WRECK_ROWS];
    int n = 0;
    res = wr_select(
        "SELECT e.resource, e.installed, e.locked, e.new_quantity, IFNULL(r.mass, 0) FROM events e "
        "JOIN (SELECT MAX(id) AS id FROM events WHERE outcome = %d AND event_type IN (%d, %d) AND drone = %d "
        "GROUP BY resource, installed, locked) latest ON latest.id = e.id "
        "LEFT JOIN resources r ON r.id = e.resource WHERE e.new_quantity > 0 ORDER BY e.resource",
        OUTCOME_OK, INCREMENT_INVENTORY_EVENT_TYPE, DECREMENT_INVENTORY_EVENT_TYPE, wreck);
    while(res && n < MAX_WRECK_ROWS && (row = mysql_fetch_row(res))) {
        resources[n] = atoi(row[0]);
        installed[n] = row[1] ? atoi(row[1]) : 0;
        locked[n] = row[2] ? atoi(row[2]) : 0;
        units[n] = atoll(row[3]);
        masses[n] = row[4] ? atoi(row[4]) : 0;
        n++;
    }
    if(res) mysql_free_result(res);
    if(n == 0) {
        engine_trace(TRACE_LEVEL_ALWAYS, "Drone [%d] plunders wreck [%d]: nothing aboard", plunderer, wreck);
        return ENGINE_OK;
    }

    // It all leaves the wreck
    int loss = 0;
    result = wr_haul_action(wreck, &loss);
    for(int i = 0; i < n && result == ENGINE_OK; i++)
        result = wr_inventory_event(loss, wreck, resources[i], installed[i], locked[i], -units[i], masses[i]);

    // The plunderer's share arrives in its hold, one action per resource, so
    // what doesn't fit is lost on its own (the rest is destroyed)
    for(int i = 0; i < n && result == ENGINE_OK; i++) {
        long long total = units[i];
        int first = 1;
        for(int j = 0; j < i; j++)
            if(resources[j] == resources[i])
                first = 0;
        if(!first)
            continue;
        for(int j = i + 1; j < n; j++)
            if(resources[j] == resources[i])
                total += units[j];

        long long taken = wreck_share(total, share, wreck, resources[i]);
        engine_trace(TRACE_LEVEL_ALWAYS, "Drone [%d] plunders wreck [%d]: [%lld] of [%lld] units of resource [%d]",
            plunderer, wreck, taken, total, resources[i]);
        if(taken <= 0)
            continue;
        int haul = 0;
        result = wr_haul_action(plunderer, &haul);
        if(result == ENGINE_OK)
            result = wr_inventory_event(haul, plunderer, resources[i], 0, 0, taken, masses[i]);
    }
    return result;
}

/* Eliminates a company that can't pay its tax: its credits vanish and its
   drones become wrecks */
static ErrorCode_t wr_eliminate(int company, long long now)
{
    ErrorCode_t result = wr_exec("UPDATE companies SET credits = 0, eliminated_at = %lld, end_cause = 'tax' WHERE company_id = %d", now, company);

    int drones[MAX_WRECK_ROWS];
    int n = 0;
    MYSQL_RES* res = wr_select("SELECT agent_id FROM agents WHERE company_id = %d AND wrecked_at IS NULL LIMIT %d",
                               company, MAX_WRECK_ROWS);
    MYSQL_ROW row;
    while(res && n < MAX_WRECK_ROWS && (row = mysql_fetch_row(res)))
        drones[n++] = atoi(row[0]);
    if(res) mysql_free_result(res);

    for(int i = 0; i < n && result == ENGINE_OK; i++)
        result = db_wreck_drone(drones[i], "tax");

    engine_trace(TRACE_LEVEL_ALWAYS, "Company [%d] can't pay its tax: eliminated, [%d] drones wrecked", company, n);
    return result;
}

ErrorCode_t db_tax_due(void)
{
    long long now = game_time_now_seconds();

    MYSQL_RES* res = wr_select("SELECT tax_period, tax_grace, tax_flat, tax_permille FROM house_rules WHERE id = 1");
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    if(!row) {
        if(res) mysql_free_result(res);
        return ENGINE_OK;   // no rules, no taxes
    }
    int period = atoi(row[0]), grace = atoi(row[1]), flat = atoi(row[2]), permille = atoi(row[3]);
    mysql_free_result(res);
    if(period <= 0)
        return ENGINE_OK;

    // A company the tax office hasn't seen yet starts its grace period and
    // its first tax period now
    ErrorCode_t result = wr_exec("UPDATE companies SET founded_t = %lld WHERE founded_t IS NULL", now);
    if(result == ENGINE_OK)
        result = wr_exec("UPDATE companies SET taxed_t = %lld WHERE taxed_t IS NULL AND eliminated_at IS NULL", now);
    if(result != ENGINE_OK)
        return result;

    int companies[MAX_TAX_COMPANIES];
    long long credits[MAX_TAX_COMPANIES], founded[MAX_TAX_COMPANIES], taxed[MAX_TAX_COMPANIES];
    long long combined[MAX_TAX_COMPANIES];
    int n = 0;
    res = wr_select(
        "SELECT c.company_id, c.credits, c.founded_t, c.taxed_t, "
        "c.credits "   /* each company on its own credits (#367 step 1) */
        "FROM companies c WHERE c.eliminated_at IS NULL AND c.taxed_t + %d <= %lld ORDER BY c.company_id LIMIT %d",
        period, now, MAX_TAX_COMPANIES);
    while(res && n < MAX_TAX_COMPANIES && (row = mysql_fetch_row(res))) {
        companies[n] = atoi(row[0]);
        credits[n] = row[1] ? atoll(row[1]) : 0;
        founded[n] = row[2] ? atoll(row[2]) : now;
        taxed[n] = row[3] ? atoll(row[3]) : now;
        combined[n] = row[4] ? atoll(row[4]) : credits[n];
        n++;
    }
    if(res) mysql_free_result(res);

    for(int i = 0; i < n && result == ENGINE_OK; i++) {
        long long periods = tax_periods_due(now, taxed[i], period);
        if(periods <= 0)
            continue;
        long long next = taxed[i] + periods * period;

        // In its grace period: nothing to pay yet
        if(founded[i] + grace > now) {
            result = wr_exec("UPDATE companies SET taxed_t = %lld WHERE company_id = %d", next, companies[i]);
            continue;
        }

        // One bill however many periods have passed: periods missed while
        // the engine was down (or after the period was shortened) are
        // skipped, not charged all at once
        long long bill = tax_bill(flat, permille, combined[i]);
        if(credits[i] >= bill) {
            result = wr_exec("UPDATE companies SET credits = credits - %lld, taxed_t = %lld WHERE company_id = %d",
                             bill, next, companies[i]);
            if(result == ENGINE_OK)
                result = wr_exec("INSERT INTO company_taxes (company_id, charged_t, amount, credits_after, eliminated) "
                                 "VALUES (%d, %lld, %lld, %lld, 0)", companies[i], now, bill, credits[i] - bill);
            engine_trace(TRACE_LEVEL_ALWAYS, "Company [%d] pays [%lld] tax, [%lld] credits left",
                companies[i], bill, credits[i] - bill);
        } else {
            result = wr_exec("UPDATE companies SET taxed_t = %lld WHERE company_id = %d", next, companies[i]);
            if(result == ENGINE_OK)
                result = wr_exec("INSERT INTO company_taxes (company_id, charged_t, amount, credits_after, eliminated) "
                                 "VALUES (%d, %lld, %lld, 0, 1)", companies[i], now, credits[i] > 0 ? credits[i] : 0);
            if(result == ENGINE_OK)
                result = wr_eliminate(companies[i], now);
        }
    }
    return result;
}
