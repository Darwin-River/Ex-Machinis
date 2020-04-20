/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Events Engine configuration module
 
******************************************************************************/

#ifndef __EM_CONFIG_E_MODULE__
#define __EM_CONFIG_E_MODULE__

/******************************* INCLUDES ************************************/

#include "engine_e.h"

/******************************* DEFINES *************************************/



/******************************* TYPES ***************************************/


/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/



/******************************* STATIC FUNCTIONS ****************************/


/******************************* EXPORTED FUNCTIONS **************************/

/** ***************************************************************************

  @brief      Reads engine configuration file and loads it into memory

  @param[in]      conf_handle  Configuration handler to be used
  @param[in]      conf_file    Configuration file path
  @param[in|out]  params       Output buffer where we store params read

  @return     Execution result code

******************************************************************************/
ErrorCode_t config_engine_read(config_t *conf_handle, char* conf_file, char* params[]);

/** ***************************************************************************

  @brief      Releases configuration resources

  @param[in]  conf_handle  Configuration handler

  @return     void

******************************************************************************/
void config_engine_stop(config_t *conf_handle);

#endif // __EM_CONFIG_E_MODULE__