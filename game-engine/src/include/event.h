/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Events management module
 
******************************************************************************/

#ifndef __EM_EVENT_MODULE__
#define __EM_EVENT_MODULE__

/******************************* INCLUDES ************************************/

#include "common_types.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Processes outcome event info

  @param[in]  event  Input event

  @return     Execution result

*******************************************************************************/
ErrorCode_t event_process_outcome(Event_t *event);

/* A batch of events (#711): event_batch_start before it; event_batch_retry
   after it says a passing database error stopped it, so the engine rolls it
   back and runs it again rather than failing the actions it touched */
void event_batch_start(void);
Bool_t event_batch_retry(void);

/** ****************************************************************************

  @brief      Trace a given event info

  @param[in]  event  Input event

  @return     void

*******************************************************************************/
void event_trace(Event_t* event);

#endif // __EM_EVENT_MODULE__