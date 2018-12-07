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

  @param[in]      object             Current object info
  @param[in]      eccentric_anomaly  A given value for eccentric_anomaly

  @return         Anomaly obtained or -1 when error

*******************************************************************************/
double orbits_get_true_anomaly(ObjectOrbitInfo_t* object, double eccentric_anomaly);

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

/** ****************************************************************************

  @brief          Gets the position of a given object

  @param[in]      object       Current object info
  @param[in|out]  cartesian    Output object where we store the cartesian info obtained

  @return         Result error code

*******************************************************************************/
ErrorCode_t orbits_get_object_position
(
  ObjectOrbitInfo_t* object,
  CartesianInfo_t* cartesian
);

/** ****************************************************************************

  @brief          Adds vectors given by their coordinates and obtains a new vector

  @param[in]      vector_1       First vector coordinates
  @param[in]      vector_2       Second vector coordinates
  @param[out]     vector_result  Result vector

  @return         void

*******************************************************************************/
void orbits_add_coordinates
(
  CartesianInfo_t* vector_1,
  CartesianInfo_t* vector_2,
  CartesianInfo_t* vector_result
);

/** ****************************************************************************

  @brief          Gets the distance between 2 objects (in light-seconds)

  @param[in]      object1    First object
  @param[in]      object2    Second object

  @return         Distance obtained

*******************************************************************************/
double orbits_get_distance_between_objects
(
  ObjectOrbitInfo_t* object1,
  ObjectOrbitInfo_t* object2
);

#endif // __EM_ORBITS_MODULE__