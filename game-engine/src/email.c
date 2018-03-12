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
		char email_command[MAX_COMMAND_CODE_SIZE*4];

		// Build first the rsp content
		snprintf(email_info->output_content,
			(MAX_COMMAND_CODE_SIZE*3), 
			"<div style=\"color:#FF0000\">-------- Output ----------<br><br><pre>%s</pre></div><br><br>"
			"-------- Input -----------<br><br><div style=\"color:#000000\"><pre>%s</pre></div><br><br>",
			email_info->message,
			email_info->input_content);

        // Now build the command to be executed to send the email
		snprintf(email_command,
			MAX_COMMAND_CODE_SIZE, 
			"%s %s \"%s\" %s \"%s\"",
			email_info->email_script,
			email_info->user_email_addr,
			email_info->subject,
			email_info->agent_name,
			email_info->output_content);

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