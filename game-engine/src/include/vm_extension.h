/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Virtual Machine extensions management module
               This module deals with user defined callbacks that deal with 
               with words at C side.
 
******************************************************************************/

#ifndef __EM_VM_EXTENSION_MODULE__
#define __EM_VM_EXTENSION_MODULE__

/******************************* INCLUDES ************************************/

#include <stdbool.h>

#include "common_types.h"

/******************************* DEFINES *************************************/



/******************************* TYPES ***************************************/

typedef int32_t sdc_t;   /**< signed double cell type */

struct vm_extension_t;
typedef struct vm_extension_t VmExtension_t;

typedef int (*embed_callback_extended_t)(VmExtension_t *v);

typedef struct
{
  embed_callback_extended_t cb; /**< Callback for function */
  const char *name;             /**< Forth function */
  bool use;                     /**< Use this callback? */
} VmExtensionCb_t;

struct vm_extension_t
{
  embed_t *h;                  /**< embed VM instance we are operating with */
  VmExtensionCb_t *callbacks;  /**< callbacks to use with this instance */
  size_t callbacks_length;     /**< length of 'callbacks' field */
  embed_opt_t o;               /**< embed virtual machine options */
  cell_t error;                /**< current error condition */
};

/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/



/******************************* STATIC FUNCTIONS ****************************/


/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief      Allocate a new VM extension object with the pre-defined callbacks

  @param[in]  None

  @return     Object created or NULL when failed

*******************************************************************************/
VmExtension_t* vm_extension_new(void);

/** ****************************************************************************

  @brief      Pops a value from a given VM and returns it

  @param[in]  vmExt      Current VM extension
  @param[out] outValue   Out buffer where we store value obtained

  @return     Execution result

*******************************************************************************/
ErrorCode_t vm_extension_pop(VmExtension_t* vmExt, int* outValue);

/** ****************************************************************************

  @brief      Push a value into VM stack

  @param[in]  vmExt      Current VM extension
  @param[in]  outValue   Value to be pushed into stack

  @return     Execution result

*******************************************************************************/
ErrorCode_t vm_extension_push(VmExtension_t* vmExt, int inValue);


#endif // __EM_VM_EXTENSION_MODULE__