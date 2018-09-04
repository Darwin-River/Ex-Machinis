/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Outgoing emails module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <sys/time.h>

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
		size_t buffer_size = (strlen(email_info->input_content) + strlen(email_info->message) + strlen(email_info->email_template))*10;
		email_info->output_content = (char*) engine_malloc(buffer_size);

		if(!email_info->output_content)
		{
			return ENGINE_MEMORY_ALLOC_ERROR;
		}

        size_t command_buffer_size = (buffer_size * 2);
		char* email_command = (char*) engine_malloc(command_buffer_size);

		if(!email_command)
		{
			return ENGINE_MEMORY_ALLOC_ERROR;
		}

		engine_trace(TRACE_LEVEL_ALWAYS,
	        	"Allocated buffers with [%ld] and [%ld] bytes",
	        	buffer_size,
	        	buffer_size*2);

        // calculate current date buffer
		time_t now;
   		struct tm *info;
   		char date_buffer[MAX_EMAIL_DATE_SIZE];
   		time(&now);
   		info = localtime(&now);
   		strftime(date_buffer, MAX_EMAIL_DATE_SIZE, "%c", info);

		// Build first the rsp content
		snprintf(email_info->output_content,
			buffer_size, 
			email_info->email_template, // "<pre>---- Output ----\n\n%s\n\n<b>From:</b> %s\n<b>Sent:</b> %s\n<b>To:</b> %s\n<b>Subject:</b> %s\n\n%s\n\n</pre>"
			email_info->message,
			email_info->user_email_addr,
			date_buffer,
			email_info->agent_email,
			email_info->subject,
			email_info->input_content);

        // Now build the command to be executed to send the email
		snprintf(email_command,
			command_buffer_size, 
			"%s %s \'%s\' %s \'%s\'",
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

        if(email_command) 
        {
        	engine_free(email_command, command_buffer_size);
        	email_command = NULL;
        }

        if(email_info->output_content) 
        {
        	engine_free(email_info->output_content, buffer_size);
        	email_info->output_content = NULL;
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
