/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Engine common types
 
******************************************************************************/

#ifndef __EM_COMMON_TYPES_MODULE__
#define __EM_COMMON_TYPES_MODULE__

/******************************* INCLUDES ************************************/

#include <mysql.h>

/******************************* DEFINES *************************************/

#define MAX_COMMAND_CODE_SIZE  2048

/******************************* TYPES ***************************************/

//-----------------------------------------------------------------------------
// Basic boolean type
//-----------------------------------------------------------------------------
typedef enum
{
	ENGINE_FALSE,
    ENGINE_TRUE
} Bool_t;

//-----------------------------------------------------------------------------
// Error codes management
//-----------------------------------------------------------------------------
typedef enum
{
	ENGINE_OK,
	ENGINE_PLAT_HOME_ERROR,
	ENGINE_LOG_CONF_ERROR,
	ENGINE_LOG_FILE_ERROR,
	ENGINE_CONFIG_ERROR,
	ENGINE_DB_INIT_ERROR,
	ENGINE_DB_CONNECT_ERROR,
	ENGINE_DB_QUERY_ERROR,
	ENGINE_INTERNAL_ERROR,
} ErrorCode_t;


//-----------------------------------------------------------------------------
//  DB connection
//-----------------------------------------------------------------------------
typedef struct
{
	MYSQL* hndl;
	char* host;
	int port;
	char* user;
	char* password;
	char* db_name;
	
} DbConnection_t;


//-----------------------------------------------------------------------------
//  FORTH Commands read from DB
//-----------------------------------------------------------------------------
typedef struct
{
	int command_id;
	int agent_id;
	char code[MAX_COMMAND_CODE_SIZE+1]; 

} Command_t;


#endif // __EM_COMMON_TYPES_MODULE__