/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Outgoing emails module
 
******************************************************************************/

#ifndef __EM_EMAIL_MODULE__
#define __EM_EMAIL_MODULE__

/******************************* INCLUDES ************************************/

#include "common_types.h"

/******************************* DEFINES *************************************/



/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/



/******************************* STATIC FUNCTIONS ****************************/


/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief          Sends an email to a given agent

  @param[in|out]  Agent email info

  @return         void

*******************************************************************************/
ErrorCode_t email_send(EmailInfo_t* email_info);

#endif // __EM_EMAIL_MODULE__