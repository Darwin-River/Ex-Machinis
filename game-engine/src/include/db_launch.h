/*
 * The database side of launched drones and their crashes (#324 step 5):
 * agents.release_vm / launched_running / crashes_in_row / crashes_total /
 * wrecked_at and the drone_crashes table (migration 0005).
 */
#ifndef __EM_DB_LAUNCH__
#define __EM_DB_LAUNCH__

#include "db.h"

typedef struct
{
    int running;        // the launched word is running (inside catch)
    int crashes_in_row; // crashes since the last action that succeeded
    int wrecked;        // the drone is a wreck
} LaunchState_t;

/** A drone's launch state (all 0 when unknown) */
ErrorCode_t db_get_launch_state(DbConnection_t* connection, int agent_id, LaunchState_t* state);

/** Saves the drone's program as its clean copy, unless it already has one */
ErrorCode_t db_save_release_vm(DbConnection_t* connection, int agent_id);

/** Puts the clean copy back as the drone's program (no-op without one) */
ErrorCode_t db_restore_release_vm(DbConnection_t* connection, int agent_id);

/** Marks the launched word as running (1) or not (0) */
ErrorCode_t db_set_launched_running(DbConnection_t* connection, int agent_id, int running);

/** The launched word returned normally */
ErrorCode_t db_record_launched_finished(DbConnection_t* connection, int agent_id);

/** Records a crash: restart_at is the game time of the restart, or -1 when
    the drone becomes a wreck */
ErrorCode_t db_record_crash(DbConnection_t* connection, int agent_id, int code, int in_row,
                            long long restart_at);

/** Queues the <launched> command to reach the drone at game time deliver_at */
ErrorCode_t db_queue_launched(DbConnection_t* connection, int agent_id, long long deliver_at);

/** An action succeeded: the drone's crashes are no longer in a row */
ErrorCode_t db_clear_crashes_in_row(DbConnection_t* connection, int agent_id);

#endif
