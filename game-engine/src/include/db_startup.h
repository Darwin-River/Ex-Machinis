/*
 * Startups (#637): the database side of their trading rules. See startup.h.
 */
#ifndef __DB_STARTUP_H__
#define __DB_STARTUP_H__

#include "common_types.h"

/* May this transfer go ahead under the startup rules? `from` is its
   decrement event (the drone giving the goods up), `to` its increment event,
   both at `place`. One query when neither drone is a startup's. Sets
   *outcome to 0, or to the outcome that refuses it, and *buy_company to the
   startup buying from the House (0 when none), for db_startup_record_buy. */
ErrorCode_t db_startup_transfer_outcome(Event_t* from, Event_t* to, int place, int* outcome, int* buy_company);

/* After a transfer went through: what a startup (company > 0) bought from the House */
ErrorCode_t db_startup_record_buy(int company, Event_t* to, int place);

/* The minimum order life (#639) for an order event (sell 4, buy 5): 0, or
   OUTCOME_ORDER_LOCKED when its drone's company plays by the new rules and
   the order would withdraw, cut or worsen its standing order too soon
   (house_rules.order_lock_hours) */
int db_order_lock_outcome(Event_t* event);

/* A startup's credits above its cap (house_rules.startup_credit_cap_permille
   of its debt, #672) after a sale: returns what goes to the bank, and records
   it (startup_skims). 0 for any other company */
long long db_startup_skim(int company, long long credits, long long game_t);

#endif
