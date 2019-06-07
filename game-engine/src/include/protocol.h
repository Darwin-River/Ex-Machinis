/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Protocol executions module
 
******************************************************************************/

#ifndef __EM_PROTOCOL_MODULE__
#define __EM_PROTOCOL_MODULE__

/******************************* INCLUDES ************************************/

#include "vm_extension.h"
#include "common_types.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Execute protocol ID with given processMultiplier

  @param[in]  protocolId  Protocol ID
  @param[in]  multiplier  Process multiplier
  @param[in]  vmExt       VM extension handler to be used if we need to communicate with forth

  @return     Execution result

*******************************************************************************/
ErrorCode_t protocol_execute(int protocolId, int multiplier, VmExtension_t* vmExt);

/** ****************************************************************************

  @brief      Gets protocol execution message with result information

  @param[in]  void

  @return     Current result message

*******************************************************************************/
const char* protocol_get_result_msg();

#endif // __EM_PROTOCOL_MODULE__