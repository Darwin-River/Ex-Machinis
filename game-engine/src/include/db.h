/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Engine module
 
******************************************************************************/

#ifndef __EM_DB_MODULE__
#define __EM_DB_MODULE__

/******************************* INCLUDES ************************************/

#include "common_types.h"

/******************************* DEFINES *************************************/



/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/



/******************************* STATIC FUNCTIONS ****************************/


/******************************* PUBLIC FUNCTIONS ****************************/

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

    @brief          Gets earth orbit info

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  name Object name we are looking for
    @param[in|out]  Output parameter where we store the orbit info obtained from DB
                    This object contains also current object ID to do the search in DB

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_object_info_by_name(DbConnection_t* connection, char* name, ObjectOrbitInfo_t* object);

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

#endif // __EM_DB_MODULE__