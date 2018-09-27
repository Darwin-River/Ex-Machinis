/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Configuration module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <unistd.h>
#include <string.h>
#include <errno.h>

#include "config.h"

/******************************* DEFINES *************************************/

#define MAX_CONF_PARAM_LEN   64

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/

// Whole process configuration
char conf_param_names[MAX_CONFIG_PARAM+1][MAX_CONF_PARAM_LEN] = 
{
    // Application config
    "APP_TRACE_LEVEL",

    // DB configuration
    "DB_HOST",
    "DB_PORT",
    "DB_USER",
    "DB_PASSWORD",
    "DB_NAME",

    // LOGIC configuration
    "DB_READ_TIME",
    "MAX_CYCLE_SECONDS",
    "VM_RESUME_COMMAND",

    // EMAIL configuration
    "SEND_EMAIL_SCRIPT",
    "SEND_EMAIL_TEMPLATE",
    "AGENTS_EMAIL_DOMAIN",

    // FORTH configuration
    "FORTH_IMAGE_PATH",
};

/******************************* LOCAL FUNCTIONS *****************************/

/** ***************************************************************************

  @brief      Displays error when missing input config parameter

  @param[in]  param  Parameter name that is missing at config file

  @return     void

******************************************************************************/
void config_missing_param(char *param)
{
    if(param) {
        engine_trace(TRACE_LEVEL_ALWAYS, 
        	"ERROR: Missing parameter [%s] at configuration",
            param);
    }
}

/** ***************************************************************************

  @brief      Reads the expected configuration parameters and stores it at
              metrics parser

  @param[in]  Engine Current engine
  
  @return     Execution result

******************************************************************************/
ErrorCode_t config_read_parameters(config_t *conf_handle, char* params[])
{
    int result;
    int i;

    // Input pointers checked by calling function

    for(i=0; i < MAX_CONFIG_PARAM; i++) {
        // read all the strings
        result = config_lookup_string(conf_handle, 
        	(char*)conf_param_names[i],  
            (const char**)&params[i]);

        if(result != CONFIG_TRUE) {
            config_missing_param((char*)conf_param_names[i]);
            // Stop reading
            return ENGINE_CONFIG_ERROR;
        } else {
            engine_trace(TRACE_LEVEL_ALWAYS, 
            	"Config param read [%s]=[%s]", 
                conf_param_names[i], params[i]);
        }

    } // End for (;;)

    return ENGINE_OK;
}

/******************************* PUBLIC FUNCTIONS ****************************/

/** ***************************************************************************

  @brief      Reads engine configuration file and loads it into memory

  @param[in]      conf_handle  Configuration handler to be used
  @param[in]      conf_file    Configuration file path
  @param[in|out]  params       Output buffer where we store params read

  @return     Execution result code

******************************************************************************/
ErrorCode_t config_engine_read(config_t *conf_handle, char* conf_file, char* params[])
{
    if(conf_handle && conf_file && params) 
    {
        config_init(conf_handle);
        
        // validate file
        if(access(conf_file, F_OK|R_OK)) 
        {
            engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: Unable to read config file [%s] ([%d],[%s])", 
                conf_file, errno, strerror(errno));

            return ENGINE_CONFIG_ERROR;
        }

        // read whole file and display error information
        if(!config_read_file(conf_handle, conf_file))
        {
            engine_trace(TRACE_LEVEL_ALWAYS, 
            	"ERROR: Configuration error, file [%s] line [%d] text [%s]",
                config_error_file(conf_handle),
                config_error_line(conf_handle),
                config_error_text(conf_handle));

            return ENGINE_CONFIG_ERROR;
        } 
        else if(config_read_parameters(conf_handle, params) != ENGINE_OK)
        {
        	return ENGINE_CONFIG_ERROR;
        }
        else
        {
        	engine_trace(TRACE_LEVEL_ALWAYS, "Config file [%s] succesfully read", 
        		conf_file);
        }
    } 
    else 
    {
        engine_trace(TRACE_LEVEL_ALWAYS, "ERROR: NULL parameters at config load");
        return ENGINE_INTERNAL_ERROR;
    }

    return ENGINE_OK;
}

/******************************************************************************/

void config_engine_stop(config_t *conf_handle)
{
	if(conf_handle) 
    {
    	config_destroy(conf_handle);

    	engine_trace(TRACE_LEVEL_ALWAYS, "Configuration resources deallocated");
    }
}
