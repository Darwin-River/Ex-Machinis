/*
 * The share exchange at Earth (#469). See db_shares.h.
 */
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "db.h"
#include "db_shares.h"
#include "db_wreck.h"
#include "engine.h"
#include "game_time.h"
#include "shares.h"
#include "trace.h"

#define MAX_BOOK 500          /* orders a side, a company, a batch */

static ErrorCode_t sh_exec(const char* fmt, ...)
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

static MYSQL_RES* sh_select(const char* fmt, ...)
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

static long long sh_value(long long dflt, const char* fmt, ...)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    va_list args;
    va_start(args, fmt);
    vsnprintf(query_text, DB_MAX_SQL_QUERY_LEN, fmt, args);
    va_end(args);
    MYSQL_RES* res = sh_select("%s", query_text);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    long long value = (row && row[0]) ? atoll(row[0]) : dflt;
    if(res) mysql_free_result(res);
    return value;
}

/* The House posts sell orders for what it holds of other companies'
   shares, collected from failed companies, at 30 days of their recent
   dividends; it keeps none */
static ErrorCode_t sh_house_orders(long long now)
{
    int house = (int)sh_value(0, "SELECT MIN(company_id) FROM companies WHERE house = 1");
    if(house <= 0)
        return ENGINE_OK;

    MYSQL_RES* res = sh_select("SELECT company FROM shares WHERE holder = %d AND company <> %d AND units > 0",
                               house, house);
    if(!res)
        return ENGINE_DB_QUERY_ERROR;
    int companies[MAX_BOOK];
    int n = 0;
    MYSQL_ROW row;
    while(n < MAX_BOOK && (row = mysql_fetch_row(res)))
        companies[n++] = atoi(row[0]);
    mysql_free_result(res);

    ErrorCode_t result = ENGINE_OK;
    for(int i = 0; i < n && result == ENGINE_OK; i++) {
        long long net = sh_value(0, "SELECT IFNULL(SUM(t.credits - t.tax), 0) FROM trades t "
                                    "JOIN agents a ON a.agent_id = t.seller "
                                    "WHERE a.company_id = %d AND t.game_t > %lld",
                                 companies[i], now - 86400);
        result = sh_exec("INSERT INTO share_orders (company, share_company, side, price, limit_units, placed_t) "
                         "VALUES (%d, %d, %d, %lld, 0, %lld) ON DUPLICATE KEY UPDATE price = VALUES(price), "
                         "limit_units = 0, placed_t = VALUES(placed_t)",
                         house, companies[i], SHARE_SELL, house_share_price(net), now);
    }
    return result;
}

/* Moves units of a company's shares from seller to buyer */
static ErrorCode_t sh_move(int company, int seller, int buyer, long long units)
{
    ErrorCode_t result = sh_exec("UPDATE shares SET units = units - %lld WHERE company = %d AND holder = %d",
                                 units, company, seller);
    if(result == ENGINE_OK && seller != company)
        result = sh_exec("DELETE FROM shares WHERE company = %d AND holder = %d AND units <= 0", company, seller);
    if(result == ENGINE_OK)
        result = sh_exec("INSERT INTO shares (company, holder, units) VALUES (%d, %d, %lld) "
                         "ON DUPLICATE KEY UPDATE units = units + VALUES(units)", company, buyer, units);
    return result;
}

static int sh_outcome(int check)
{
    switch(check) {
        case SHARE_OK:          return OUTCOME_OK;
        case SHARE_BELOW_KEEP:  return OUTCOME_RESOURCES_LOWER_LIMIT;
        case SHARE_ABOVE_WANT:  return OUTCOME_RESOURCES_UPPER_LIMIT;
        case SHARE_NO_CREDITS:  return OUTCOME_NO_CREDITS;
        case SHARE_NOT_HELD:    return OUTCOME_NO_RESOURCES;
        default:                return OUTCOME_NO_MARKET_ORDER;
    }
}

int db_shares_settle(Event_t* event)
{
    MYSQL_RES* res = sh_select("SELECT company, drone, kind, share_company, IFNULL(counterparty, 0), units, price "
                               "FROM share_requests WHERE action = %d", event->action_id);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    if(!row) {
        if(res) mysql_free_result(res);
        return OUTCOME_ACTION_ABORTED;
    }
    int company = atoi(row[0]), drone = atoi(row[1]), kind = atoi(row[2]), shares = atoi(row[3]);
    int other = atoi(row[4]);
    long long units = atoll(row[5]), price = atoll(row[6]);
    mysql_free_result(res);
    sh_exec("DELETE FROM share_requests WHERE action = %d", event->action_id);
    long long now = game_time_now_seconds();

    // Posting (or withdrawing, at price 0) a standing order
    if(kind == 1 || kind == 2) {
        int side = kind == 1 ? SHARE_SELL : SHARE_BUY;
        ErrorCode_t result = price <= 0 ?
            sh_exec("DELETE FROM share_orders WHERE company = %d AND share_company = %d AND side = %d",
                    company, shares, side) :
            sh_exec("INSERT INTO share_orders (company, share_company, side, price, limit_units, drone, action, placed_t) "
                    "VALUES (%d, %d, %d, %lld, %lld, %d, %d, %lld) ON DUPLICATE KEY UPDATE price = VALUES(price), "
                    "limit_units = VALUES(limit_units), drone = VALUES(drone), action = VALUES(action), "
                    "placed_t = VALUES(placed_t)", company, shares, side, price, units, drone, event->action_id, now);
        return result == ENGINE_OK ? OUTCOME_OK : OUTCOME_ACTION_ABORTED;
    }

    // A pull buys from the other company's sell order; a push sells into its
    // buy order. At the order's price, within its limit
    int side = kind == 3 ? SHARE_SELL : SHARE_BUY;
    int seller = kind == 3 ? other : company, buyer = kind == 3 ? company : other;
    if(other == company)
        return OUTCOME_NO_MARKET_ORDER;
    res = sh_select("SELECT o.price, o.limit_units, IFNULL(o.drone, 0), IFNULL(o.action, 0) FROM share_orders o "
                    "JOIN companies c ON c.company_id = o.company AND c.eliminated_at IS NULL "
                    "WHERE o.company = %d AND o.share_company = %d AND o.side = %d", other, shares, side);
    row = res ? mysql_fetch_row(res) : NULL;
    if(!row) {
        if(res) mysql_free_result(res);
        return OUTCOME_NO_MARKET_ORDER;
    }
    long long order_price = atoll(row[0]), limit = atoll(row[1]);
    int order_drone = atoi(row[2]), order_action = atoi(row[3]);
    mysql_free_result(res);

    long long seller_held = sh_value(0, "SELECT units FROM shares WHERE company = %d AND holder = %d", shares, seller);
    long long buyer_held = sh_value(0, "SELECT units FROM shares WHERE company = %d AND holder = %d", shares, buyer);
    long long credits = sh_value(0, "SELECT credits FROM companies WHERE company_id = %d", buyer);
    int check = share_trade_check(side, units, order_price, limit, seller_held, buyer_held, credits);
    if(check != SHARE_OK) {
        engine_trace(TRACE_LEVEL_ALWAYS, "Shares: [%lld] of company [%d]'s shares from [%d] to [%d] refused ([%d])",
            units, shares, seller, buyer, check);
        return sh_outcome(check);
    }

    long long paid = units * order_price;
    int seller_drone = kind == 3 ? order_drone : drone, buyer_drone = kind == 3 ? drone : order_drone;
    int seller_action = kind == 3 ? order_action : event->action_id;
    int buyer_action = kind == 3 ? event->action_id : order_action;
    ErrorCode_t result = sh_move(shares, seller, buyer, units);
    if(result == ENGINE_OK)
        result = db_credit_company(buyer, -paid, buyer_drone, buyer_action, FUEL_RESOURCE_ID);
    if(result == ENGINE_OK)
        result = db_credit_company(seller, paid, seller_drone, seller_action, FUEL_RESOURCE_ID);
    if(result == ENGINE_OK)
        result = sh_exec("INSERT INTO share_trades (share_company, seller, buyer, units, price, action, game_t) "
                         "VALUES (%d, %d, %d, %lld, %lld, %d, %lld)",
                         shares, seller, buyer, units, order_price, event->action_id, now);
    engine_trace(TRACE_LEVEL_ALWAYS, "Shares: company [%d] sells [%lld] of company [%d]'s shares to [%d] at [%lld]",
        seller, units, shares, buyer, order_price);
    return result == ENGINE_OK ? OUTCOME_OK : OUTCOME_ACTION_ABORTED;
}

ErrorCode_t db_shares_clear_due(void)
{
    long long now = game_time_now_seconds();
    long long hour = now - now % SHARE_BATCH_SECONDS;
    if(hour <= sh_value(0, "SELECT cleared_t FROM share_exchange WHERE id = 1"))
        return ENGINE_OK;

    // Once a game hour: the House re-posts its sell orders at today's prices,
    // and an ended company's orders go
    ErrorCode_t result = sh_house_orders(now);
    if(result == ENGINE_OK)
        result = sh_exec("DELETE o FROM share_orders o JOIN companies c ON c.company_id = o.company "
                         "WHERE c.eliminated_at IS NOT NULL");
    if(result == ENGINE_OK)
        result = sh_exec("UPDATE share_exchange SET cleared_t = %lld WHERE id = 1", hour);
    return result;
}
