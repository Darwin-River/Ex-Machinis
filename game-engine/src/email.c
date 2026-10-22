/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Outgoing emails module

******************************************************************************/

/******************************* INCLUDES ************************************/

#include <stdlib.h>
#include <unistd.h>
#include <sys/types.h>
#include <sys/wait.h>
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

   		// The reply goes to the script as a separate argument (no shell),
   		// so the player's text needs no escaping.
   		const char* scaped_buffer = email_info->input_content;

		// Build first the rsp content
		snprintf(email_info->output_content,
			buffer_size,
			email_info->email_template,
			//"\n%s\n\nContact help@exmachinis.com if you need assistance.\n\nFrom: %s\nSent: %s\nTo: %s\nSubject:
			// email_info->drone_position,
			// (email_info->distance / LIGHT_SPEED_KM_PER_MINUTE), // Distance is KM -> Convert to light-minutes
			email_info->message,
			email_info->user_email_addr,
			date_buffer,
			email_info->agent_email,
			email_info->subject,
			scaped_buffer ) ; //email_info->input_content);


        // Subject is the player's: one line only, so it can't add headers
        char subject[MAX_COMMAND_CODE_SIZE+1];
        snprintf(subject, sizeof(subject), "%s", email_info->subject);
        for(char* c = subject; *c; c++) {
            if(*c == '\r' || *c == '\n') *c = ' ';
        }

        // For the log only
		snprintf(email_command,
			command_buffer_size,
			"%s %s '%s' %s '%s'",
			email_info->email_script,
			email_info->user_email_addr,
			subject,
			email_info->agent_name,
			email_info->output_content);

        // Run the script with each value as its own argument. Never through
        // a shell: the subject and the drone's output come from players.
        int error = -1;
        pid_t pid = fork();
        if(pid == 0) {
            execl(email_info->email_script, email_info->email_script,
                email_info->user_email_addr, subject, email_info->agent_name,
                email_info->output_content, (char*)NULL);
            _exit(127);
        } else if(pid > 0) {
            int status = 0;
            if(waitpid(pid, &status, 0) == pid)
                error = WIFEXITED(status) ? WEXITSTATUS(status) : -1;
        }

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
