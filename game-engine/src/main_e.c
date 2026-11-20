/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Main module
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include "engine_e.h"
#include "settle_clock.h"

/******************************* DEFINES *************************************/

/******************************* TYPES ***************************************/



/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/******************************* MAIN ****************************************/

/** ****************************************************************************

  @brief      Events engine entry point

  @param[in]  argc, argv 

  @return     Execution result (EXIT_SUCCESS/EXIT_FAILURE)

*******************************************************************************/
int main(int argc, char** argv) 
{
    // EVENTS_IN_ENGINE=1 (#694 stage 4): the game engine settles, so this
    // one neither settles nor takes the lock, and rests rather than exit
    if(settle_flag_on())
        return ee_rest(argc, argv);

    // initialize engine & run it
    if(ee_engine_init(argc, argv) == ENGINE_OK)
    {
        ee_run();
    }

    return (ee_engine_get_last_error());   
}

