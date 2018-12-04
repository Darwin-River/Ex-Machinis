/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Orbits parameters maths
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <stdlib.h>
#include <string.h>
#include <time.h>

#include "orbits.h"

/******************************* DEFINES *************************************/


/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief          Gets the mean anomaly for a given object

  @param[in]      Current object info

  @return         Anomaly obtained or -1 when fatal error

*******************************************************************************/
double orbits_get_mean_anomaly(ObjectOrbitInfo_t* object)
{
	double anomaly = -1.0;

	if(object) {

	    // Get current timestamp (seconds)
		time_t now = time(NULL);

		time_t diff_in_seconds = now - object->epoch; // seconds between epoch and now
		double diff_in_days = ((double)diff_in_seconds/86400.0); // days between epoch and now (double value)

		//Formula:    M(t) = M0+n(t-t0)

		//M(t)   = current mean anomaly
		//M0     = anomaly at epoch (taken from DB field: mean_anomaly)
		//n      = mean angular motion
		//(t-t0) = he difference in times expressed in days

		anomaly = object->mean_anomaly + object->mean_angular_motion * diff_in_days;
	}

	return anomaly;
}

/** ****************************************************************************

  @brief          Gets the eccentric anomaly for a given object

  @param[in]      Current object info

  @return         Anomaly obtained or -1 when error

*******************************************************************************/
double orbits_get_eccentric_anomaly(ObjectOrbitInfo_t* object)
{
	double anomaly = -1.0;

	return anomaly;
}

/** ****************************************************************************

  @brief          Gets the true anomaly for a given object

  @param[in]      Current object info

  @return         Anomaly obtained or -1 when error

*******************************************************************************/
double orbits_get_true_anomaly(ObjectOrbitInfo_t* object)
{
	double anomaly = -1.0;

	return anomaly;
}

/** ****************************************************************************

  @brief          Gets the cartesian coordinates for a given object

  @param[in]      Current object info
  @param[in|out]  Output object where we store the cartesian info obtained

  @return         void

*******************************************************************************/
void orbits_get_cartesian_coordinates(ObjectOrbitInfo_t* object, CartesianInfo_t* cartesian)
{

}


