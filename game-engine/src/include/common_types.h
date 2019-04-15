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
#include "embed_util.h"  // embed util features

/******************************* DEFINES *************************************/

// size limits
#define MAX_COMMAND_CODE_SIZE  2048
#define MAX_AGENT_NAME_SIZE     256
#define MAX_EMAIL_ADDRESS_SIZE  512
#define MAX_EMAIL_DATE_SIZE     156

// orbits info
#define MAX_OBJECT_NAME_SIZE     45
#define MAX_OBJECT_TYPE_SIZE     16  
#define OBJECTS_TIMESTAMP_FORMAT "%Y-%m-%d %T"

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
typedef embed_t VirtualMachine_t;
typedef embed_t forth_t; // redefine old type to new one

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
	char drone_position[MAX_OBJECT_NAME_SIZE];
	double distance;  // from Earth

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
	int object_id;
	VirtualMachine_t* vm;
} AgentInfo_t;

//-----------------------------------------------------------------------------
//  OBJECT orbit info
//-----------------------------------------------------------------------------

// Enum to access the fields returned by the query
typedef enum
{
	OBJECT_ID_IDX,
	OBJECT_NAME_IDX,
	OBJECT_TYPE_IDX,
	GRAVITATIONAL_PARAMETER_IDX,
	CENTRAL_BODY_OBJECT_ID_IDX,
	EPOCH_IDX,
	SEMIMAJOR_AXIS_IDX,
	ECCENTRICITY_IDX,
	PERIAPSIS_ARGUMENT_IDX,
	MEAN_ANOMALY_IDX,
	INCLINATION_IDX,
	ASCENDING_NODE_LONGITUDE_IDX,
	MEAN_ANGULAR_MOTION_IDX,

	// Max fields expected
	MAX_OBJECT_FIELDS

} ObjectFieldId_t;


typedef struct
{
	int object_id;
	char object_name[MAX_OBJECT_NAME_SIZE];
	char object_type[MAX_OBJECT_TYPE_SIZE];
	double gravitational_parameter;
	int central_body_object_id;
	time_t epoch;
	double semimajor_axis;
	double eccentricity;
	double periapsis_argument;
	double mean_anomaly;
	double inclination;
	double ascending_node_longitude;
	double mean_angular_motion;

} ObjectOrbitInfo_t;


typedef struct
{
	double x;
	double y;
	double z;
	
} CartesianInfo_t;

#endif // __EM_COMMON_TYPES_MODULE__