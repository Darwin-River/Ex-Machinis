/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Engine common types
 
******************************************************************************/

#ifndef __EM_COMMON_TYPES_MODULE__
#define __EM_COMMON_TYPES_MODULE__

/******************************* INCLUDES ************************************/


/******************************* DEFINES *************************************/

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
	ENGINE_INTERNAL_ERROR,
} ErrorCode_t;


#endif // __EM_COMMON_TYPES_MODULE__