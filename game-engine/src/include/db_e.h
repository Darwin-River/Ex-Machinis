/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: The events engine's own copies of db.h's functions (db_e.c).
               They carry an ee_ prefix so both engines link into one program
               (#694 stage 4); db.h documents each of them.

******************************************************************************/

#ifndef __EM_DB_E_MODULE__
#define __EM_DB_E_MODULE__

#include <stddef.h>
#include <time.h>

#include "common_types.h"

time_t ee_db_date_to_timestamp(char* date, char* format);
ErrorCode_t ee_db_init(DbConnection_t* connection);
void ee_db_stop(DbConnection_t* connection);
ErrorCode_t ee_db_start_transaction(DbConnection_t* connection);
ErrorCode_t ee_db_commit_transaction(DbConnection_t* connection);
ErrorCode_t ee_db_rollback_transaction(DbConnection_t* connection);
ErrorCode_t ee_db_update_agent_object(DbConnection_t* connection, int agent_id, int object_id);
void ee_db_scape_string(
    DbConnection_t* connection,
    char *value,
    size_t len,
    char *out,
    size_t out_len
);
ErrorCode_t ee_db_abort_action(int action_id);
char* ee_db_int2str(int value, char* buffer, size_t size);

#endif // __EM_DB_E_MODULE__
