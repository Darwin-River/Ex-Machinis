/******************************************************************************
 * The game clock: how fast game time passes compared with real time.
 *
 * One master scale (game seconds per real second) governs everything that
 * takes time in the game: orbits, action and travel durations, and the
 * light-speed delay of signals (email, observations). It lives in the
 * game_clock table so both engines share it:
 *
 *   game time = game_anchor + (real now - real_anchor) * scale
 *
 * Changing the scale re-anchors the clock at the current game time, so game
 * time (and every orbit) carries on without a jump.
 *****************************************************************************/

#include <math.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#include "game_time.h"
#include "db.h"
#include "trace.h"
#include "engine.h"

static double g_scale = 1.0;
static time_t g_real_anchor = 0;
static double g_game_anchor = 0;
static double g_epoch = 0;
static int g_loaded = 0;

static ErrorCode_t game_time_query(DbConnection_t* connection, const char* query_text)
{
    if(mysql_query(connection->hndl, query_text))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Query [%s] failed: %s", query_text, mysql_error(connection->hndl));
        return ENGINE_DB_QUERY_ERROR;
    }
    return ENGINE_OK;
}

ErrorCode_t game_time_load(void)
{
    DbConnection_t* connection = engine_get_db_connection();
    ErrorCode_t result = db_reconnect(connection);
    char query_text[256];

    if(result != ENGINE_OK)
        return result;

    // First start: game time begins as real time, at scale 1, and the world's
    // first game second (its epoch) is now
    snprintf(query_text, sizeof(query_text),
        "INSERT IGNORE INTO game_clock (id, real_anchor, game_anchor, scale, epoch) "
        "VALUES (1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 1, UNIX_TIMESTAMP())");
    result = game_time_query(connection, query_text);

    if(result == ENGINE_OK)
        result = game_time_query(connection,
            "SELECT real_anchor, game_anchor, scale, IFNULL(epoch, game_anchor) FROM game_clock WHERE id = 1");

    if(result == ENGINE_OK)
    {
        MYSQL_RES* db_result = mysql_store_result(connection->hndl);
        MYSQL_ROW row = db_result ? mysql_fetch_row(db_result) : NULL;

        if(row && row[0] && row[1] && row[2] && row[3] && atof(row[2]) > 0)
        {
            g_real_anchor = (time_t)atoll(row[0]);
            g_game_anchor = atof(row[1]);
            g_scale = atof(row[2]);
            g_epoch = atof(row[3]);
            g_loaded = 1;
        }
        else
        {
            result = ENGINE_DB_NOT_FOUND_ERROR;
        }

        if(db_result) mysql_free_result(db_result);
    }

    return result;
}

ErrorCode_t game_time_set_scale(double scale)
{
    DbConnection_t* connection = engine_get_db_connection();
    char query_text[256];

    if(!(scale > 0))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: invalid game time scale [%f], keeping the current one", scale);
        return ENGINE_CONFIG_ERROR;
    }

    ErrorCode_t result = game_time_load();
    if(result != ENGINE_OK || fabs(scale - g_scale) < 1e-9)
        return result;

    // Re-anchor: game time goes on from where it is now, at the new pace
    time_t now = time(NULL);
    double game_now = game_time_now();

    snprintf(query_text, sizeof(query_text),
        "UPDATE game_clock SET real_anchor = %lld, game_anchor = %.3f, scale = %.6f WHERE id = 1",
        (long long)now, game_now, scale);
    result = game_time_query(connection, query_text);

    // Keep every rate change, so charts can map game time to real time
    if(result == ENGINE_OK)
    {
        snprintf(query_text, sizeof(query_text),
            "INSERT INTO game_clock_history (real_time, game_time, scale) VALUES (%lld, %.3f, %.6f)",
            (long long)now, game_now, scale);
        result = game_time_query(connection, query_text);
    }

    if(result == ENGINE_OK)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "NOTICE: game time scale changed from [%f] to [%f]", g_scale, scale);
        g_real_anchor = now;
        g_game_anchor = game_now;
        g_scale = scale;
    }

    return result;
}

double game_time_scale(void)
{
    return g_scale;
}

double game_time_now(void)
{
    if(!g_loaded)
        return (double)time(NULL); // no clock yet: real time

    return g_game_anchor + difftime(time(NULL), g_real_anchor) * g_scale;
}

long long game_time_now_seconds(void)
{
    return llround(game_time_now());
}

double game_time_epoch(void)
{
    return g_loaded ? g_epoch : game_time_now();
}

long long game_time_light_seconds(double distance_km)
{
    if(!(distance_km > 0))
        return 0;
    return llround(distance_km / LIGHT_SPEED_KM_PER_SECOND);
}

int game_time_to_real(double game_seconds)
{
    if(game_seconds <= 0)
        return 0;

    double real = game_seconds / g_scale;
    if(real > 2e9)
        real = 2e9;

    return (int)lround(real);
}

int game_time_light_delay(double distance_km)
{
    return game_time_to_real(distance_km / LIGHT_SPEED_KM_PER_SECOND);
}
