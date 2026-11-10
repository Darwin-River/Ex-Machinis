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

    // Not a company that has ended: its shares are worth nothing (#493)
    MYSQL_RES* res = sh_select("SELECT s.company FROM shares s JOIN companies c ON c.company_id = s.company "
                               "AND c.eliminated_at IS NULL WHERE s.holder = %d AND s.company <> %d AND s.units > 0",
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
        // A day's sales, averaged over the last 7 game days (#492)
        long long net = sh_value(0, "SELECT IFNULL(SUM(t.credits - t.tax), 0) FROM agents a "
                                    "JOIN trades t ON t.seller = a.agent_id AND t.game_t > %lld "
                                    "WHERE a.company_id = %d",
                                 now - 7 * 86400, companies[i]) / 7;
        result = sh_exec("INSERT INTO share_orders (company, share_company, side, price, limit_units, placed_t) "
                         "VALUES (%d, %d, %d, %lld, 0, %lld) ON DUPLICATE KEY UPDATE price = VALUES(price), "
                         "limit_units = 0, placed_t = VALUES(placed_t)",
                         house, companies[i], SHARE_SELL,
                         house_share_price(net, sh_value(SHARES_PER_COMPANY,
                             "SELECT share_count FROM companies WHERE company_id = %d", companies[i])), now);
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

/* Refunds every commitment to a company's offering and closes it (#485):
   at its deadline, when the company withdraws it, or when it ends */
static ErrorCode_t sh_refund_offering(int company)
{
    MYSQL_RES* res = sh_select("SELECT investor, credits, IFNULL(drone, 0), IFNULL(action, 0) "
                               "FROM share_commitments WHERE company = %d", company);
    if(!res)
        return ENGINE_DB_QUERY_ERROR;
    ErrorCode_t result = ENGINE_OK;
    MYSQL_ROW row;
    while(result == ENGINE_OK && (row = mysql_fetch_row(res)))
        result = db_credit_company(atoi(row[0]), atoll(row[1]), atoi(row[2]), atoi(row[3]), FUEL_RESOURCE_ID);
    mysql_free_result(res);
    if(result == ENGINE_OK)
        result = sh_exec("DELETE FROM share_offerings WHERE company = %d", company);
    engine_trace(TRACE_LEVEL_ALWAYS, "Shares: company [%d]'s offering closes unfilled; commitments refunded", company);
    return result;
}

/* An offering closes with shares to issue (#485): fully committed, or at
   its deadline with at least its minimum committed (#516). The new shares
   (`units`: what was committed) go to the committers, the company is paid
   what they put in, and its count grows */
static ErrorCode_t sh_fill_offering(int company, long long units, long long price, int drone, int action, long long now)
{
    MYSQL_RES* res = sh_select("SELECT investor, units, credits FROM share_commitments WHERE company = %d", company);
    if(!res)
        return ENGINE_DB_QUERY_ERROR;
    ErrorCode_t result = ENGINE_OK;
    long long paid = 0;
    MYSQL_ROW row;
    while(result == ENGINE_OK && (row = mysql_fetch_row(res))) {
        int investor = atoi(row[0]);
        long long n = atoll(row[1]);
        paid += atoll(row[2]);
        result = sh_exec("INSERT INTO shares (company, holder, units) VALUES (%d, %d, %lld) "
                         "ON DUPLICATE KEY UPDATE units = units + VALUES(units)", company, investor, n);
        if(result == ENGINE_OK)
            result = sh_exec("INSERT INTO share_trades (share_company, seller, buyer, units, price, action, game_t) "
                             "VALUES (%d, %d, %d, %lld, %lld, %d, %lld)", company, company, investor, n, price, action, now);
    }
    mysql_free_result(res);
    if(result == ENGINE_OK)
        result = sh_exec("UPDATE companies SET share_count = share_count + %lld WHERE company_id = %d", units, company);
    if(result == ENGINE_OK)
        result = db_credit_company(company, paid, drone, action, FUEL_RESOURCE_ID);
    if(result == ENGINE_OK)
        result = sh_exec("DELETE FROM share_offerings WHERE company = %d", company);
    engine_trace(TRACE_LEVEL_ALWAYS, "Shares: company [%d] issues [%lld] new shares at [%lld], raising [%lld]",
        company, units, price, paid);
    return result;
}

/* Posting (or, with no units, withdrawing) an offering of new shares (#485):
   up to `units`, at least `min` (#516) */
static int sh_offer(int company, int drone, int action, long long units, long long min, long long price,
                    long long days, long long now)
{
    int open = sh_value(0, "SELECT COUNT(*) FROM share_offerings WHERE company = %d", company) > 0;
    if(units <= 0) {
        if(!open)
            return OUTCOME_NO_MARKET_ORDER;
        return sh_refund_offering(company) == ENGINE_OK ? OUTCOME_OK : OUTCOME_ACTION_ABORTED;
    }
    long long count = sh_value(SHARES_PER_COMPANY, "SELECT share_count FROM companies WHERE company_id = %d", company);
    int check = offer_check(units, min, price, days, count, open);
    if(check != OFFER_OK) {
        engine_trace(TRACE_LEVEL_ALWAYS, "Shares: company [%d]'s offering of [%lld] (at least [%lld]) at [%lld] for [%lld] days "
            "refused ([%d])", company, units, min, price, days, check);
        return check == OFFER_TOO_MANY ? OUTCOME_RESOURCES_UPPER_LIMIT :
               check == OFFER_ALREADY_OPEN ? OUTCOME_ACTION_ABORTED : OUTCOME_NO_MARKET_ORDER;
    }
    ErrorCode_t result = sh_exec("INSERT INTO share_offerings (company, units, min_units, price, deadline_t, drone, action, placed_t) "
                                 "VALUES (%d, %lld, %lld, %lld, %lld, %d, %d, %lld)",
                                 company, units, min, price, now + days * 86400, drone, action, now);
    engine_trace(TRACE_LEVEL_ALWAYS, "Shares: company [%d] offers up to [%lld] new shares (at least [%lld]) at [%lld] for [%lld] days",
        company, units, min, price, days);
    return result == ENGINE_OK ? OUTCOME_OK : OUTCOME_ACTION_ABORTED;
}

/* Committing to another company's offering (#485): the credits are taken
   now and held; the last commitment that covers it fills it */
static int sh_commit(int investor, int drone, int action, int company, long long units, long long now)
{
    if(company == investor)
        return OUTCOME_NO_MARKET_ORDER;
    MYSQL_RES* res = sh_select("SELECT o.units, o.price, IFNULL(o.drone, 0), IFNULL(o.action, 0), "
                               "o.units - IFNULL((SELECT SUM(m.units) FROM share_commitments m WHERE m.company = o.company), 0) "
                               "FROM share_offerings o JOIN companies c ON c.company_id = o.company AND c.eliminated_at IS NULL "
                               "WHERE o.company = %d AND o.deadline_t > %lld", company, now);
    MYSQL_ROW row = res ? mysql_fetch_row(res) : NULL;
    if(!row) {
        if(res) mysql_free_result(res);
        return OUTCOME_NO_MARKET_ORDER;
    }
    long long offered = atoll(row[0]), price = atoll(row[1]), left = atoll(row[4]);
    int offer_drone = atoi(row[2]), offer_action = atoi(row[3]);
    mysql_free_result(res);

    long long credits = sh_value(0, "SELECT credits FROM companies WHERE company_id = %d", investor);
    int check = commit_check(units, left, price, credits);
    if(check != COMMIT_OK) {
        engine_trace(TRACE_LEVEL_ALWAYS, "Shares: company [%d]'s commitment of [%lld] to [%d]'s offering refused ([%d])",
            investor, units, company, check);
        return check == COMMIT_TOO_MANY ? OUTCOME_RESOURCES_UPPER_LIMIT :
               check == COMMIT_NO_CREDITS ? OUTCOME_NO_CREDITS : OUTCOME_NO_MARKET_ORDER;
    }
    long long paid = units * price;
    ErrorCode_t result = db_credit_company(investor, -paid, drone, action, FUEL_RESOURCE_ID);
    if(result == ENGINE_OK)
        result = sh_exec("INSERT INTO share_commitments (company, investor, units, credits, drone, action, placed_t) "
                         "VALUES (%d, %d, %lld, %lld, %d, %d, %lld) ON DUPLICATE KEY UPDATE units = units + VALUES(units), "
                         "credits = credits + VALUES(credits), drone = VALUES(drone), action = VALUES(action), "
                         "placed_t = VALUES(placed_t)", company, investor, units, paid, drone, action, now);
    engine_trace(TRACE_LEVEL_ALWAYS, "Shares: company [%d] commits [%lld] credits for [%lld] of company [%d]'s new shares",
        investor, paid, units, company);
    if(result == ENGINE_OK && units == left)
        result = sh_fill_offering(company, offered, price, offer_drone, offer_action, now);
    return result == ENGINE_OK ? OUTCOME_OK : OUTCOME_ACTION_ABORTED;
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

    // Offerings of new shares (#485): 5 offers (or withdraws) the drone's
    // company's (the days ride in counterparty, its minimum in share_company,
    // #516), 6 commits to another's
    if(kind == 5)
        return sh_offer(company, drone, event->action_id, units, shares, price, other, now);
    if(kind == 6)
        return sh_commit(company, drone, event->action_id, shares, units, now);

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
    // An ended company's shares are worth nothing and don't trade (#493)
    if(other == company || !db_company_trading(shares))
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
    // and so do the orders for an ended company's shares: they are worth
    // nothing and can't be traded (#493)
    if(result == ENGINE_OK)
        result = sh_exec("DELETE o FROM share_orders o JOIN companies c ON c.company_id = o.share_company "
                         "WHERE c.eliminated_at IS NOT NULL");
    // Offerings past their deadline close (#485): one with at least its
    // minimum committed issues what was committed (#516); short of it, or
    // of a company that ended, it refunds every commitment
    if(result == ENGINE_OK) {
        MYSQL_RES* res = sh_select("SELECT o.company, c.eliminated_at IS NOT NULL, IFNULL(o.min_units, o.units), o.price, "
                                   "IFNULL(o.drone, 0), IFNULL(o.action, 0), "
                                   "IFNULL((SELECT SUM(m.units) FROM share_commitments m WHERE m.company = o.company), 0) "
                                   "FROM share_offerings o JOIN companies c ON c.company_id = o.company "
                                   "WHERE o.deadline_t <= %lld OR c.eliminated_at IS NOT NULL LIMIT %d", now, MAX_BOOK);
        struct { int company, ended, drone, action; long long min, price, committed; } lapsed[MAX_BOOK];
        int n = 0;
        MYSQL_ROW row;
        while(res && n < MAX_BOOK && (row = mysql_fetch_row(res))) {
            lapsed[n].company = atoi(row[0]);
            lapsed[n].ended = atoi(row[1]);
            lapsed[n].min = atoll(row[2]);
            lapsed[n].price = atoll(row[3]);
            lapsed[n].drone = atoi(row[4]);
            lapsed[n].action = atoi(row[5]);
            lapsed[n].committed = atoll(row[6]);
            n++;
        }
        if(res) mysql_free_result(res);
        else result = ENGINE_DB_QUERY_ERROR;
        for(int i = 0; i < n && result == ENGINE_OK; i++) {
            long long issue = lapsed[i].ended ? 0 : offer_issues(lapsed[i].committed, lapsed[i].min);
            result = issue > 0 ?
                sh_fill_offering(lapsed[i].company, issue, lapsed[i].price, lapsed[i].drone, lapsed[i].action, now) :
                sh_refund_offering(lapsed[i].company);
        }
    }
    // A company that ended can't take up new shares: its commitments go
    // (with the credits, as with the rest of what it had), and the shares
    // are open to others again
    if(result == ENGINE_OK)
        result = sh_exec("DELETE m FROM share_commitments m JOIN companies c ON c.company_id = m.investor "
                         "WHERE c.eliminated_at IS NOT NULL");
    if(result == ENGINE_OK)
        result = sh_exec("UPDATE share_exchange SET cleared_t = %lld WHERE id = 1", hour);
    return result;
}
