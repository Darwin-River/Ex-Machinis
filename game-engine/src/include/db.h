/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Engine module
 
******************************************************************************/

#ifndef __EM_DB_MODULE__
#define __EM_DB_MODULE__

/******************************* INCLUDES ************************************/

#include "common_types.h"

/******************************* DEFINES *************************************/

#define DB_MAX_SQL_QUERY_LEN (ENGINE_MAX_BUF_SIZE + 1024) // Max engine buffer + extra bytes for query statement

/******************************* TYPES ***************************************/

// Enum to control how we obtain the previous event
typedef enum
{
    PREVIOUS_EVENT_BY_RESOURCE_INFO,
    PREVIOUS_EVENT_BY_DRONE,
    PREVIOUS_EVENT_BY_OWNER,

    // Define here more filter types

} PreviousEventFilter_t;

/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/



/******************************* STATIC FUNCTIONS ****************************/


/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief          Checks connection status and reconnects when necessary

  @param[in|out]  Connection info

  @return         void

*******************************************************************************/
ErrorCode_t db_reconnect(DbConnection_t* connection);

/** ***************************************************************************

  @brief      Converts a given date string into timestamp value

  @param[in]  date    Input date string
  @param[in]  format  Desired format string

  @return     void

******************************************************************************/
time_t db_date_to_timestamp(char* date, char* format);

/** ***************************************************************************

  @brief          Creates a DB connection using credentials supplied

  @param[in|out]  Connection info, updated once connected

  @return         Execution result

******************************************************************************/
ErrorCode_t db_init(DbConnection_t* connection);

/** ****************************************************************************

  @brief          Closes connection with DB

  @param[in|out]  Connection info, updated once disconnected

  @return         void

*******************************************************************************/
void db_stop(DbConnection_t* connection);

/** ***************************************************************************

  @brief          Initiates a transaction (group of queries associated)

  @param[in|out]  DB Connection info

  @return         Execution result

******************************************************************************/
ErrorCode_t db_start_transaction(DbConnection_t* connection);

/** ***************************************************************************

  @brief          Ends/commits a transaction (group of queries associated)

  @param[in|out]  DB Connection info

  @return         Execution result

******************************************************************************/
ErrorCode_t db_commit_transaction(DbConnection_t* connection);

/** ***************************************************************************

  @brief          Rollback a transaction (group of queries associated)

  @param[in|out]  DB Connection info

  @return         Execution result

******************************************************************************/
ErrorCode_t db_rollback_transaction(DbConnection_t* connection);

/** ****************************************************************************

    @brief          Gets the next command

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Command info, command read is stored here once read.

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_next_command(DbConnection_t* connection, Command_t* command);

/** ****************************************************************************

    @brief          Inserts given command info into DB

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Command info, command to be inserted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_command(DbConnection_t* connection, Command_t* command);

/** ****************************************************************************

    @brief          Deletes a given command once processed

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Command info, command to be deleted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_command(DbConnection_t* connection, Command_t* command);

/** ****************************************************************************

    @brief          Deletes all resume commands currently stored in DB for a given user

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  agent_id  Current agent ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_resume_commands(DbConnection_t* connection, int agent_id);

/** ****************************************************************************

    @brief          Checks if there is any pending/paused command already running
                    for this agent

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  agent_id  Current agent ID

    @return         Checking result boolean

*******************************************************************************/
Bool_t db_agent_has_running_command(DbConnection_t* connection, int agent_id);

/** ****************************************************************************

    @brief          Gets current agent VM and other info required by the engine

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose VM data we want to get 
                    (we use current if any or create a new one)
    @param[in|out]  Output info obtained for input agent

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_agent_engine_info(DbConnection_t* connection, int agent_id, AgentInfo_t* agent);

/** ****************************************************************************

    @brief          Gets current agent VM

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose VM we want to update
    @param[in]      vm  VM object

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_save_agent_vm(DbConnection_t* connection, int agent_id, VirtualMachine_t* vm);

/** ****************************************************************************

    @brief          Updates latest command output in DB

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose VM output we want to update
    @param[in]      Output msg we need to update

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_agent_output(DbConnection_t* connection, int agent_id, char* msg);

/** ****************************************************************************

    @brief          Updates latest input output in DB

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose input we want to update
    @param[in]      Command information including msg and subject

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_agent_input(DbConnection_t* connection, int agent_id, Command_t* command);

/** ****************************************************************************

    @brief          Updates current agent object ID (when current location changes)

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose output we want to update
    @param[in]      New object ID to be set

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_agent_object(DbConnection_t* connection, int agent_id, int object_id);

/** ****************************************************************************

    @brief          Gets email data for a given agent ID

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Agent email info, calling function supplies here the agent_id and 
                    this function fills the rest of the information querying DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_agent_email_info(DbConnection_t* connection, EmailInfo_t* email_info);

/** ****************************************************************************

    @brief          Gets user ID and agent name for a given agent ID

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Output parameter where we store whole agent info and receive agent ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_agent_info(DbConnection_t* connection, AgentInfo_t* agent_info);

/** ****************************************************************************

    @brief          Gets email and name for a given user ID

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Current user ID 
    @param[in|out]  Output parameter where we store the users's email address
    @param[in|out]  Output parameter where we store the users's name

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_user_info(DbConnection_t* connection, int user_id, char* email, char* name);

/** ***************************************************************************

  @brief      Scapes an string value that will be used inside a mysql query

  @param[in]  value    Value to be scaped
  @param[in]  len      Value size
  @param[in]  out      out buffer
  @param[in]  out_len  Out buffer size

  @return     void

******************************************************************************/
void db_scape_string(
    DbConnection_t* connection, 
    char *value, 
    size_t len, 
    char *out, 
    size_t out_len
);

/** ****************************************************************************

    @brief          Gets orbit info for a given object ID

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Output parameter where we store the orbit info obtained from DB
                    This object contains also current object ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_orbit_info(DbConnection_t* connection, ObjectOrbitInfo_t* object);

/** ****************************************************************************

    @brief          Gets orbit info for a given object ID

    @param[in|out]  Output parameter where we store the orbit info obtained from DB
                    This object contains also current object ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_orbit_info_x(ObjectOrbitInfo_t* object);

/** ****************************************************************************

    @brief          Gets earth orbit info

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  name Object name we are looking for
    @param[in|out]  Output parameter where we store the orbit info obtained from DB
                    This object contains also current object ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_object_info_by_name(DbConnection_t* connection, char* name, ObjectOrbitInfo_t* object);

/** ****************************************************************************

    @brief          Gets earth orbit info

    @param[in|out]  name Object name we are looking for
    @param[in|out]  Output parameter where we store the orbit info obtained from DB
                    This object contains also current object ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_object_info_by_name_x(char* name, ObjectOrbitInfo_t* object);

/** ****************************************************************************

    @brief          Gets protocol info for a given protocol ID 
                    (received in IN/OUT protocol parameter)

    @param[in|out]  Output parameter where we store the protocol info obtained
                    This object contains also current protocol ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_prococol_info(ProtocolInfo_t* protocol);

/** ****************************************************************************

    @brief          Gets resource info for a given resource ID 
                    (received in IN/OUT protocol parameter)

    @param[in|out]  Output parameter where we store the resource info obtained
                    This object contains (as input) current resource ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_resource_info(Resource_t* resource);

/** ****************************************************************************

    @brief          Gets event type info for a given event type ID 
                    (received in IN/OUT protocol parameter)

    @param[in|out]  Output parameter where we store the event type info obtained
                    This object contains (as input) current event type ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_event_type_info(EventType_t* event_type);

/** ****************************************************************************

    @brief          Inserts a new event entry

    @param[in|out]  Event info to be inserted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_event(Event_t* event);

/** ****************************************************************************

    @brief          Gets last event for a given drone and resource

    @param[in|out]  Event info obtained (drone ID is filled)

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_previous_resource_event(Event_t* event);

/** ****************************************************************************

    @brief          Inserts a new action entry

    @param[in|out]  Action info to be inserted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_action(Action_t* action);

/** ****************************************************************************

    @brief          Aborts an action using its ID

    @param[in]      action_id Action to be aborted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_abort_action(int action_id);

/** ****************************************************************************

    @brief      Gets all the resource effects configured for a given protocol ID

    @param[in]      Protocol Info
    @param[in|out]  Output buffer where we store the effects found in DB for this protocol
    @param[in|out]  Output buffer size (number of effects returned)

    @return     Execution result

*******************************************************************************/
ErrorCode_t db_get_resource_effects
(
    ProtocolInfo_t* protocol, 
    ResourceEffect_t** effects, 
    int* effectsNum
);

/** ****************************************************************************

    @brief      Gets all the market effects configured for a given protocol ID

    @param[in]      Protocol Info
    @param[in|out]  Output buffer where we store the effects found in DB for this protocol
    @param[in|out]  Output buffer size (number of effects returned)

    @return     Execution result

*******************************************************************************/
ErrorCode_t db_get_market_effects
( 
    ProtocolInfo_t* protocol, 
    MarketEffect_t** effects, 
    int* effectsNum
);

/** ****************************************************************************

    @brief      Gets all the location effects configured for a given protocol ID

    @param[in]      Protocol Info
    @param[in|out]  Output buffer where we store the effects found in DB for this protocol
    @param[in|out]  Output buffer size (number of effects returned)

    @return     Execution result

*******************************************************************************/
ErrorCode_t db_get_location_effects
(
    ProtocolInfo_t* protocol, 
    LocationEffect_t** effects, 
    int* effectsNum
);

/** ****************************************************************************

    @brief          Deletes all events currently associated with a given action ID

    @param[in]      Input action ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_action_events(int action_id);

/** ****************************************************************************

    @brief          Purges old events present at DB (older than configurable days value)

    @param[in]      void

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_purge_old_events();

/** ****************************************************************************

    @brief          Deletes event giving event_id

    @param[in]      Input event

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_event(Event_t* event);

/** ****************************************************************************

    @brief          Gets next batch of outcome events

    @param[in]      Callback to be invoked when outcome event detected

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_outcome_events(void (*outcomeEventCb)(Event_t *e));

/** ****************************************************************************

    @brief          Updates event info using input info received

    @param[in]      event Event info to be updated

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_event(Event_t *event);

/** ****************************************************************************

    @brief          Gets previous event using input one and applying some filters

    @param[in]      event     Event to do the search
    @param[in]      filter    Enum that determines the filter to be applied at search
    @param[in]      out_event Output event obtained (when any)

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_previous_events(Event_t *event);

/** ****************************************************************************

    @brief          Gets action information from DB using its ID

    @param[in|out]  action Action information obtained, we will as input the ID to do the search

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_action(Action_t *action);

/** ****************************************************************************

    @brief          Gets previous event using input one and applying some filters

    @param[in]      event     Event to do the search
    @param[in]      filter    Enum that determines the filter to be applied at search
    @param[in]      out_event Output event obtained (when any)

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_previous_event(Event_t *event, PreviousEventFilter_t filter, Event_t *out_event);

/** ****************************************************************************

    @brief          Gets the max cargo supported by a given drone

    @param[in|out]  capacity Output param where we store max capacity when success

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_max_drone_cargo(int drone_id, int *capacity);

/** ****************************************************************************

    @brief          Inserts a new observation entry

    @param[in|out]  Action info to be inserted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_observation(Observation_t* observation);

/** ****************************************************************************

    @brief          Deletes all observations for a given event

    @param[in]      Input event ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_event_observations(int event_id);

/** ****************************************************************************

    @brief          Generates observations for any drone at the same orbit than current
                    drone (event drone)

    @param[in]      Input event ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_insert_local_observations(Event_t *event);

/** ****************************************************************************

    @brief          Gets whole observation info for a given event (observable, reportable, etc,..)

    @param[in]      event    Input event
    @param[in\out]  protocol Output struct where we store the protocol info required

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_event_observation_info(Event_t *event, ProtocolInfo_t *protocol);

/** ****************************************************************************

    @brief          Gets whole observation info for a given event (observable, reportable, etc,..)

    @param[in]      event    Input event
    @param[in\out]  protocol Output struct where we store the protocol info required

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_event_observation_info(Event_t *event, ProtocolInfo_t *protocol);

/** ****************************************************************************

    @brief          Gets object ID for current event drone

    @param[in]      event      Input event
    @param[out]     object_id  Output object ID obtained when success

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_event_object_id(Event_t *event, int *object_id);

/** ****************************************************************************

    @brief          Deletes a given action

    @param[in]      Input action ID

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_action(int action_id);

/** ****************************************************************************

    @brief          Purges old actions present at DB (older than configurable days value)
                    Those actions that have no event associated

    @param[in]      void

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_purge_old_actions();

/** ****************************************************************************

    @brief          Gets resource abundancies depending on current location

    @param[in|out]  abundancies  Input/output object where we store results

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_abundancies(Abundancies_t *abundancies);

/** ****************************************************************************

    @brief          Checks if a given agent name exists in DB

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Name to be used in update

    @return         Execution result (ENGINE_OK when found)

*******************************************************************************/
ErrorCode_t db_check_agent_name(DbConnection_t* connection, char* name);

/** ****************************************************************************

    @brief          Checks if a given company name exists in DB

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Name to be used in update

    @return         Execution result (ENGINE_OK when found)

*******************************************************************************/
ErrorCode_t db_check_company_name(DbConnection_t* connection, char* company_name);

/** ****************************************************************************

    @brief          Updates given agent name

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose output we want to update
    @param[in]      Name to be used in update

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_agent_name(DbConnection_t* connection, int agent_id, char* name);

/** ****************************************************************************

    @brief          Updates given agent name

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose output we want to update
    @param[in]      Company name to be used in update

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_agent_company(DbConnection_t* connection, int agent_id, char* company_name);

/** ****************************************************************************

    @brief          Gets query info using a given ID

    @param[in|out]  Output parameter where we store the query info obtained
                    This object contains (as input) current query ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_query_info(Queries_t* queryInfo);

/** ****************************************************************************

    @brief      Runs a query against DB using info provided by VM.
                Stores results into the VM using the address and size supplied

    @param[in]  queryInfo Whole query info obtained from VM stack
    @param[in]  vm        Current VM

    @return     Execution result

*******************************************************************************/
ErrorCode_t db_run_vm_query(Queries_t* queryInfo, VirtualMachine_t* vm);

/** ****************************************************************************

    @brief      Handy function to convert an integer into string and return string

    @param[in]  value  Integer value we want to convert
    @param[in]  buffer String buffer where we want to store the result
    @param[in]  szie   String buffer size

    @return     String result

*******************************************************************************/
char* db_int2str(int value, char* buffer, size_t size);

/** ****************************************************************************

    @brief          Gets object ID for a given drone ID

    @param[in]      drone_id   Input drone ID
    @param[out]     object_id  Output object ID obtained when success

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_drone_object_id(int drone_id, int *object_id);

#endif // __EM_DB_MODULE__