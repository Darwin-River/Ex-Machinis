/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Engine common types
 
******************************************************************************/

#ifndef __EM_COMMON_TYPES_MODULE__
#define __EM_COMMON_TYPES_MODULE__

/******************************* INCLUDES ************************************/

#include <mysql.h>
#include <linux/limits.h>

//#include "libforth.h"
#include "embed.h" // embedded FORTH version

/******************************* DEFINES *************************************/

// size limits
#define MAX_COMMAND_CODE_SIZE  2048
#define MAX_AGENT_NAME_SIZE     256
#define MAX_EMAIL_ADDRESS_SIZE  512
#define MAX_EMAIL_DATE_SIZE     156

/******************************* TYPES ***************************************/

//-----------------------------------------------------------------------------
// Basic boolean type
//-----------------------------------------------------------------------------
typedef enum
{
	ENGINE_FALSE = 0,
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
	ENGINE_DB_NOT_FOUND_ERROR,
	ENGINE_FORTH_EVAL_ERROR,
	ENGINE_FORTH_SERIALIZE_ERROR,
	ENGINE_EMAIL_ERROR,
	ENGINE_INTERNAL_ERROR,
	ENGINE_MEMORY_ALLOC_ERROR,
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
	char subject[MAX_COMMAND_CODE_SIZE+1];
	char code[MAX_COMMAND_CODE_SIZE+1]; 
	char* email_content; // dynamically allocated

} Command_t;


//-----------------------------------------------------------------------------
//  Virtual Machine structure (we wrap the forth_t here in an oppac object)
//-----------------------------------------------------------------------------
typedef forth_t VirtualMachine_t;

//-----------------------------------------------------------------------------
//  EMAIL info object
//-----------------------------------------------------------------------------
typedef struct
{
	int agent_id;
	int company_id;
	int user_id;
	char email_template[MAX_COMMAND_CODE_SIZE+1];
	char agent_name[MAX_EMAIL_ADDRESS_SIZE+1];
	char agent_email[MAX_EMAIL_ADDRESS_SIZE+1];
	char user_email_addr[MAX_EMAIL_ADDRESS_SIZE+1];
	char user_name[MAX_AGENT_NAME_SIZE+1];
	char email_script[PATH_MAX]; 
	char* input_content; // dynamically allocated
	char message[MAX_COMMAND_CODE_SIZE+1]; // Msg received from VM
	char subject[MAX_COMMAND_CODE_SIZE+1];
	char* output_content; // dynamically allocated

} EmailInfo_t;

//-----------------------------------------------------------------------------
//  AGENT info object
//-----------------------------------------------------------------------------
typedef struct
{
	int agent_id;
	int company_id;
	char agent_name[MAX_EMAIL_ADDRESS_SIZE+1];
	char* input_content; // dynamically allocated
	char subject[MAX_COMMAND_CODE_SIZE+1];
} AgentInfo_t;


#endif // __EM_COMMON_TYPES_MODULE__