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

/* The House offers what it holds of other companies' shares, collected from
   failed companies, at 30 days of their recent dividends */
static ErrorCode_t sh_house_offers(long long now)
{
    int house = (int)sh_value(0, "SELECT MIN(company_id) FROM companies WHERE house = 1");
    if(house <= 0)
        return ENGINE_OK;

    MYSQL_RES* res = sh_select("SELECT company, units FROM shares WHERE holder = %d AND company <> %d AND units > 0",
                               house, house);
    if(!res)
        return ENGINE_DB_QUERY_ERROR;
    int companies[MAX_BOOK];
    long long units[MAX_BOOK];
    int n = 0;
    MYSQL_ROW row;
    while(n < MAX_BOOK && (row = mysql_fetch_row(res))) {
        companies[n] = atoi(row[0]);
        units[n] = atoll(row[1]);
        n++;
    }
    mysql_free_result(res);

    ErrorCode_t result = ENGINE_OK;
    for(int i = 0; i < n && result == ENGINE_OK; i++) {
        long long net = sh_value(0, "SELECT IFNULL(SUM(t.credits - t.tax), 0) FROM trades t "
                                    "JOIN agents a ON a.agent_id = t.seller "
                                    "WHERE a.company_id = %d AND t.game_t > %lld",
                                 companies[i], now - 86400);
        result = sh_exec("DELETE FROM share_orders WHERE company = %d AND share_company = %d AND side = %d",
                         house, companies[i], SHARE_OFFER);
        if(result == ENGINE_OK)
            result = sh_exec("INSERT INTO share_orders (company, share_company, side, units, price, placed_t, arrives_t) "
                             "VALUES (%d, %d, %d, %lld, %lld, %lld, %lld)",
                             house, companies[i], SHARE_OFFER, units[i], house_share_price(net), now, now);
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

typedef struct { int id; int drone; int action; } OrderRef_t;

/* Reads one side of a company's book: offers cheapest first (capped by the
   shares the seller holds), bids highest first (with the buyer's credits) */
static int sh_book(int company, int side, long long batch, ShareOrder_t* orders, OrderRef_t* refs, long long* credits)
{
    MYSQL_RES* res = side == SHARE_OFFER ?
        sh_select("SELECT o.id, o.company, LEAST(o.units, IFNULL(s.units, 0)), o.price, IFNULL(o.drone, 0), "
                  "IFNULL(o.action, 0) FROM share_orders o "
                  "LEFT JOIN shares s ON s.company = o.share_company AND s.holder = o.company "
                  "WHERE o.share_company = %d AND o.side = %d AND o.arrives_t <= %lld "
                  "ORDER BY o.price, o.arrives_t, o.id LIMIT %d", company, side, batch, MAX_BOOK) :
        sh_select("SELECT o.id, o.company, o.units, o.price, IFNULL(o.drone, 0), IFNULL(o.action, 0), c.credits "
                  "FROM share_orders o JOIN companies c ON c.company_id = o.company "
                  "WHERE o.share_company = %d AND o.side = %d AND o.arrives_t <= %lld "
                  "ORDER BY o.price DESC, o.arrives_t, o.id LIMIT %d", company, side, batch, MAX_BOOK);
    if(!res)
        return -1;
    int n = 0;
    MYSQL_ROW row;
    while(n < MAX_BOOK && (row = mysql_fetch_row(res))) {
        refs[n].id = atoi(row[0]);
        orders[n].company = atoi(row[1]);
        orders[n].units = row[2] ? atoll(row[2]) : 0;
        orders[n].price = atoll(row[3]);
        refs[n].drone = atoi(row[4]);
        refs[n].action = atoi(row[5]);
        if(credits)
            credits[n] = row[6] ? atoll(row[6]) : 0;
        n++;
    }
    mysql_free_result(res);
    return n;
}

/* Clears one company's shares */
static ErrorCode_t sh_clear_company(int company, long long batch, long long now)
{
    static ShareOrder_t offers[MAX_BOOK], bids[MAX_BOOK];
    static OrderRef_t offer_refs[MAX_BOOK], bid_refs[MAX_BOOK];
    static long long credits[MAX_BOOK];
    static ShareFill_t fills[2 * MAX_BOOK];

    int n_offers = sh_book(company, SHARE_OFFER, batch, offers, offer_refs, NULL);
    int n_bids = sh_book(company, SHARE_BID, batch, bids, bid_refs, credits);
    if(n_offers < 0 || n_bids < 0)
        return ENGINE_DB_QUERY_ERROR;

    int n = shares_cross(offers, n_offers, bids, n_bids, credits, fills, 2 * MAX_BOOK);
    ErrorCode_t result = ENGINE_OK;
    for(int i = 0; i < n && result == ENGINE_OK; i++) {
        OrderRef_t* o = &offer_refs[fills[i].offer];
        OrderRef_t* b = &bid_refs[fills[i].bid];
        int seller = offers[fills[i].offer].company, buyer = bids[fills[i].bid].company;
        long long units = fills[i].units, paid = fills[i].units * fills[i].price;

        result = sh_move(company, seller, buyer, units);
        if(result == ENGINE_OK)
            result = db_credit_company(buyer, -paid, b->drone, b->action, FUEL_RESOURCE_ID);
        if(result == ENGINE_OK)
            result = db_credit_company(seller, paid, o->drone, o->action, FUEL_RESOURCE_ID);
        if(result == ENGINE_OK)
            result = sh_exec("INSERT INTO share_trades (share_company, seller, buyer, units, price, game_t) "
                             "VALUES (%d, %d, %d, %lld, %lld, %lld)", company, seller, buyer, units, fills[i].price, now);
        if(result == ENGINE_OK)
            result = sh_exec("UPDATE share_orders SET units = units - %lld WHERE id IN (%d, %d)", units, o->id, b->id);
        engine_trace(TRACE_LEVEL_ALWAYS, "Shares: company [%d] sells [%lld] of company [%d]'s shares to [%d] at [%lld]",
            seller, units, company, buyer, fills[i].price);
    }
    // Filled orders leave the book
    if(result == ENGINE_OK && n > 0)
        result = sh_exec("DELETE FROM share_orders WHERE share_company = %d AND arrives_t <= %lld AND units <= 0",
                         company, batch);
    return result;
}

ErrorCode_t db_shares_clear_due(void)
{
    long long now = game_time_now_seconds();
    long long batch = now - now % SHARE_BATCH_SECONDS;
    if(batch <= sh_value(0, "SELECT cleared_t FROM share_exchange WHERE id = 1"))
        return ENGINE_OK;

    ErrorCode_t result = sh_house_offers(now);

    // The newest order of each kind to reach Earth replaces the ones before;
    // one for 0 units withdraws it; an ended company's orders go
    if(result == ENGINE_OK)
        result = sh_exec("DELETE o FROM share_orders o JOIN share_orders n ON n.company = o.company "
                         "AND n.share_company = o.share_company AND n.side = o.side AND n.id > o.id "
                         "AND n.arrives_t <= %lld WHERE o.arrives_t <= %lld", batch, batch);
    if(result == ENGINE_OK)
        result = sh_exec("DELETE FROM share_orders WHERE arrives_t <= %lld AND units <= 0", batch);
    if(result == ENGINE_OK)
        result = sh_exec("DELETE o FROM share_orders o JOIN companies c ON c.company_id = o.company "
                         "WHERE c.eliminated_at IS NOT NULL");

    // Each company's shares with both offers and bids at Earth
    int companies[MAX_BOOK];
    int n = 0;
    if(result == ENGINE_OK) {
        MYSQL_RES* res = sh_select("SELECT share_company FROM share_orders WHERE arrives_t <= %lld "
                                   "GROUP BY share_company HAVING SUM(side = %d) > 0 AND SUM(side = %d) > 0 LIMIT %d",
                                   batch, SHARE_OFFER, SHARE_BID, MAX_BOOK);
        MYSQL_ROW row;
        while(res && n < MAX_BOOK && (row = mysql_fetch_row(res)))
            companies[n++] = atoi(row[0]);
        if(res) mysql_free_result(res);
        else result = ENGINE_DB_QUERY_ERROR;
    }
    for(int i = 0; i < n && result == ENGINE_OK; i++)
        result = sh_clear_company(companies[i], batch, now);

    if(result == ENGINE_OK)
        result = sh_exec("UPDATE share_exchange SET cleared_t = %lld WHERE id = 1", batch);
    return result;
}
