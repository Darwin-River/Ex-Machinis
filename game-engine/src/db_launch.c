/*
 * The database side of launched drones and their crashes (#324 step 5).
 * See db_launch.h.
 */
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "db_launch.h"
#include "common_types.h"
#include "engine.h"
#include "game_time.h"
#include "trace.h"
#include "vm_meter.h"

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
            "wrecked_at = %s, wreck_cause = %s WHERE agent_id = %d",
            in_row, wrecked, restart_at < 0 ? "'crashes'" : "NULL", agent_id);

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

ErrorCode_t db_add_drone_message(DbConnection_t* connection, int agent_id, long long game_t,
                                 const char* subject, const char* body)
{
    ErrorCode_t result = db_reconnect(connection);
    if(result != ENGINE_OK)
        return result;

    subject = subject ? subject : "";
    body = body ? body : "";

    // Escaping can double each value
    size_t subject_len = strlen(subject), body_len = strlen(body);
    size_t query_size = 256 + 2 * (subject_len + body_len);
    char* query_text = malloc(query_size);
    if(!query_text)
        return ENGINE_INTERNAL_ERROR;

    char* end = query_text;
    end += snprintf(end, query_size, "INSERT INTO drone_messages (agent_id, game_t, subject, body) VALUES (%d, %lld, '",
        agent_id, game_t);
    end += mysql_real_escape_string(connection->hndl, end, subject, subject_len);
    end += snprintf(end, query_size - (size_t)(end - query_text), "', '");
    end += mysql_real_escape_string(connection->hndl, end, body, body_len);
    snprintf(end, query_size - (size_t)(end - query_text), "')");

    if(mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to keep a reply for drone [%d]: %s", agent_id, mysql_error(connection->hndl));
        result = ENGINE_DB_QUERY_ERROR;
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "Reply kept for drone [%d] (email is off), reaches Earth at game time [%lld]", agent_id, game_t);
    }

    free(query_text);
    return result;
}

ErrorCode_t db_take_power_due(DbConnection_t* connection, int agent_id, long long per_fuel, long long* units)
{
    *units = 0;
    if(per_fuel <= 0)
        return ENGINE_OK;

    ErrorCode_t result = db_launch_query(connection,
        "SELECT instructions, power_paid, release_vm IS NULL FROM agents WHERE agent_id = %d", agent_id);
    if(result != ENGINE_OK)
        return result;

    MYSQL_RES* db_result = mysql_store_result(connection->hndl);
    MYSQL_ROW row = db_result ? mysql_fetch_row(db_result) : NULL;
    long long instructions = (row && row[0]) ? atoll(row[0]) : 0;
    long long paid = (row && row[1]) ? atoll(row[1]) : 0;
    int loading = (row && row[2]) ? atoi(row[2]) : 0;
    if(db_result)
        mysql_free_result(db_result);

    // Loading a released script is free (#380): until the drone first runs
    // launched (which saves release_vm) it is only reading its program, about
    // 20,000 instructions a word. Billed, a 150-line script cost more than
    // the 100 fuel a drone starts with, and the drone died before it ran.
    if(loading)
        return db_launch_query(connection,
            "UPDATE agents SET power_paid = instructions WHERE agent_id = %d", agent_id);

    *units = vm_meter_fuel_due(instructions, paid, per_fuel);
    if(*units > 0)
        result = db_launch_query(connection,
            "UPDATE agents SET power_paid = power_paid + %lld WHERE agent_id = %d", *units * per_fuel, agent_id);

    return result;
}
