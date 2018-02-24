/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Outgoing emails module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

 #include <stdlib.h>

#include "email.h"
#include "trace.h"
#include "engine.h"

/******************************* DEFINES *************************************/


/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief          Sends an email to a given agent

  @param[in|out]  Agent email info

  @return         void

*******************************************************************************/
ErrorCode_t email_send(EmailInfo_t* email_info)
{
	ErrorCode_t result = ENGINE_OK;

	if(email_info) 
	{
		char email_command[MAX_COMMAND_CODE_SIZE];
		snprintf(email_command,
			MAX_COMMAND_CODE_SIZE, 
			"%s %s %s",
			email_info->email_script,
			email_info->email_addr,
			email_info->message);

        int error = system(email_command);

        if(error)
        {
        	engine_trace(TRACE_LEVEL_ALWAYS,
	        	"ERROR: Unable to send email to agent [%d] with command [%s] (error [%d])",
	        	email_info->agent_id,
	        	email_command,
	        	error);

        	result = ENGINE_EMAIL_ERROR;
        }
        else
        {
        	// Invoke the email script passing the parameters and get the result
			engine_trace(TRACE_LEVEL_ALWAYS,
	        	"email sent to agent [%d] command [%s]",
	        	email_info->agent_id,
	        	email_command);
        }
	}
	else
	{
		// NULL input
        engine_trace(TRACE_LEVEL_ALWAYS,
        	"ERROR: Unable to send email to agents, NULL info");

        result = ENGINE_INTERNAL_ERROR;
	}

	return result;
}