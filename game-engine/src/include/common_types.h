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

// protocols
#define MAX_PROTOCOL_NAME_SIZE   45
#define MAX_PROTOCOL_DESCRIPTION_SIZE  255
#define MAX_PROTOCOL_PARAMETERS_NUM  64

// resources
#define MAX_RESOURCE_NAME_SIZE          45
#define MAX_RESOURCE_DESCRIPTION_SIZE   1024

// event_types
#define MAX_EVENT_TYPE_NAME_SIZE  45

#define LIGHT_SPEED_KM_PER_SECOND   299792.458  // Km/second
#define LIGHT_SPEED_KM_PER_MINUTE   (LIGHT_SPEED_KM_PER_SECOND * 60.0) // Km/minute 


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
	ENGINE_LOGIC_ERROR,
	ENGINE_NOT_FOUND,
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
	int user_id;
	char email_template[MAX_COMMAND_CODE_SIZE+1];
	char agent_name[MAX_AGENT_NAME_SIZE+1];
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
	int user_id;
	char agent_name[MAX_AGENT_NAME_SIZE+1];
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
	COMPUTED_ON_IDX,
	X_COORD_IDX,
	Y_COORD_IDX,
	Z_COORD_IDX,

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


//-----------------------------------------------------------------------------
//  PROTOCOLS execution info
//-----------------------------------------------------------------------------

typedef struct
{
	int protocol_id;
	int parameters_num;
	char protocol_name[MAX_PROTOCOL_NAME_SIZE];
	char protocol_description[MAX_PROTOCOL_DESCRIPTION_SIZE];
	int process_multiplier;
	int bulk_multiplier;
	int parameters[MAX_PROTOCOL_PARAMETERS_NUM];
	unsigned char observable;
	unsigned char reportable;
	unsigned char internal;
	
} ProtocolInfo_t;

// Enum to access the fields returned by the query against protocols table
typedef enum
{
	PROTOCOL_ID_IDX,
	PROTOCOL_NAME_IDX,
	PROTOCOL_PARAMETERS_IDX,
	PROTOCOL_BULK_MODIFIER_IDX,
	PROTOCOL_DESCRIPTION_IDX,
	PROTOCOL_OBSERVABLE_IDX,
	PROTOCOL_REPORTABLE_IDX,
	PROTOCOL_INTERNAL_IDX,

	// Max fields expected
	MAX_PROTOCOL_FIELDS

} ProtocolFieldId_t;


//-----------------------------------------------------------------------------
//  ACTIONS table
//-----------------------------------------------------------------------------

typedef struct
{
	int action_id;
	int drone_id;
	int protocol_id;
	int process_multiplier;
	unsigned char aborted;
	
} Action_t;

// Enum to access the fields returned by the query against protocols table
typedef enum
{
	ACTION_ID_IDX,
	ACTION_DRONE_ID_IDX,
	ACTION_PROTOCOL_ID_IDX,
	ACTION_PROCESS_MULTIPLIER_IDX,
	ACTION_ABORTED_ID_IDX,
	ACTION_TIMESTAMP_IDX,

	// Max fields expected
	MAX_ACTION_FIELDS

} ActionFieldId_t;

//-----------------------------------------------------------------------------
//  RESOURCES table
//-----------------------------------------------------------------------------

typedef struct
{
	int resource_id;
	char resource_name[MAX_RESOURCE_NAME_SIZE];
	char resource_description[MAX_RESOURCE_DESCRIPTION_SIZE];
	int resource_mass;
	int resource_capacity;
	int resource_slot_size;
	
} Resource_t;

// Enum to access the fields returned by the query against protocols table
typedef enum
{
	RESOURCE_ID_IDX,
	RESOURCE_NAME_IDX,
	RESOURCE_DESCRIPTION_IDX,
	RESOURCE_MASS_IDX,
	RESOURCE_CAPACITY_IDX,
	RESOURCE_SLOT_SIZE_IDX,

	// Max fields expected
	MAX_RESOURCE_FIELDS

} ResourceFieldId_t;

//-----------------------------------------------------------------------------
//  EVENT_TYPE table
//-----------------------------------------------------------------------------

typedef struct
{
	int event_type_id;
	char event_type_name[MAX_EVENT_TYPE_NAME_SIZE];

} EventType_t;

// Enum to access the fields returned by the query against protocols table
typedef enum
{
	EVENT_TYPE_ID_IDX,
	EVENT_TYPE_NAME_IDX,
	
	// Max fields expected
	MAX_EVENT_TYPE_FIELDS

} EventTypeFieldId_t;


//-----------------------------------------------------------------------------
//  RESOURCES_EFFECTS table
//-----------------------------------------------------------------------------

#define RESOURCE_EFFECT_NULL_DRONE_ID  0

typedef struct
{
	int resource_effect_id;
	int drone_id;
	int resource_id;
	int protocol_id;
	int event_type;
	unsigned char local;
	unsigned char installed;
	unsigned char locked;
	unsigned char deplete;
	int quantity;
	int time;
	
} ResourceEffect_t;

// Enum with table fields idx
typedef enum
{
	RESOURCE_EFFECT_ID_IDX,
	RESOURCE_EFFECT_DRONE_ID_IDX,
	RESOURCE_EFFECT_RESOURCE_ID_IDX,
	RESOURCE_EFFECT_PROTOCOL_ID_IDX,
	RESOURCE_EFFECT_EVENT_TYPE_IDX,
	RESOURCE_EFFECT_LOCAL_IDX,
	RESOURCE_EFFECT_INSTALLED_IDX,
	RESOURCE_EFFECT_LOCKED_IDX,
	RESOURCE_EFFECT_DEPLETE_IDX,
	RESOURCE_EFFECT_QUANTITY_IDX,
	RESOURCE_EFFECT_TIME_IDX,

	// Max fields expected
	MAX_RESOURCE_EFFECT_FIELDS

} ResourceEffectFieldId_t;


//-----------------------------------------------------------------------------
//  MARKET_EFFECTS table
//-----------------------------------------------------------------------------

typedef struct
{
	int market_effect_id;
	int protocol_id;
	int event_type;
	int resource_id;
	unsigned char upper_limit;
	int quantity;
	int price;
	int time;
	
} MarketEffect_t;

// Enum with table fields idx
typedef enum
{
	MARKET_EFFECT_ID_IDX,
	MARKET_EFFECT_PROTOCOL_ID_IDX,
	MARKET_EFFECT_EVENT_TYPE_IDX,
	MARKET_EFFECT_RESOURCE_ID_IDX,
	MARKET_EFFECT_UPPER_LIMIT_IDX,
	MARKET_EFFECT_QUANTITY_IDX,
	MARKET_EFFECT_PRICE_IDX,
	MARKET_EFFECT_TIME_IDX,

	// Max fields expected
	MAX_MARKET_EFFECT_FIELDS

} MarketEffectFieldId_t;

//-----------------------------------------------------------------------------
//  LOCATION_EFFECTS table
//-----------------------------------------------------------------------------

typedef struct
{
	int location_effect_id;
	int protocol_id;
	int event_type;
	int location;
	int time;
	
} LocationEffect_t;

// Enum with table fields idx
typedef enum
{
	LOCATION_EFFECT_ID_IDX,
	LOCATION_EFFECT_PROTOCOL_ID_IDX,
	LOCATION_EFFECT_EVENT_TYPE_IDX,
	LOCATION_EFFECT_LOCATION_IDX,
	LOCATION_EFFECT_TIME_IDX,

	// Max fields expected
	MAX_LOCATION_EFFECT_FIELDS

} LocationEffectFieldId_t;

//-----------------------------------------------------------------------------
//  EVENTS table
//-----------------------------------------------------------------------------

// Outcome values
typedef enum
{
	OUTCOME_OK = 1,
	OUTCOME_NO_OUTCOME = 0,
	OUTCOME_NO_LOCAL = -1,
	OUTCOME_NO_RESOURCES = -2,
	OUTCOME_NO_CARGO_SPACE = -3,
	OUTCOME_NO_CREDITS = -4,
	OUTCOME_RESOURCES_LOWER_LIMIT = -5,
	OUTCOME_RESOURCES_UPPER_LIMIT = -6,
	OUTCOME_WRONG_DEPLETION_PRICE = -7,
	OUTCOME_WRONG_ACCUMULATION_PRICE = -8

} Outcome_t;

typedef struct
{
	int event_id;
	int event_type;
	int action_id;
	unsigned char logged;
	unsigned char outcome;
	int drone_id;
	int resource_id;
	unsigned char installed;
	unsigned char locked;
	int new_quantity;
	int new_credits;
	int new_location;
	int new_transmission;
	int new_cargo;
	time_t timestamp;
	
} Event_t;

// Enum with table fields idx
typedef enum
{
	EVENT_ID_IDX,
	EVENT_TYPE_IDX,
	EVENT_ACTION_IDX,
	EVENT_LOGGED_IDX,
	EVENT_OUTCOME_IDX,
	EVENT_DRONE_ID_IDX,
	EVENT_RESOURCE_ID_IDX,
	EVENT_INSTALLED_IDX,
	EVENT_LOCKED_IDX,
	EVENT_NEW_QUANTITY_IDX,
	EVENT_NEW_CREDITS_IDX,
	EVENT_NEW_LOCATION_IDX,
	EVENT_NEW_TRANSMISSION_IDX,
	EVENT_NEW_CARGO_IDX,
	EVENT_TIMESTAMP_IDX,

	// Max fields expected
	MAX_EVENT_FIELDS

} EventFieldId_t;

//-----------------------------------------------------------------------------
//  OBSERVATIONS table
//-----------------------------------------------------------------------------

typedef struct
{
	int id;
	int drone_id;
	int event_id;
	time_t timestamp;
	
} Observation_t;

// Enum with table fields idx
typedef enum
{
	OBSERVATION_ID_IDX,
	OBSERVATION_DRONE_ID_IDX,
	OBSERVATION_EVENT_ID_IDX,
	OBSERVATION_TIMESTAMP_IDX,

	// Max fields expected
	MAX_OBSERVATION_FIELDS

} ObservationFieldId_t;

#endif // __EM_COMMON_TYPES_MODULE__

