/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Engine module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <stdio.h>
#include <stdlib.h>
#include <signal.h>
#include <sys/types.h>
#include <unistd.h>
#include <stdarg.h>
#include <string.h>
#include <math.h>
#include <strings.h>
#include <ctype.h>
#include <stdbool.h>

#include "engine.h"
#include "wrap.h"
#include "game_time.h"
#include "config.h"
#include "db.h"

// The current command ran on the drone's VM (not refused, not a meta command)
static bool g_ran_on_vm = false;

// Emails msg to the current drone's owner. with_pending: put the output the
// script printed before it last paused first (and clear it).
void engine_send_output(const char* msg, bool with_pending);
#include "vm.h"
#include "email.h"
#include "orbits.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/

// env variable
const char* PLAT_HOME = "PLAT_HOME";

Engine_t engine;

/******************************* LOCAL FUNCTIONS *****************************/

/** ****************************************************************************

  @brief      Callback invoked when supported signal is received
              We do cleanup and exit properly

  @param[in]  signal_id  ID of signal received

  @return     void

*******************************************************************************/
void signals_handler(int signal_id)
{
    engine_trace(TRACE_LEVEL_ALWAYS, "Signal [%d] received", signal_id);
    engine_stop();
}

/** ****************************************************************************

  @brief      Initializes engine traces module

  @param[in]  void

  @return     Execution result 

*******************************************************************************/
ErrorCode_t engine_init_traces()
{
	ErrorCode_t result = ENGINE_OK;

    // Define traces configuration
    engine.trace_conf.level = TRACE_LEVEL_DEBUG;
    snprintf(engine.trace_conf.log_file_path, 
    	PATH_MAX, 
    	"%s/log/%s.log",
    	engine.plat_home,
    	engine.exe_name);


    result = trace_init(&engine.trace_conf, &engine.trace_hndl);
	
	return result;
}

/** ****************************************************************************

  @brief      Initializes configuration module

  @param[in]  None

  @return     void

*******************************************************************************/
ErrorCode_t engine_init_config()
{
	ErrorCode_t result = ENGINE_OK;

	// set config file name
	snprintf(engine.options.conf_file_path, 
    	PATH_MAX, 
    	"%s/conf/%s.conf",
    	engine.plat_home,
    	engine.exe_name);

    // load config
    result = config_engine_read(&engine.conf_hndl, 
    	engine.options.conf_file_path, 
    	engine.config.params);

    return result;
}


/** ****************************************************************************

  @brief      Initializes configuration module

  @param[in]  None

  @return     void

*******************************************************************************/
ErrorCode_t engine_init_db()
{
	ErrorCode_t result = ENGINE_OK;

    // initialize DB parameters
    engine.db_connection.hndl = NULL;
    engine.db_connection.host = engine.config.params[DB_HOST_ID];
    engine.db_connection.port = atoi(engine.config.params[DB_PORT_ID]);
    engine.db_connection.user = engine.config.params[DB_USER_ID];
    engine.db_connection.password = engine.config.params[DB_PASSWORD_ID];
    engine.db_connection.db_name = engine.config.params[DB_NAME_ID];

	// db
	result = db_init(&engine.db_connection);

	// Add columns newer code needs to older databases
	if(result == ENGINE_OK) db_upgrade_schema();

	// The master game time scale comes from this engine's configuration
	if(result == ENGINE_OK) game_time_set_scale(atof(engine.config.params[GAME_TIME_SCALE_ID]));

	return result;
}

/** ****************************************************************************

  @brief      Stops engine submodules

  @param[in]  None

  @return     void

*******************************************************************************/
void engine_stop_all()
{
	// DB
    db_stop(&engine.db_connection);

    // CONFIG
    config_engine_stop(&engine.conf_hndl);

	engine.status = ENGINE_STOPPED_STATUS;

	engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------");
    engine_trace(TRACE_LEVEL_ALWAYS, "Engine stopped (PID [%d])", getpid());
    engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------");

    // TRACES
    trace_stop(engine.trace_hndl);
}

/** ****************************************************************************

  @brief      Inserts a new command into DB (commands table)

  @param[in]  command String to be inserted

  @return     void

*******************************************************************************/
void engine_insert_db_command(char* command)
{
    if(command)
    {
        Command_t new_command;
        memcpy(&new_command, &engine.last_command, sizeof(new_command));
        sprintf(new_command.code, "%s", command);
        if(engine.last_command.email_content) {
            size_t size = strlen(engine.last_command.email_content) + 1;
            new_command.email_content = engine_malloc(size);
            sprintf(new_command.email_content, "%s", engine.last_command.email_content);
            db_insert_command(&engine.db_connection, &new_command);
            engine_free(new_command.email_content, size);
            new_command.email_content = NULL;
        } else {
            db_insert_command(&engine.db_connection, &new_command);
        }
    }
}

/** ****************************************************************************

  @brief      Checks if current VM is busy and can not process more commands

  @param[in]  None

  @return     boolean result (true when busy)

*******************************************************************************/
Bool_t engine_is_current_vm_busy()
{
    const char* resume_comand = engine_get_vm_resume_command();

    // Check that current command is not a resume command and also if there are resume commands at DB
    if(strncmp(engine.last_command.code, resume_comand, strlen(resume_comand)))
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Command [%s] is NOT a [%s], check if VM is running other commands",
            engine.last_command.code,
            resume_comand);

        // check if we have any pending command
        return db_agent_has_running_command(&engine.db_connection, engine.last_command.agent_id);
    }

    return ENGINE_FALSE;
}

/** ****************************************************************************

  @brief          Gets the position of a given object ID (body and distance from Earth)

  @param[in]      object_id     Current object ID (drone object ID)
  @param[out]     position      Output buffer where we store current position
  @param[out]     distance      Output buffer where we store current distance from Earth

  @return         Error code

*******************************************************************************/
ErrorCode_t engine_get_object_position(int object_id, CartesianInfo_t* out, char* name)
{
    ObjectOrbitInfo_t object;
    int steps = 0;

    memset(out, 0, sizeof(*out));
    memset(&object, 0, sizeof(object));
    object.object_id = object_id;

    // Add up the body's position around its central body, that body's
    // position around its own, and so on up to the Sun
    while(steps++ < 10)
    {
        if(db_get_orbit_info(&engine.db_connection, &object) != ENGINE_OK) {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to obtain position for OBJ_ID=[%d]", object.object_id);
            return ENGINE_DB_QUERY_ERROR;
        }

        if(steps == 1 && name)
            sprintf(name, "%s", object.object_name);

        // The Sun (object 0) is the origin
        if(object.object_id == 0)
            return ENGINE_OK;

        CartesianInfo_t position;
        CartesianInfo_t total = *out;
        orbits_get_object_position(&object, &position);
        orbits_add_coordinates(&position, &total, out);

        // Planets, asteroids and comets orbit the Sun (central body 0)
        if(object.central_body_object_id <= 0 || object.central_body_object_id == object.object_id)
            return ENGINE_OK;

        object.object_id = object.central_body_object_id;
    }

    engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: OBJ_ID=[%d] has too many central bodies", object_id);
    return ENGINE_INTERNAL_ERROR;
}

ErrorCode_t engine_get_objects_distance(int object_a, int object_b, double* distance)
{
    CartesianInfo_t a, b;
    ErrorCode_t result = engine_get_object_position(object_a, &a, NULL);

    if(result == ENGINE_OK)
        result = engine_get_object_position(object_b, &b, NULL);

    if(result == ENGINE_OK)
        *distance = sqrt((a.x - b.x) * (a.x - b.x) + (a.y - b.y) * (a.y - b.y) + (a.z - b.z) * (a.z - b.z));

    return result;
}

ErrorCode_t engine_get_drone_position(int object_id, char* position, double* distance)
{
    ObjectOrbitInfo_t earth;

    if(!position || !distance)
        return ENGINE_INTERNAL_ERROR;

    memset(&earth, 0, sizeof(earth));
    if(db_get_object_info_by_name(&engine.db_connection, "Earth", &earth) != ENGINE_OK) {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to obtain EARTH position");
        return ENGINE_DB_QUERY_ERROR;
    }

    CartesianInfo_t drone;
    ErrorCode_t result = engine_get_object_position(object_id, &drone, position);

    if(result == ENGINE_OK)
        result = engine_get_objects_distance(object_id, earth.object_id, distance);

    if(result == ENGINE_OK)
        engine_trace(TRACE_LEVEL_ALWAYS, "OBJ_ID=[%d] is at [%s], [%.0f] km from Earth", object_id, position, *distance);

    return result;
}

/** ****************************************************************************

  @brief      Times when new commands reach their drones. A player's mail
              travels from Earth at the speed of light, so a command runs
              only once its signal has crossed the distance to the drone
              (scaled by the game clock). Engine resumes are immediate.

  @return     void

*******************************************************************************/
static void engine_stamp_commands(void)
{
    int ids[50], objects[50], immediate[50];
    int count = 0;

    if(db_get_unstamped_commands(&engine.db_connection, ids, objects, immediate, 50, &count) != ENGINE_OK)
        return;

    for(int i = 0; i < count; i++)
    {
        int delay = 0;
        char position[MAX_OBJECT_NAME_SIZE];
        double km = 0;

        if(!immediate[i] && engine_get_drone_position(objects[i], position, &km) == ENGINE_OK)
            delay = game_time_light_delay(km);

        if(delay > 0)
            engine_trace(TRACE_LEVEL_ALWAYS, "Command [%d] reaches its drone at [%s] in [%d] s", ids[i], position, delay);

        db_set_command_delivery(&engine.db_connection, ids[i], delay);
    }
}

/** ****************************************************************************

  @brief      Before resuming a script paused by perform, leaves perform's
              result on its stack: 0 when the action succeeded, else why it
              failed (a negative outcome code)

  @return     ENGINE_FALSE while the action is still running (the script
              stays paused), ENGINE_TRUE when the script can resume

*******************************************************************************/
static Bool_t engine_finish_perform(void)
{
    int agent_id = engine.last_command.agent_id;
    int action_id = 0, running = 0, code = PERFORM_OK;

    if(db_get_agent_waiting_action(&engine.db_connection, agent_id, &action_id) != ENGINE_OK || !action_id)
        return ENGINE_TRUE; // not waiting: a resume after a time slice

    if(db_get_action_result(&engine.db_connection, action_id, &running, &code) == ENGINE_OK && running)
    {
        // Not done yet (normally the resume is held back until it is)
        engine_trace(TRACE_LEVEL_ALWAYS, "Drone [%d] still waits for action [%d]", agent_id, action_id);
        vm_wait_for_action(action_id);
        return ENGINE_FALSE;
    }

    engine_trace(TRACE_LEVEL_ALWAYS, "Action [%d] done for drone [%d]: perform leaves [%d]", action_id, agent_id, code);

    vm_push_result(engine.last_agent.vm, code);
    db_set_agent_waiting_action(&engine.db_connection, agent_id, 0);
    return ENGINE_TRUE;
}

/** ****************************************************************************

  @brief      Processes a VM command (command to be executed on the VM)

  @param[in|out]  out_buf       Input/output buffer where we will store email response
  @param[in]      out_buf_size  Buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_process_vm_command(char* out_buf, size_t out_buf_size)
{
    ErrorCode_t result = ENGINE_OK;

    engine_trace(TRACE_LEVEL_ALWAYS, "Processing VM command [%s] at agent [%d]", 
        engine.last_command.code, engine.last_command.agent_id);

    // When busy, wait
    if(engine_is_current_vm_busy() == ENGINE_TRUE)
    {
        engine_send_output("Another command is running, retry later", false);
        out_buf[0] = 0; // do not output more emails 
        return ENGINE_OK;
    }

    // A new script (not a resume) starts with no collected output
    const char* resume = engine_get_vm_resume_command();
    if(strncmp(engine.last_command.code, resume, strlen(resume)) != 0)
        free(db_take_agent_output(&engine.db_connection, engine.last_command.agent_id));
    else if(engine_finish_perform() == ENGINE_FALSE)
    {
        out_buf[0] = 0; // still waiting: stay paused, nothing to email yet
        return ENGINE_OK;
    }

    // VM command & not busy -> execute it and take the suitable actions
    // A resumed script carries on with the lines it hadn't read yet
    char* pending_input = NULL;
    if(strncmp(engine.last_command.code, resume, strlen(resume)) == 0)
        pending_input = db_get_agent_pending_input(&engine.db_connection, engine.last_command.agent_id);

    result = vm_run_command(engine.last_agent.vm, &engine.last_command, out_buf, out_buf_size, &pending_input);

    // Keep the unread rest of a paused script (NULL clears it)
    db_set_agent_pending_input(&engine.db_connection, engine.last_command.agent_id, pending_input);
    free(pending_input);
    g_ran_on_vm = true;

    // perform started an action: the script stays paused until it is done
    if(vm_waiting_action())
        db_set_agent_waiting_action(&engine.db_connection, engine.last_command.agent_id, vm_waiting_action());

    AgentInfo_t info;
    // Update drone info (just in case, we executed a location change)
    if(result == ENGINE_OK)
    {
        // Get current values
        result = db_get_agent_engine_info(&engine.db_connection, 
            engine.last_command.agent_id,
            &info);
    }

    if(result == ENGINE_OK)
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Drone [%d] moving from objectId [%d] to [%d]",
            engine.last_command.agent_id,
            engine.last_agent.object_id,
            info.object_id);

        // Update object ID to use it when calculating distances and sending email
        engine.last_agent.object_id = info.object_id;
    }

    return result;
}

#define MAX_NEW_NAME_LEN 64

/** True when name is usable as a drone's email local part. */
static bool engine_is_valid_agent_name(const char* name)
{
    static const char* reserved[] = {
        "registrar", "catchall", "services", "postmaster", "abuse", "hostmaster",
        "webmaster", "admin", "root", "help", "noreply", "no-reply", "mailer-daemon", "dscc",
    };
    size_t len = strlen(name);
    if(len == 0 || len > MAX_NEW_NAME_LEN)
        return false;
    for(size_t i = 0; i < len; i++) {
        unsigned char c = (unsigned char)name[i];
        if(!isalnum(c) && c != '.' && c != '-' && c != '_')
            return false;
    }
    if(name[0] == '.' || name[len - 1] == '.' || strstr(name, ".."))
        return false;
    for(size_t i = 0; i < sizeof(reserved) / sizeof(reserved[0]); i++) {
        if(strcasecmp(name, reserved[i]) == 0)
            return false;
    }
    return true;
}

/** ****************************************************************************

  @brief      Processes rename meta command

  @param[in|out]  out_buf       Input/output buffer where we will store email response
  @param[in]      out_buf_size  Buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_process_meta_rename(char* out_buf, size_t out_buf_size)
{
    ErrorCode_t result = ENGINE_OK;
    ErrorCode_t db_result = ENGINE_OK;

    // input params checked at calling function

    // Process rename
    // Get the new name
    char *start = strchr(engine.last_command.code, '>');
    start++;
    char *end = strchr(start, '<');
    char *name = (char*) engine_malloc(end - start + 1);
    snprintf(name, (end - start + 1), "%s", start);
    engine_trace(TRACE_LEVEL_ALWAYS, "Changing agent [%d] name to [%s]", engine.last_command.agent_id, name);

    // validate new name: it becomes the drone's email address, so only
    // letters, digits, '.', '-' and '_', not a reserved address, and unique
    if(!engine_is_valid_agent_name(name)) {
        snprintf(out_buf, out_buf_size, "Command error: agent name [%.64s] must be 1-%d letters, digits, '.', '-' or '_', and not a reserved address", name, MAX_NEW_NAME_LEN);
    } else if ((db_result=db_check_agent_name(&engine.db_connection, name)) == ENGINE_OK) {
        snprintf(out_buf, out_buf_size, "Command error: agent name [%s] already exists", name);
    } else if (db_result == ENGINE_DB_NOT_FOUND_ERROR) {
        // valid name -> update it
        result = db_update_agent_name(&engine.db_connection, engine.last_command.agent_id, name);
        if(result == ENGINE_OK) {
            snprintf(out_buf, out_buf_size, "Agent name changed to [%s]", name);
        }
        // Update agent name to keep the emails chain
        AgentInfo_t info;
        if(result == ENGINE_OK)
        {
            // Get current values
            result = db_get_agent_engine_info(&engine.db_connection, 
                engine.last_command.agent_id,
                &info);
        }

        if(result == ENGINE_OK)
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
                "Agent name updated [%s] -> [%s]",
                engine.last_agent.agent_name,
                info.agent_name);

            // Update name to answer using this new one
            sprintf(engine.last_agent.agent_name, "%s", info.agent_name);
        }
    }

    if(name) engine_free(name, (strlen(name)+1));

    return result;
}

/** ****************************************************************************

  @brief      Processes rebrand meta command

  @param[in|out]  out_buf       Input/output buffer where we will store email response
  @param[in]      out_buf_size  Buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_process_meta_rebrand(char* out_buf, size_t out_buf_size)
{
    ErrorCode_t result = ENGINE_OK;

    // Process rebrand
    // Get the new company name
    char *start = strchr(engine.last_command.code, '>');
    start++;
    char *end = strchr(start, '<');
    char *company_name = (char*) engine_malloc(end - start + 1);
    snprintf(company_name, (end - start + 1), "%s", start);
    engine_trace(TRACE_LEVEL_ALWAYS, "Changing agent [%d] company to [%s]", engine.last_command.agent_id, company_name);

    size_t company_len = strlen(company_name);
    ErrorCode_t db_result = (company_len == 0 || company_len > MAX_NEW_NAME_LEN)
        ? ENGINE_LOGIC_ERROR
        : db_check_company_name(&engine.db_connection, company_name);

    if (company_len == 0 || company_len > MAX_NEW_NAME_LEN) {
        snprintf(out_buf, out_buf_size, "Command error: company name must be 1-%d characters", MAX_NEW_NAME_LEN);
        result = ENGINE_LOGIC_ERROR;
    } else if (db_result == ENGINE_OK) {
        snprintf(out_buf, out_buf_size, "Command error: company name [%s] already exists", company_name);
        result = ENGINE_LOGIC_ERROR;
    } else if(db_result == ENGINE_DB_NOT_FOUND_ERROR) {
        result = db_update_agent_company(&engine.db_connection, engine.last_command.agent_id, company_name);
        if(result == ENGINE_OK) {
            snprintf(out_buf, out_buf_size, "Agent company name changed to [%s]", company_name);
        }
    }
    engine_free(company_name, (strlen(company_name)+1));

    return result;
}

/** ****************************************************************************

  @brief      Starts a drone the events engine has just launched: its copied
              program goes back to the prompt (whatever drone D was doing
              when it was copied), the owner is told the drone exists, and it
              runs its launched word once if the program defines one.

  @param[in|out]  out_buf       Output buffer
  @param[in]      out_buf_size  Buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
static ErrorCode_t engine_process_meta_launched(char* out_buf, size_t out_buf_size)
{
    vm_restart(engine.last_agent.vm);

    bool run = vm_word_defined(engine.last_agent.vm, LAUNCHED_WORD);

    engine_send_output(run ?
        "Launched. Running my launched word." :
        "Launched. My program has no launched word: send me commands.", false);

    if(!run)
        return ENGINE_OK;

    snprintf(engine.last_command.code, sizeof(engine.last_command.code), "%s\n", LAUNCHED_WORD);
    return engine_process_vm_command(out_buf, out_buf_size);
}

/** ****************************************************************************

  @brief      Processes a meta command (command NOT execute on the VM)

  @param[in|out]  out_buf       Input/output buffer where we will store email response
  @param[in]      out_buf_size  Buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_process_meta_command(char* out_buf, size_t out_buf_size)
{
    ErrorCode_t result = ENGINE_OK;

    // input params checked at calling function

    engine_trace(TRACE_LEVEL_ALWAYS, "Processing meta command [%s] at agent [%d]", 
        engine.last_command.code, engine.last_command.agent_id);

    // Identify the meta-command. The code may carry surrounding whitespace
    // (the line splitter ends every line with \n), so ignore it for abort.
    const char* code = engine.last_command.code;
    while(isspace((unsigned char)*code)) code++;
    size_t code_len = strlen(code);
    while(code_len > 0 && isspace((unsigned char)code[code_len - 1])) code_len--;
    bool is_abort = (code_len == strlen(META_COMMAND_ABORT)) &&
        (strncasecmp(code, META_COMMAND_ABORT, code_len) == 0);

    if(is_abort) {
        engine_trace(TRACE_LEVEL_ALWAYS, "Running abort command at agent [%d]", engine.last_command.agent_id);
        // abort and delete any pending resume
        vm_abort(engine.last_agent.vm);
        result = db_delete_resume_commands(&engine.db_connection, engine.last_command.agent_id);
        // The script no longer waits for an action (the action itself goes on)
        db_set_agent_waiting_action(&engine.db_connection, engine.last_command.agent_id, 0);
        db_set_agent_pending_input(&engine.db_connection, engine.last_command.agent_id, NULL);
    } else if (strstr(engine.last_command.code, META_COMMAND_RESET)) {
        engine_trace(TRACE_LEVEL_ALWAYS, "Running reset command at agent [%d]", engine.last_command.agent_id);
        // reset and start with a fresh VM
        vm_reset(engine.last_agent.vm);
        if(engine.last_agent.vm) vm_free(engine.last_agent.vm);
        engine.last_agent.vm = NULL;
        result = db_save_agent_vm(&engine.db_connection, engine.last_command.agent_id, engine.last_agent.vm);
        // A paused script's resume must not run on the fresh VM
        if(result == ENGINE_OK)
            result = db_delete_resume_commands(&engine.db_connection, engine.last_command.agent_id);
        free(db_take_agent_output(&engine.db_connection, engine.last_command.agent_id));
        db_set_agent_waiting_action(&engine.db_connection, engine.last_command.agent_id, 0);
        db_set_agent_pending_input(&engine.db_connection, engine.last_command.agent_id, NULL);
    } else if (code_len == strlen(META_COMMAND_LAUNCHED) &&
               strncasecmp(code, META_COMMAND_LAUNCHED, code_len) == 0) {
        result = engine_process_meta_launched(out_buf, out_buf_size);
    } else if (strstr(engine.last_command.code, META_COMMAND_RENAME)) {
        result = engine_process_meta_rename(out_buf, out_buf_size);
    } else if (strstr(engine.last_command.code, META_COMMAND_REBRAND)) {
        result = engine_process_meta_rebrand(out_buf, out_buf_size);
    }

    return result;
}

/** ****************************************************************************

  @brief      Processes a command read from DB and generates output for email

  @param[in|out]  out_buf       Input/output buffer where we will store email response
  @param[in]      out_buf_size  Buffer size

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_process_command(char* out_buf, size_t out_buf_size)
{
    ErrorCode_t result;

    if(!out_buf || (out_buf_size == 0)) {
        engine_trace(TRACE_LEVEL_ALWAYS,  "ERROR: Unable to process command (invalid buffer)");
        return ENGINE_INTERNAL_ERROR;
    }

    // We need to identify if it is a VM command or just a meta-command
    if(engine.last_command.code[0] == '<') {
        // meta command
        result = engine_process_meta_command(out_buf, out_buf_size);
    } else {
        // VM command
        result = engine_process_vm_command(out_buf, out_buf_size);
    }

    // When processing fails, indicate it at email
    if(result != ENGINE_OK && !out_buf[0])
    {
        engine_send_output("Command error", false);
    }

    return result;
}

/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Initializes engine modules using command line options received

  @param[in]  argc, argv Command line options

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_init(int argc, char** argv)
{
	// Initialize engine
	memset(&engine, 0, sizeof(engine));

	engine.status = ENGINE_IDLE_STATUS;

	engine.argc = argc;
	engine.argv = argv;

	engine.error = ENGINE_OK;

    // Get the basename
    engine.exe_name = argv[0];
    char* pos = strrchr(argv[0], '/');
    if(pos)
    {
        engine.exe_name = ++pos;
    }

	// Check PLAT_HOME - mandatory
	engine.plat_home = getenv(PLAT_HOME);

	if(!engine.plat_home)
	{
		engine.error = ENGINE_PLAT_HOME_ERROR;
	}

    if(engine.error == ENGINE_OK)
    {
		signal(SIGINT, signals_handler);
    	signal(SIGTERM, signals_handler);

    	// Define exit callback
    	atexit(engine_stop);
    }

    // Initialize traces
    if(engine.error == ENGINE_OK)
    {
    	engine.error = engine_init_traces();
    }

    engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------------");
    engine_trace(TRACE_LEVEL_ALWAYS, 
        "Engine started (NAME [%s] PID [%d])", engine.exe_name, getpid());
    engine_trace(TRACE_LEVEL_ALWAYS, "-----------------------------------------------");

    // Initialize config
    if(engine.error == ENGINE_OK)
    {
    	engine.error = engine_init_config();
    }

    // update trace level using conf
    trace_set_level(engine.trace_hndl, 
        atoi(engine.config.params[APP_TRACE_LEVEL_ID]));

    // Initialize DB connection
    if(engine.error == ENGINE_OK)
    {
    	engine.error = engine_init_db();
    }

    // stop-all when error
    if(engine.error != ENGINE_OK)
    {
    	engine_stop_all();
    }

	return engine.error;
}

/** ****************************************************************************

  @brief      For a given command read from DB we apply max lines limitation
              and store the rest in the DB again.

  @param[in]  void

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_apply_max_cycle_lines()
{
    ErrorCode_t result = ENGINE_OK;

    // Get configured limit
    int max_lines = 10000; //atoi(engine.config.params[MAX_LINES_PER_CYCLE_ID]);

    engine_trace(TRACE_LEVEL_ALWAYS, 
        "Applying [%d] lines limit to command [%s]",
        max_lines,
        engine.last_command.code);

    // Extract both the code to be executed and the remaining
    char new_code[MAX_COMMAND_CODE_SIZE+1];
    char remaining_code[MAX_COMMAND_CODE_SIZE+1];

    new_code[0] = 0;
    remaining_code[0] = 0;


    // go line by line
    char* buffer = strdup(engine.last_command.code);
    char* line = strtok(buffer, "\r\n");
    int lines_read = 0;

    char* new_pos = new_code;
    char* remaining_pos = remaining_code;

    // Each line goes after the previous ones; pass only the space left
    char* new_end = new_code + sizeof(new_code);
    char* remaining_end = remaining_code + sizeof(remaining_code);

    while(line) {
        lines_read++;
        int written;

        // The VM reads at most FORTH_MAX_LINE characters per line: re-wrap
        // longer lines at spaces
        char wrapped[MAX_COMMAND_CODE_SIZE+1];
        int wrap_result = forth_wrap_line(line, FORTH_MAX_LINE, wrapped, sizeof(wrapped));
        if(wrap_result == WRAP_TOO_LONG) {
            engine_trace(TRACE_LEVEL_ALWAYS, "Line [%d] can't be split to fit: [%s]", lines_read, line);
            snprintf(new_code, sizeof(new_code),
                ".( Error: line %d has a string longer than %d characters.) cr\n"
                ".( Split it into shorter strings. Nothing was run.) cr\n",
                lines_read, FORTH_MAX_LINE);
            remaining_code[0] = 0;
            break;
        }
        if(wrap_result == WRAP_OK) {
            line = wrapped;
        }

        if(lines_read <= max_lines) {
            written = snprintf(new_pos, new_end - new_pos, "%s\n", line);
            new_pos += (written < new_end - new_pos) ? written : (new_end - new_pos - 1);
        } else {
            written = snprintf(remaining_pos, remaining_end - remaining_pos, "%s\n", line);
            remaining_pos += (written < remaining_end - remaining_pos) ? written : (remaining_end - remaining_pos - 1);
        }

        // Get next one
        line  = strtok(NULL, "\r\n");
    }

    engine_trace(TRACE_LEVEL_ALWAYS, 
        "New code [%s] remaining [%s]",
        new_code,
        remaining_code);

    // Make new code = current and store remaining as new command
    sprintf(engine.last_command.code, "%s", new_code);

    if(remaining_code[0] != 0) {
        Command_t new_command;
        memcpy(&new_command, &engine.last_command, sizeof(new_command));
        sprintf(new_command.code, "%s", remaining_code);
        new_command.email_content = strdup(engine.last_command.email_content);

        result = db_insert_command(&engine.db_connection, &new_command);

        free(new_command.email_content);
    }
    
    free(buffer);

    return result;
}


/** ****************************************************************************

  @brief      Runs engine logic loop

  @param[in]  void

  @return     Execution result code (ErrorCode_t)

*******************************************************************************/
ErrorCode_t engine_run()
{
  ErrorCode_t result = ENGINE_OK;

	engine.status = ENGINE_RUNNING_STATUS;

    // Checks periodically for commands, executes them and stores VM status

	while(engine.status == ENGINE_RUNNING_STATUS)
	{
        // reset VM
        engine.last_agent.vm = NULL;

        // Forget the last drone's pause before loading the next drone's VM:
        // loading runs FORTH (it defines perform, query...), and a stale
        // "waiting for an action" flag would stop it at once, leaving a new
        // drone without those words
        vm_clear_yield();

		engine_trace(TRACE_LEVEL_ALWAYS, "Running engine logic");

        // Read the game clock (shared with the events engine)
        game_time_load();

        // Signals travel at the speed of light: time when new commands reach
        // their drones, and send the replies that have reached Earth
        engine_stamp_commands();
        db_send_due_outbox(&engine.db_connection, engine.config.params[SEND_EMAIL_SCRIPT_ID]);

        // start a transaction
        result = db_start_transaction(&engine.db_connection);

        if(result == ENGINE_OK)
        {
            // get next command
            result = db_get_next_command(&engine.db_connection, &engine.last_command);
        }

        if(result == ENGINE_OK)
        {
            // apply configured max lines per cycle
            result = engine_apply_max_cycle_lines();

            if(result != ENGINE_OK)
            {
                engine_send_output("Internal error", false);
            }
        }


        if(result == ENGINE_OK)
        {
            // Get whole info for agent_id
            result = db_get_agent_engine_info(&engine.db_connection, 
                engine.last_command.agent_id,
                &engine.last_agent);
        }

        if(result == ENGINE_OK)
        {
            // save last agent input & subject command info
            result = db_update_agent_input(&engine.db_connection, 
                engine.last_command.agent_id, 
                &engine.last_command);
        }            

        char out_buffer[ENGINE_MAX_BUF_SIZE+1];
        if(result == ENGINE_OK)
        {
            // Process input command and retrieve result text in out_buffer
            memset(out_buffer, 0, ENGINE_MAX_BUF_SIZE+1);
            vm_clear_yield();
            g_ran_on_vm = false;
            engine_process_command(out_buffer, ENGINE_MAX_BUF_SIZE);
        } 

        if(result == ENGINE_OK)
        {
            if(vm_is_yield()) 
            {
                // Keep this slice's output: it is emailed with the rest when
                // the script finishes (or calls report)
                // (skip slices with only whitespace: each pause adds one)
                if(out_buffer[strspn(out_buffer, " \t\r\n")] != 0) 
                    db_append_agent_output(&engine.db_connection, 
                        engine.last_command.agent_id, out_buffer);

                // When we yield - insert special command to indicate that we need to resume later
                engine_insert_db_command(engine.config.params[VM_RESUME_COMMAND]);
            }
            else if(g_ran_on_vm)
            {
                // The script finished: email everything it printed
                engine_vm_output_cb(out_buffer);
            }
            else if(out_buffer[0] != 0) 
            {
                engine_send_output(out_buffer, false);
            }
        }           

        if(result == ENGINE_OK)
        {
            // Save VM in DB
            result = db_save_agent_vm(&engine.db_connection, 
                engine.last_command.agent_id, 
                engine.last_agent.vm);
        }

        // Delete command always to avoid spam when error
        if(engine.last_command.code[0]) {
            result = db_delete_command(&engine.db_connection, &engine.last_command);
        }

        // commit or rollback current transaction
        if((result == ENGINE_OK) || (result == ENGINE_DB_NOT_FOUND_ERROR))
        {
            result = db_commit_transaction(&engine.db_connection);
        }
        else
        {
            result = db_rollback_transaction(&engine.db_connection);
        }

        // always deallocate VM and command resources
        if(engine.last_agent.vm) vm_free(engine.last_agent.vm);
        if(engine.last_command.email_content) {
            engine_free(engine.last_command.email_content, strlen(engine.last_command.email_content) + 1);
            engine.last_command.email_content = NULL;
        }

		sleep(atoi(engine.config.params[DB_READ_TIME_ID]));
	}

	// deallocate resources/Stop modules
    engine_stop_all();
	
	return engine.error;
}

/** ****************************************************************************

  @brief      Stops engine execution

  @param[in]  void

  @return     void

*******************************************************************************/
void engine_stop()
{
	if((engine.status != ENGINE_STOPPING_STATUS) && 
		(engine.status != ENGINE_STOPPED_STATUS))
	{
		engine.status = ENGINE_STOPPING_STATUS;

		engine_trace(TRACE_LEVEL_ALWAYS, "Engine is stopping...");
	}
}

/** ****************************************************************************

  @brief      Checks if engine is stopped

  @param[in]  void

  @return     Boolean result

*******************************************************************************/
Bool_t engine_is_stopped()
{
	if(engine.status != ENGINE_RUNNING_STATUS)
		return ENGINE_TRUE;

	return ENGINE_FALSE;
}

/** ****************************************************************************

  @brief      Gets last engine error

  @param[in]  void

  @return     Last error code

*******************************************************************************/
int engine_get_last_error()
{
	return engine.error;
}

/** ****************************************************************************

  @brief      Logs an engine trace in output log file

  @param[in]  level Trace level
  @param[in]  trace Trace format text
  @param[in]  ...   Variable list of arguments

  @return     void

*******************************************************************************/
void engine_trace(TraceLevel_t level, const char *trace, ... )
{
	static va_list valist;
	
	va_start(valist, trace);
	trace_write(engine.trace_hndl, level, trace, valist);
	va_end(valist);
}

/** ****************************************************************************

  @brief      Initializes a log line only with date and level, this function must be 
              called before using trace_append

  @param[in]  level  Trace level
  
  @return     void

*******************************************************************************/
void engine_trace_header(TraceLevel_t level)
{
    trace_header(engine.trace_hndl, level);
}

/** ****************************************************************************

  @brief      Appends more text to current log line, no new line is added

  @param[in]  level Trace level
  @param[in]  trace Trace format text
  @param[in]  ...   Variable list of arguments

  @return     void

*******************************************************************************/
void engine_trace_append(TraceLevel_t level, const char *trace, ... )
{
    static va_list valist;
    
    va_start(valist, trace);
    trace_append(engine.trace_hndl, level, trace, valist);
    va_end(valist);
}

/** ****************************************************************************

  @brief      Function used to send VM output to users

  @param[in]  msg      Output msg

  @return     void

*******************************************************************************/
void engine_send_output(const char* msg, bool with_pending)
{
    int agent_id = engine.last_command.agent_id;

    if(msg)
    {
        // With pending: what the script printed before it last paused comes
        // first. Copy everything: msg may be the VM's own buffer.
        char* pending = with_pending ? db_take_agent_output(&engine.db_connection, agent_id) : strdup("");
        size_t full_size = strlen(pending ? pending : "") + strlen(msg) + 1;
        char* full = malloc(full_size);
        if(!full) { free(pending); return; }
        snprintf(full, full_size, "%s%s", pending ? pending : "", msg);
        free(pending);
        msg = full;

        // Remove the "query redefined" warning that reloading a VM prints
        char* pos;
        while((pos = strstr(full, "query redefined")) != NULL)
            memmove(pos, pos + strlen("query redefined"), strlen(pos + strlen("query redefined")) + 1);

        // A script that printed nothing (e.g. one that only defines words)
        // still gets a reply, so the player knows it ran
        if(full[strspn(full, " \t\r\n")] == 0) {
            if(!with_pending) { free(full); return; }
            free(full);
            full = strdup("ok");
            if(!full) return;
            msg = full;
        }

        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Output VM msg [%s] read for agent [%d]",
            msg,
            agent_id); 


        // Get agent email info and send it by email
        EmailInfo_t email_info;
        memset(&email_info, 0, sizeof(email_info));

        // Fill the info we have so far
        email_info.agent_id = agent_id;
        // email format
        snprintf(email_info.email_template, 
            MAX_COMMAND_CODE_SIZE, 
            "%s", 
            engine.config.params[SEND_EMAIL_TEMPLATE_ID]);


        // Get current position
        engine_get_drone_position(engine.last_agent.object_id, email_info.drone_position, &email_info.distance);

        // The reply reaches Earth at the speed of light (scaled by the game clock)
        email_info.delay = game_time_light_delay(email_info.distance);

        // Every reply starts with where the drone is (as the player's manual
        // promises) and how far its signal travels to reach Earth
        double light_minutes = email_info.distance / LIGHT_SPEED_KM_PER_MINUTE;
        if(email_info.drone_position[0] && light_minutes < 1)
            snprintf(email_info.message, MAX_COMMAND_CODE_SIZE, "Location: %s (%.1f light-seconds from Earth)\n\n%s",
                email_info.drone_position, light_minutes * 60, msg);
        else if(email_info.drone_position[0])
            snprintf(email_info.message, MAX_COMMAND_CODE_SIZE, "Location: %s (%.1f light-minutes from Earth)\n\n%s",
                email_info.drone_position, light_minutes, msg);
        else
            snprintf(email_info.message, MAX_COMMAND_CODE_SIZE, "%s", msg); // it is quoted later at email module
        snprintf(email_info.email_script, PATH_MAX, "%s", engine.config.params[SEND_EMAIL_SCRIPT_ID]);

        if(db_get_agent_email_info(&engine.db_connection, &email_info) == ENGINE_OK)
        {
            // agent email
            snprintf(email_info.agent_email, 
                MAX_EMAIL_ADDRESS_SIZE, 
                "%s@%s", 
                email_info.agent_name,
                engine.config.params[AGENTS_EMAIL_DOMAIN_ID]); 

            // send email
            email_send(&email_info);
        }

        // Frees this function's copy only. The command's own email_content is
        // still needed (a paused script's resume is queued with it).
        if(email_info.input_content) {
            engine_free(email_info.input_content, strlen(email_info.input_content)+1);
        }
        free(full);
    }
    else
    {
        engine_trace(TRACE_LEVEL_ALWAYS, 
            "WARNING: NULL msg received from VM for agent [%d]",
            agent_id); 
    }
}

/** Emails a script's output, with what it printed before it last paused. */
void engine_vm_output_cb(const char* msg)
{
    engine_send_output(msg, true);
}

/** ****************************************************************************

  @brief      Allocation wrapper to control memory used by the engine

  @param[in]  size Size we want to allocate
  
  @return     Pointer to memory allocated or NULL when failed

*******************************************************************************/
void* engine_malloc(size_t size)
{
    void* memory = malloc(size);

    if(memory)
    {
        engine.memory_in_use += size; 

        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Allocated [%ld] bytes (Total memory: [%ld])",
            size,
            engine.memory_in_use);
    }

    return memory;
}

/** ****************************************************************************

  @brief      Free wrapper to control memory used by the engine

  @param[in]  Pointer to memory we want to deallocate
  
  @return     void

*******************************************************************************/
void engine_free(void* memory, size_t size)
{
    if(memory)
    {
        free(memory);

        engine.memory_in_use -= size; 

        engine_trace(TRACE_LEVEL_ALWAYS, 
            "Deallocated [%ld] bytes (Total memory in use: [%ld])",
            size,
            engine.memory_in_use);
    }
}

/** ****************************************************************************

  @brief      Gets path of the forth image used as template to create VM

  @param[in]  None
  
  @return     Path string

*******************************************************************************/
const char* engine_get_forth_image_path()
{
    return (const char*)engine.config.params[FORTH_IMAGE_PATH_ID];
}

/** ****************************************************************************

  @brief      Gets the max number of second each VM can spend processing commands

  @param[in]  None
  
  @return     Seconds value configured

*******************************************************************************/
const int engine_get_max_cycle_seconds()
{
    return atoi((const char*)engine.config.params[MAX_CYCLE_SECONDS]);
}

/** ****************************************************************************

  @brief      Gets the command defined (at config) as VM resume command
              It is an special command that is not executed at the VM, it just 
              indicates that we need to resume VM to continue executing pending commands

  @param[in]  None
  
  @return     Command string

*******************************************************************************/
const char* engine_get_vm_resume_command()
{
    return (const char*)engine.config.params[VM_RESUME_COMMAND];
}

/** ****************************************************************************

  @brief      Gets current engine DB connection

  @param[in]  None

  @return     Current DB connection info

*******************************************************************************/
DbConnection_t* engine_get_db_connection()
{
    if(!engine.db_connection.hndl)
        engine_init_db();

    return &engine.db_connection;
}

/** ****************************************************************************

  @brief      Gets current drone ID (drone processing commands)

  @param[in]  None

  @return     Drone ID

*******************************************************************************/
int engine_get_current_drone_id()
{
    return engine.last_command.agent_id;
}


/** ****************************************************************************

  @brief      Gets the flag (0/1) that controls VM dump into disk

  @param[in]  None
  
  @return     Flag value configured

*******************************************************************************/
const int engine_get_dump_vm_flag()
{
    return atoi((const char*)engine.config.params[FORTH_DUMP_VM]);
}

/** ****************************************************************************

  @brief      Gets the configured path to dump VM into memory

  @param[in]  None
  
  @return     Path value configured

*******************************************************************************/
const char* engine_get_dump_vm_path()
{
    return (const char*)engine.config.params[FORTH_VM_OUT_PATH];
}

/** Drones' acceleration when travelling (m/s^2 of game time) */
double engine_get_travel_acceleration()
{
    return atof(engine.config.params[TRAVEL_ACCELERATION_ID]);
}

/** Where the drone running the current command is (object ID) */
int engine_get_current_drone_object_id()
{
    return engine.last_agent.object_id;
}

