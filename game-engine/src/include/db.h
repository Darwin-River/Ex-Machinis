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

    @brief          Deletes a given command once processed

    @param[in|out]  Connection info, updated once disconnected
    @param[in|out]  Command info, command to be deleted

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_delete_command(DbConnection_t* connection, Command_t* command);

/** ****************************************************************************

    @brief          Gets current agent VM

    @param[in|out]  Connection info, updated once disconnected
    @param[in]      Agent ID whose VM data we want to get 
                    (we use current if any or create a new one)
    @param[in|out]  Output address where engine is stored when success

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_get_agent_vm(DbConnection_t* connection, int agent_id, VirtualMachine_t** vm);

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
    @param[in]      Agent ID whose VM we want to update
    @param[in]      Output msg we need to update

    @return         Execution result

*******************************************************************************/
ErrorCode_t db_update_agent_output(DbConnection_t* connection, int agent_id, char* msg);

#endif // __EM_DB_MODULE__