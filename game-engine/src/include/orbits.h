/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Outgoing emails module
 
******************************************************************************/

#ifndef __EM_ORBITS_MODULE__
#define __EM_ORBITS_MODULE__

/******************************* INCLUDES ************************************/

#include "common_types.h"

/******************************* DEFINES *************************************/



/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/



/******************************* GLOBAL VARIABLES ****************************/



/******************************* STATIC FUNCTIONS ****************************/


/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief          Gets the mean anomaly for a given object

  @param[in]      Current object info

  @return         Anomaly obtained or -1 when fatal error

*******************************************************************************/
double orbits_get_mean_anomaly(ObjectOrbitInfo_t* object);

/** ****************************************************************************

  @brief          Gets the eccentric anomaly for a given object

  @param[in]      object        Current object info
  @param[in]      mean_anomaly  Current object info

  @return         Anomaly obtained or -1 when error

*******************************************************************************/
double orbits_get_eccentric_anomaly(ObjectOrbitInfo_t* object, double mean_anomaly);

/** ****************************************************************************

  @brief          Gets the true anomaly for a given object

  @param[in]      eccentric_anomaly  Current object info

  @return         Anomaly obtained or -1 when error

*******************************************************************************/
double orbits_get_true_anomaly(double eccentric_anomaly);

/** ****************************************************************************

  @brief          Gets the true anomaly radius for a given object

  @param[in]      object        Current object info
  @param[in]      true_anomaly  True anomaly value

  @return         Radius obtained or -1 when error

*******************************************************************************/
double orbits_get_true_anomaly_radius(ObjectOrbitInfo_t* object, double true_anomaly);

/** ****************************************************************************

  @brief          Gets the cartesian coordinates for a given object

  @param[in]      object          Current object info
  @param[in]      true_anomaly    True anomaly value
  @param[in]      anomaly_radius  Radius of the true anomaly
  @param[in|out]  cartesian Output object where we store the cartesian info obtained

  @return         void

*******************************************************************************/
void orbits_get_cartesian_coordinates
(
  ObjectOrbitInfo_t* object, 
  double true_anomaly,
  double true_anomaly_radius,
  CartesianInfo_t* cartesian
);

#endif // __EM_ORBITS_MODULE__