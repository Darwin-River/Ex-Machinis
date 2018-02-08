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

/** ****************************************************************************

  @brief          Gets the next command

  @param[in|out]  Connection info, updated once disconnected

  @return         void

*******************************************************************************/
ErrorCode_t db_get_next_command(DbConnection_t* connection);

#endif // __EM_DB_MODULE__