/*
 * Current state in memory, the database side (#694 stage 4 step 5, #431,
 * MEMORY_STATE=1): holdings, fed by holding_changes (migration 0092), and
 * units() (query 212) answered from them. See db_state.c.
 */
#ifndef DB_STATE_H
#define DB_STATE_H

#include "common_types.h"

/* The query units() asks (game data's 212) */
#define MEMORY_STATE_UNITS_QUERY 212

/* MEMORY_STATE=1 in the environment */
int memory_state_flag_on(void);

/* Whether units() comes from memory (the flag on, and the feed turned on) */
int memory_state_on(void);

/* At start, after the database connection: with MEMORY_STATE=1, turns the
   feed on (the memory loads at the first use); with it off, shard 0 turns
   the feed off and empties it. Logs a NOTICE either way when it acts */
void db_state_start(DbConnection_t* connection);

/* The turn connection's transaction began, committed or rolled back: the
   next answer reads the feed first (as a statement would see what is
   committed) */
void db_state_transaction_begins(const DbConnection_t* connection);
void db_state_transaction_ends(const DbConnection_t* connection);

/* A settlement pass ran in this engine (settle.c): it may have changed
   holdings */
void db_state_settled(void);

/* Between turns: the feed read once a real second, a NOTICE each minute,
   the full check every five minutes, old changes deleted (shard 0) */
void db_state_tick(DbConnection_t* connection);

/* Whether a script is query 212's as this build knows it (another script,
   from changed game data, is asked of MySQL) */
int db_state_is_units_script(const char* script);

/* units(): the drone's units of the resource, from memory. 1 and *units
   when answered, 0 when MySQL must answer (off, or the memory failed) */
int db_state_units(DbConnection_t* connection, long long drone, long long resource, long long* units);

/* MEMORY_STATE_CHECK=1 (a check build, for load tests): each answer is
   also asked of MySQL and compared; a difference is logged and counted */
int memory_state_check_on(void);
void db_state_check_result(long long drone, long long resource, long long memory, long long sql);

#endif
