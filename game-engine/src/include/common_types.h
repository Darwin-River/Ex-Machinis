/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Engine common types

******************************************************************************/

#ifndef __EM_COMMON_TYPES_MODULE__
#define __EM_COMMON_TYPES_MODULE__

/******************************* INCLUDES ************************************/

#include <time.h>
#include <mysql.h>
#include <linux/limits.h>

//#include "libforth.h"
#include "embed.h" // embedded FORTH version
#include "embed_util.h"  // embed util features

/******************************* DEFINES *************************************/


// size limits
#define MAX_COMMAND_CODE_SIZE 16000
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


#define CENTRAL_DATABASE_DRONE_ID   0

// queries table
#define MAX_QUERIES_NAME_SIZE          45
#define MAX_QUERIES_DESCRIPTION_SIZE  255
#define MAX_QUERIES_SCRIPT_SIZE      2000

// meta-commands supported
#define META_COMMAND_ABORT    "<abort>"
#define META_COMMAND_RESET    "<reset>"
#define META_COMMAND_RENAME   "<rename>"
#define META_COMMAND_REBRAND  "<rebrand>"
// Queued by the events engine for a drone it has just launched (not mailed)
#define META_COMMAND_LAUNCHED "<launched>"
// Word a launched drone runs once, if its copied program defines it
#define LAUNCHED_WORD         "launched"

// Fuel (#324 step 8): the fuel resource, and the protocol that burns it for
// power ([N 2 perform], run by the game engine itself)
#define FUEL_RESOURCE_ID      2061
#define LAUNCH_FUEL             20   // fuel a clone gets from its launcher (#324 step 13; game data LAUNCH_FUEL)
#define POWER_PROTOCOL_ID     2

// Special query tags
#define MAX_QUERY_TAG_LEN      32
#define MAX_QUERY_TAGS_NUM     20
// Query buffers
#define MAX_QUERY_VALUE_BUF_LEN  32
// values
#define QUERY_TAG_VALUE_1      "[value_1]"
#define QUERY_TAG_VALUE_2      "[value_2]"
#define QUERY_TAG_VALUE_3      "[value_3]"
#define QUERY_TAG_VALUE_4      "[value_4]"
#define QUERY_TAG_VALUE_5      "[value_5]"
#define QUERY_TAG_VALUE_6      "[value_6]"
#define QUERY_TAG_VALUE_7      "[value_7]"
#define QUERY_TAG_VALUE_8      "[value_8]"
#define QUERY_TAG_VALUE_9      "[value_9]"
// strings
#define QUERY_TAG_STRING_1     "[string_1]"
#define QUERY_TAG_STRING_2     "[string_2]"
#define QUERY_TAG_STRING_3     "[string_3]"
#define QUERY_TAG_STRING_4     "[string_4]"
// Others
#define QUERY_TAG_DRONE_ID     "[drone_id]"
#define QUERY_TAG_TIME_DELAY   "[time_delay]"
#define QUERY_TAG_GAME_NOW     "[game_now]"   // current game time, game seconds (#327)
// Double-cell inputs (#324 step 9): [dvalue_N] is a double whose high cell is
// parameter N (the top of the stack) and low cell parameter N+1
#define QUERY_TAG_DVALUE_1     "[dvalue_1]"
#define QUERY_TAG_DVALUE_2     "[dvalue_2]"
#define QUERY_TAG_DVALUE_3     "[dvalue_3]"
#define QUERY_TAG_DVALUE_4     "[dvalue_4]"
// !!! modify MAX_QUERY_TAGS_NUM if more added


#define JANUARY_1_2000_DATE    "2000-01-01 00:00:00"

#define NULL_VALUE     0xFFFF

// Special event types (sell and buy)
#define INCREMENT_INVENTORY_EVENT_TYPE 1
#define DECREMENT_INVENTORY_EVENT_TYPE 2
#define MOVE_EVENT_TYPE                3
#define SELL_EVENT_TYPE                4
#define BUY_EVENT_TYPE                 5
#define INCREMENT_CREDITS_EVENT_TYPE   6
#define DECREMENT_CREDITS_EVENT_TYPE   7
#define LAUNCH_EVENT_TYPE              8
#define STATION_OFFER_EVENT_TYPE       9   // an offer to sell to a House station (#324 step 10)
#define STATION_ORDER_EVENT_TYPE      10   // an order to buy from a House station
#define STATION_DELIVERY_PROTOCOL_ID  33   // actions that deliver or return station goods
#define PLUNDER_EVENT_TYPE            11   // a drone plunders a wreck (#324 step 12)
#define WAIT_EVENT_TYPE               12   // the end of a wait (#324 step 14)
#define WAIT_PROTOCOL_ID               3   // [M 3 perform]: wait M game minutes
#define MAX_WAIT_MINUTES           10080   // a week
#define PLUNDER_HAUL_PROTOCOL_ID      35   // actions that move plunder out of a wreck and into the plunderer

// Protocols that move goods between drones (decrement here, increment there)
#define PUSH_PROTOCOL_ID 10
#define PULL_PROTOCOL_ID 11
// Selling to and buying from the House (#380): N R 31 perform pushes N of R
// into the House station drone where the drone is, N R 32 perform pulls
// them from it. The engine names the station drone as their third
// parameter; the events engine treats them as a push and a pull
#define SELL_TO_STATION_PROTOCOL_ID   31
#define BUY_FROM_STATION_PROTOCOL_ID  32
#define STATION_DRONE_PARAM           2   // parameter 3 (-3 in the effects)

// [D K 12 perform]: builds a drone from ship kit K with a copy of drone D's
// program. The kit (a resource) and the new drone's hull share an ID.
#define LAUNCH_PROTOCOL_ID 12
#define LAUNCH_KIT_PARAM    0   // parameters[] index: K (top of the stack)
#define LAUNCH_SOURCE_PARAM 1   // parameters[] index: D

// users
#define MAX_USER_NAME_SIZE             255
#define MAX_USER_PASSWORD_SIZE         255
#define MAX_USER_EMAIL_SIZE            255
#define MAX_USER_REMEMBER_TOKEN_SIZE   100


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
	time_t checked_at;   // when the connection was last found alive (db_reconnect)

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
	int delay;        // real seconds until the reply reaches Earth (light speed)

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
	int optional_location;
	int bulk_multiplier;
	int parameters[MAX_PROTOCOL_PARAMETERS_NUM];
	unsigned char observable;
	unsigned char reportable;
	unsigned char multiplier; // new field to use when none parameters, can receive and optional multiplier
	int double_params;    // bit mask: parameters taken off the stack as doubles (bit 0 = parameter 1)
	int travel_from_time; // travel: effects at or after this time (game s)...
	int travel_shift;     // ...are delayed by this much, to last the trip
	int launch_delay;     // launch: the program's light time from drone D (game s)
	int wait_delay;       // wait: how long the drone waits (game s, #324 step 14)

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
	PROTOCOL_MULTIPLIER_IDX,
	PROTOCOL_DOUBLE_PARAMS_IDX,   // parameters taken as doubles (#324 step 9)

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
	unsigned char abundancies;
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
	RESOURCE_EFFECT_ABUNDANCIES_IDX,
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

// Enum to access the stack parameters received in buy/sell orders
typedef enum
{
	MARKET_RESOURCE_IDX,
	MARKET_QUANTITY_IDX,
	MARKET_PRICE_IDX,

	// Max fields expected
	MAX_MARKET_FIELDS

} MarketEffectParamId_t;

// Special

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
	OUTCOME_WRONG_ACCUMULATION_PRICE = -8,
	OUTCOME_ACTION_ABORTED = -9,
	OUTCOME_NO_MARKET_ORDER = -10,
	// -11 and -12 are perform's own codes (below)
	OUTCOME_SAME_PLAYER = -13,   // a trade between two companies of one player is blocked
	OUTCOME_NO_STATION = -14,    // no House station here trades that resource that way (#324 step 10)
	OUTCOME_NO_WRECK = -15,      // no unplundered wreck of that ID where the drone is (#324 step 12)

} Outcome_t;

// What perform leaves: 0 when the action succeeded, an Outcome_t value when
// it failed, or one of these when it was not started
#define PERFORM_OK                0
#define PERFORM_UNKNOWN_PROTOCOL  -11
#define PERFORM_REFUSED           -12

typedef struct
{
	int event_id;
	int event_type;
	int action_id;
	unsigned char logged;
	signed char outcome;
	int drone_id;
	int resource_id;
	unsigned char installed;
	unsigned char locked;
	int new_quantity;
	int new_credits;
	int new_location;
	int new_transmission;
	int new_cargo;
	time_t timestamp;   // real time (for the website)
	long long game_t;   // game time (game seconds): what the engines compare
  int delay; // The delay imposed on the event by the resource effects table.
  unsigned char burn; // the action's own fuel cost (#380): ordered first at its time

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
	EVENT_GAME_T_IDX,       // added by migration 0002

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
	time_t timestamp;   // real time when the event becomes visible (website)
	long long game_t;   // game time when it becomes visible to the drone

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


//-----------------------------------------------------------------------------
//  ABUNDANCIES table
//-----------------------------------------------------------------------------

typedef struct
{
	int id;
	int location_id;
	int resource_id;
	int multiplier;

} Abundancies_t;

// Enum with table fields idx
typedef enum
{
	ABUNDANCIES_ID_IDX,
	ABUNDANCIES_LOCATION_ID_IDX,
	ABUNDANCIES_RESOURCE_ID_IDX,
	ABUNDANCIES_MULTIPLIER_IDX,

	// Max fields expected
	MAX_ABUNDANCIES__FIELDS

} AbundanciesFieldId_t;


//-----------------------------------------------------------------------------
//  QUERIES table
//-----------------------------------------------------------------------------

typedef struct
{
	int id;
	char name[MAX_QUERIES_NAME_SIZE];
	char description[MAX_QUERIES_DESCRIPTION_SIZE];
	int parametersNum;
	char script[MAX_QUERIES_SCRIPT_SIZE];
	// dynamically allocated
	int *parameterValues;
	int resultsArrayAddr;
	int resultsArraySize;
	int rowsStored; // rows written into the results array (query leaves it)
	char *finalQuery;

} Queries_t;

// Enum with table fields idx
typedef enum
{
	QUERIES_ID_IDX,
	QUERIES_NAME_IDX,
	QUERIES_DESCRIPTION_IDX,
	QUERIES_PARAMETERS_IDX,
	QUERIES_SCRIPT_IDX,

	// Max fields expected
	MAX_QUERIES_FIELDS

} QueriesFieldId_t;


typedef struct
{
	int id;
	char name[MAX_QUERIES_NAME_SIZE];
	int mass;
	int locked;
	int quantity;
	int event_id;

} DroneResources_t;

// Enum with table fields idx
typedef enum
{
	DRONE_RESOURCE_ID_IDX,
	DRONE_RESOURCE_NAME_IDX,
	DRONE_RESOURCE_LOCKED_IDX,
	DRONE_RESOURCE_MASS_IDX,
	DRONE_RESOURCE_QUANTITY_IDX,
	DRONE_RESOURCE_EVENT_ID_IDX,

	// Max fields expected
	MAX_DRONE_RESOURCE_FIELDS

} DroneResourceFieldId_t;


//-----------------------------------------------------------------------------
//  USERS table
//-----------------------------------------------------------------------------

typedef struct
{
	int user_id;
	char name[MAX_USER_NAME_SIZE];
	char email[MAX_USER_EMAIL_SIZE];
	char password[MAX_USER_PASSWORD_SIZE];
	char remember_token[MAX_USER_REMEMBER_TOKEN_SIZE];
	time_t created_at;
	time_t updated_at;
	int credits;
	// Since #324 step 4 the engines load this from companies, not users:
	// user_id is then the company ID (companies.company_id) and credits its
	// bank account; player_id is the player who owns the company
	int player_id;
	// Internal stuff - not present at DB
	int current_drone_id; // link user with current drone being managed
	int action_id; // link with action

} User_t;


// Enum with table fields idx
typedef enum
{
	USER_USER_ID_IDX,
	USER_NAME_IDX,
	USER_EMAIL_IDX,
	USER_PASSWORD_IDX,
	USER_REMEMBER_TOKEN_IDX,
	USER_CREATED_AT_IDX,
	USER_UPDATED_AT_IDX,
	USER_CREDITS_IDX,

	// Max fields expected
	MAX_USER_FIELDS

} UserFieldId_t;

#endif // __EM_COMMON_TYPES_MODULE__
