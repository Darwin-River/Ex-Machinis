/*
 * The database side of launched drones and their crashes (#324 step 5).
 * See db_launch.h.
 */
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>

#include "db_launch.h"
#include "common_types.h"
#include "engine.h"
#include "game_time.h"
#include "trace.h"

/* Runs one statement built from fmt */
static ErrorCode_t db_launch_query(DbConnection_t* connection, const char* fmt, ...)
{
    char query_text[DB_MAX_SQL_QUERY_LEN+1];
    ErrorCode_t result = db_reconnect(connection);

    if(result != ENGINE_OK)
        return result;

    va_list args;
    va_start(args, fmt);
    vsnprintf(query_text, DB_MAX_SQL_QUERY_LEN, fmt, args);
    va_end(args);

    if(mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        result = ENGINE_DB_QUERY_ERROR;
    }

    return result;
}

ErrorCode_t db_get_launch_state(DbConnection_t* connection, int agent_id, LaunchState_t* state)
{
    state->running = state->crashes_in_row = state->wrecked = 0;

    ErrorCode_t result = db_launch_query(connection,
        "SELECT launched_running, crashes_in_row, wrecked_at IS NOT NULL FROM agents WHERE agent_id = %d",
        agent_id);
    if(result != ENGINE_OK)
        return result;

    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    MYSQL_ROW row = db_result ? mysql_fetch_row(db_result) : NULL;
    if(row)
    {
        state->running = row[0] ? atoi(row[0]) : 0;
        state->crashes_in_row = row[1] ? atoi(row[1]) : 0;
        state->wrecked = row[2] ? atoi(row[2]) : 0;
    }
    else
    {
        result = ENGINE_DB_NOT_FOUND_ERROR;
    }
    if(db_result)
        mysql_free_result(db_result);

    return result;
}

ErrorCode_t db_save_release_vm(DbConnection_t* connection, int agent_id)
{
    return db_launch_query(connection,
        "UPDATE agents SET release_vm = VM WHERE agent_id = %d AND release_vm IS NULL AND VM IS NOT NULL",
        agent_id);
}

ErrorCode_t db_restore_release_vm(DbConnection_t* connection, int agent_id)
{
    return db_launch_query(connection,
        "UPDATE agents SET VM = release_vm WHERE agent_id = %d AND release_vm IS NOT NULL",
        agent_id);
}

ErrorCode_t db_set_launched_running(DbConnection_t* connection, int agent_id, int running)
{
    return db_launch_query(connection,
        "UPDATE agents SET launched_running = %d WHERE agent_id = %d", running ? 1 : 0, agent_id);
}

ErrorCode_t db_record_launched_finished(DbConnection_t* connection, int agent_id)
{
    return db_launch_query(connection,
        "UPDATE agents SET launched_running = 0, crashes_in_row = 0 WHERE agent_id = %d", agent_id);
}

ErrorCode_t db_record_crash(DbConnection_t* connection, int agent_id, int code, int in_row,
                            long long restart_at)
{
    long long now = game_time_now_seconds();
    char restart[32], wrecked[32];

    // A restart time, or none and the drone is a wreck from now on
    if(restart_at < 0) {
        snprintf(restart, sizeof(restart), "NULL");
        snprintf(wrecked, sizeof(wrecked), "%lld", now);
    } else {
        snprintf(restart, sizeof(restart), "%lld", restart_at);
        snprintf(wrecked, sizeof(wrecked), "NULL");
    }

    ErrorCode_t result = db_launch_query(connection,
        "INSERT INTO drone_crashes (drone, game_t, code, in_row, restart_at) VALUES (%d, %lld, %d, %d, %s)",
        agent_id, now, code, in_row, restart);

    if(result == ENGINE_OK)
        result = db_launch_query(connection,
            "UPDATE agents SET launched_running = 0, crashes_in_row = %d, crashes_total = crashes_total + 1, "
            "wrecked_at = %s WHERE agent_id = %d",
            in_row, wrecked, agent_id);

    return result;
}

ErrorCode_t db_queue_launched(DbConnection_t* connection, int agent_id, long long deliver_at)
{
    return db_launch_query(connection,
        "INSERT INTO commands (code, agent_id, created_at, updated_at, subject, email_content, deliver_at) "
        "VALUES ('%s', %d, NOW(), NOW(), 'Restart', '', %lld)",
        META_COMMAND_LAUNCHED, agent_id, deliver_at);
}

ErrorCode_t db_clear_crashes_in_row(DbConnection_t* connection, int agent_id)
{
    return db_launch_query(connection,
        "UPDATE agents SET crashes_in_row = 0 WHERE agent_id = %d AND crashes_in_row <> 0", agent_id);
}
