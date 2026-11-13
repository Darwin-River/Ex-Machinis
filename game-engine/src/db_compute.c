/*
 * The processor tax (#544). See db_compute.h.
 */
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>

#include "compute.h"
#include "db.h"
#include "db_compute.h"
#include "db_wreck.h"
#include "engine.h"
#include "game_time.h"
#include "trace.h"

#define MAX_COMPANIES 5000
#define MAX_DRONES 20000

static ErrorCode_t cp_exec(const char* fmt, ...)
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

static MYSQL_RES* cp_select(const char* fmt, ...)
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

static long long cp_value(long long dflt, const char* fmt, ...)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    va_list args;
    va_start(args, fmt);
    vsnprintf(query_text, DB_MAX_SQL_QUERY_LEN, fmt, args);
    va_end(args);
    MYSQL_RES* res = cp_select("%s", query_text);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    long long value = (row && row[0]) ? atoll(row[0]) : dflt;
    if(res) mysql_free_result(res);
    return value;
}

typedef struct { int company; long long credits, debt, amount, instructions; } Bill_t;

ErrorCode_t db_compute_bill_due(void)
{
    long long now = game_time_now_seconds();
    long long hour = now - now % COMPUTE_BILL_SECONDS;
    if(hour <= cp_value(0, "SELECT billed_t FROM compute_clock WHERE id = 1"))
        return ENGINE_OK;

    // The congestion tax (#620): while the clock runs slower than its target
    // because the engines can't keep up, thinking costs that many times more
    // (up to house_rules.congestion_cap)
    long long base = cp_value(0, "SELECT compute_tax_per_million FROM house_rules WHERE id = 1");
    double scale = cp_value(0, "SELECT ROUND(1000 * scale) FROM game_clock WHERE id = 1") / 1000.0;
    double target = cp_value(0, "SELECT ROUND(1000 * IFNULL(target_scale, scale)) FROM game_clock WHERE id = 1") / 1000.0;
    double cap = cp_value(0, "SELECT IFNULL(congestion_cap, 0) FROM house_rules WHERE id = 1");
    double congestion = compute_congestion(scale, target, cap);
    long long rate = compute_congested_rate(base, congestion);        // the top rate, for a busy company
    long long full_at = cp_value(0, "SELECT IFNULL(congestion_full_at, 0) FROM house_rules WHERE id = 1");
    long long per_credit = compute_instructions_per_credit(base);      // each company's own, below
    if(cp_exec("UPDATE compute_clock SET rate_now = %lld, congestion = %.3f WHERE id = 1", rate, congestion) != ENGINE_OK)
        return ENGINE_DB_QUERY_ERROR;
    if(congestion > 1)
        engine_trace(TRACE_LEVEL_ALWAYS, "Processor tax: %lld credits a million (%.2f times %lld: the clock runs at %.0f of %.0f)",
            rate, congestion, base, scale, target);
    ErrorCode_t result = ENGINE_OK;
    static Bill_t bills[MAX_COMPANIES];
    int n = 0;

    if(per_credit > 0) {
        // Each working drone's unbilled instructions, by company: the drones
        // still loading their released script are left out (loading is free;
        // the game engine squares their power_paid itself)
        MYSQL_RES* res = cp_select(
            "SELECT a.agent_id, a.company_id, a.instructions - a.power_paid FROM agents AS a "
            "JOIN companies AS c ON c.company_id = a.company_id AND c.eliminated_at IS NULL AND c.house = 0 "
            "WHERE a.release_vm IS NOT NULL AND a.instructions > a.power_paid ORDER BY a.company_id LIMIT %d", MAX_DRONES);
        if(!res)
            return ENGINE_DB_QUERY_ERROR;
        MYSQL_ROW row;
        int rate_company = -1;
        while(result == ENGINE_OK && (row = mysql_fetch_row(res))) {
            int drone = atoi(row[0]), company = atoi(row[1]);
            // A company's own rate (#620): its share of the congestion, by
            // how much its drones thought over the last game day
            if(company != rate_company) {
                rate_company = company;
                long long day = cp_value(0, "SELECT IFNULL(SUM(instructions), 0) FROM compute_bills "
                                            "WHERE company_id = %d AND charged_t > %lld", company, now - 86400);
                per_credit = compute_instructions_per_credit(
                    compute_congested_rate(base, compute_company_congestion(congestion, day, full_at)));
            }
            long long billed = 0;
            long long due = compute_credits_due(atoll(row[2]), per_credit, &billed);
            if(due <= 0)
                continue;
            if(n == 0 || bills[n - 1].company != company) {
                if(n >= MAX_COMPANIES)
                    break;
                bills[n].company = company;
                bills[n].amount = bills[n].instructions = 0;
                n++;
            }
            bills[n - 1].amount += due;
            bills[n - 1].instructions += billed;
            // Billed: the rest carries over to the next bill
            result = cp_exec("UPDATE agents SET power_paid = power_paid + %lld WHERE agent_id = %d", billed, drone);
        }
        mysql_free_result(res);
    }

    // Companies that owe from before, with nothing new billed: their debt is
    // collected as credits come in
    if(result == ENGINE_OK) {
        MYSQL_RES* res = cp_select("SELECT company_id FROM companies WHERE compute_debt > 0 AND eliminated_at IS NULL");
        MYSQL_ROW row;
        while(res && n < MAX_COMPANIES && (row = mysql_fetch_row(res))) {
            int company = atoi(row[0]), seen = 0;
            for(int i = 0; i < n && !seen; i++)
                seen = bills[i].company == company;
            if(!seen) {
                bills[n].company = company;
                bills[n].amount = bills[n].instructions = 0;
                n++;
            }
        }
        if(res) mysql_free_result(res);
        else result = ENGINE_DB_QUERY_ERROR;
    }

    // Each company pays what it can of its debt and the new bill
    for(int i = 0; i < n && result == ENGINE_OK; i++) {
        Bill_t* b = &bills[i];
        b->credits = cp_value(0, "SELECT credits FROM companies WHERE company_id = %d", b->company);
        b->debt = cp_value(0, "SELECT compute_debt FROM companies WHERE company_id = %d", b->company);
        long long owed = b->debt + b->amount;
        long long paid = compute_pay(b->credits, owed);
        if(paid > 0)
            result = db_credit_company(b->company, -paid, 0, 0, FUEL_RESOURCE_ID);
        if(result == ENGINE_OK && owed - paid != b->debt)
            result = cp_exec("UPDATE companies SET compute_debt = %lld WHERE company_id = %d", owed - paid, b->company);
        if(result == ENGINE_OK && (b->amount > 0 || paid > 0))
            result = cp_exec("INSERT INTO compute_bills (company_id, charged_t, instructions, amount, paid, debt_after) "
                             "VALUES (%d, %lld, %lld, %lld, %lld, %lld)",
                             b->company, now, b->instructions, b->amount, paid, owed - paid);
        if(owed - paid > 0)
            engine_trace(TRACE_LEVEL_ALWAYS, "Processor tax: company [%d] owes [%lld] after paying [%lld]",
                b->company, owed - paid, paid);
    }

    // A company broke (credits less what it owes at 0 or below) for
    // house_rules.dissolve_days game days straight dissolves (#547): it ends,
    // and every working drone becomes a wreck for anyone to plunder
    if(result == ENGINE_OK)
        result = cp_exec("UPDATE companies SET broke_since = %lld WHERE broke_since IS NULL AND eliminated_at IS NULL "
                         "AND house = 0 AND credits - compute_debt <= 0", now);
    if(result == ENGINE_OK)
        result = cp_exec("UPDATE companies SET broke_since = NULL WHERE broke_since IS NOT NULL "
                         "AND (credits - compute_debt > 0 OR eliminated_at IS NOT NULL)");
    long long dissolve_days = cp_value(0, "SELECT dissolve_days FROM house_rules WHERE id = 1");
    if(result == ENGINE_OK && dissolve_days > 0) {
        long long since = now - dissolve_days * 86400;
        MYSQL_RES* res = cp_select("SELECT company_id FROM companies WHERE broke_since IS NOT NULL AND broke_since <= %lld "
                                   "AND eliminated_at IS NULL AND house = 0 LIMIT %d", since, MAX_COMPANIES);
        int gone[MAX_COMPANIES];
        int g = 0;
        MYSQL_ROW row;
        while(res && g < MAX_COMPANIES && (row = mysql_fetch_row(res)))
            gone[g++] = atoi(row[0]);
        if(res) mysql_free_result(res);
        else result = ENGINE_DB_QUERY_ERROR;
        for(int i = 0; i < g && result == ENGINE_OK; i++) {
            result = cp_exec("UPDATE agents SET wrecked_at = %lld, wreck_cause = 'dissolved', launched_running = 0 "
                             "WHERE company_id = %d AND wrecked_at IS NULL", now, gone[i]);
            if(result == ENGINE_OK)
                result = cp_exec("UPDATE companies SET credits = 0, eliminated_at = %lld, end_cause = 'dissolved', "
                                 "broke_since = NULL WHERE company_id = %d", now, gone[i]);
            engine_trace(TRACE_LEVEL_ALWAYS, "Company [%d] dissolves: broke for %lld game days; its drones are wrecks",
                gone[i], dissolve_days);
        }
    }

    if(result == ENGINE_OK)
        result = cp_exec("UPDATE compute_clock SET billed_t = %lld WHERE id = 1", hour);
    return result;
}
