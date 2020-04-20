/******************************************************************************

  PROJECT    : Ex-Machinis

  DESCRIPTION: Orbits parameters maths
 
******************************************************************************/

/******************************* INCLUDES ************************************/

#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <math.h>

#include "orbits.h"
#include "engine.h"

/******************************* DEFINES *************************************/

#define DEG_TO_RADIANS(d)  ((d * M_PI)/180.0)


/******************************* TYPES ***************************************/

/******************************* PROTOTYPES **********************************/

/******************************* GLOBAL VARIABLES ****************************/

/******************************* LOCAL FUNCTIONS *****************************/

/******************************* PUBLIC FUNCTIONS ****************************/

/** ****************************************************************************

  @brief          Gets the mean anomaly for a given object

  @param[in]      object Current object info

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
		//(t-t0) = the time difference expressed in days

		anomaly = object->mean_anomaly + object->mean_angular_motion * diff_in_days;

		engine_trace(TRACE_LEVEL_ALWAYS, 
                     "OBJECT=[%s] epoch [%ld] now [%ld] diff_seconds [%ld] days [%f] "
                     "mean_angular_motion (deg/day) [%f] mean_anomaly_T0 [%f] mean_anomaly (deg) [%f]",
                     object->object_name, object->epoch, now, diff_in_seconds, diff_in_days, 
                     object->mean_angular_motion, object->mean_anomaly, anomaly);
	}

	return anomaly;
}

/** ****************************************************************************

  @brief          Gets the eccentric anomaly for a given object

  @param[in]      object        Current object info
  @param[in]      mean_anomaly  Current object info

  @return         Anomaly obtained or -1 when error

*******************************************************************************/
double orbits_get_eccentric_anomaly(ObjectOrbitInfo_t* object, double mean_anomaly)
{
	double anomaly = -1.0;

	if(object) {

		// We need to iterate till the Ei value is sufficiently small using this formula
		// (e) here is the object eccentricity

		//                  
		//                  Ei -  (M + e * sin(Ei))
		//  E    =  E   - (------------------------)
		//   i+1     i         1 -  e * cos(Ei)
		//

		// We start with E0 = M and iterate, with M = mean_anomaly previously calculated

		double Ei  = mean_anomaly;
		double Ei1 = mean_anomaly;
		double diff = 1;

		// we iterate till the abs difference of Ei and Ei1 is lower than a pre-defined value (we take for the moment 0.01)
		// first round we start with Ei = mean_anomaly and then calculate the next
		do {
			Ei = Ei1; // last value = current value
			double numerator = Ei - (mean_anomaly + object->eccentricity * sin(DEG_TO_RADIANS(Ei)));
			double denominator = 1 - (object->eccentricity * cos(DEG_TO_RADIANS(Ei)));

			Ei1 = Ei - (numerator/denominator);

			diff = fabs(Ei - Ei1);

			engine_trace(TRACE_LEVEL_ALWAYS, 
				"Ei [%f] Ei+1 [%f] numerator [%f] denominator [%f] abs(Ei - Ei1) [%f]",
                Ei, Ei1, numerator, denominator, diff);

		} while (diff >= 0.01);

		// When the condition is met, anomaly = Ei1
		anomaly = Ei1;

		engine_trace(TRACE_LEVEL_ALWAYS, 
                     "OBJECT=[%s] mean_anomaly [%f] eccentricity [%f] eccentric_anomaly [%f]",
                     object->object_name, mean_anomaly, object->eccentricity, 
                     anomaly);
	}

	return anomaly;
}

/** ****************************************************************************

  @brief          Gets the true anomaly for a given object

  @param[in]      object             Current object info
  @param[in]      eccentric_anomaly  A given value for eccentric_anomaly

  @return         Anomaly obtained or -1 when error

*******************************************************************************/
double orbits_get_true_anomaly(ObjectOrbitInfo_t* object, double eccentric_anomaly)
{
	double anomaly = -1.0;

	// The true anomaly is obtained as follows
	// v = atan2(sqrt(1-E) * cos(E/2), sqrt(1+E) * sin(E/2));

	// we simplify this function doing: atan2(x, y), first we calculate X and then Y

	double X = sqrt(1 - object->eccentricity) * cos(DEG_TO_RADIANS(eccentric_anomaly/2));
	double Y = sqrt(1 + object->eccentricity) * sin(DEG_TO_RADIANS(eccentric_anomaly/2));

	// now get anomaly as atan2(X,Y)
	anomaly = atan2(X, Y);

	engine_trace(TRACE_LEVEL_ALWAYS, 
                 "OBJECT=[%s] eccentric_anomaly [%f] eccentricity [%f] true_anomaly [%f]",
                 object->object_name, eccentric_anomaly, object->eccentricity, 
                 anomaly);

	return anomaly;
}

/** ****************************************************************************

  @brief          Gets the true anomaly radius for a given object

  @param[in]      object        Current object info
  @param[in]      true_anomaly  True anomaly value

  @return         Radius obtained or -1 when error

*******************************************************************************/
double orbits_get_true_anomaly_radius(ObjectOrbitInfo_t* object, double true_anomaly)
{
	double radius = -1.0;

	if(object) {

		// The radius depends on semimajor axis (A) , true anomaly (v) and object eccentricity (e) as follows, also depends on the e number
		// r = A * ((1 - pow(e, 2))/(1 + e * cos(v))) 
		// For the sake of simplicity let's simplify the formula as follows and calculate the parts:
		// r = A *(numerator/denominator);

		double A = object->semimajor_axis;
		double numerator = 1 - pow(object->eccentricity, 2); 
		double denominator = 1 + (object->eccentricity * cos(DEG_TO_RADIANS(true_anomaly)));

		radius = A * (numerator/denominator);

		engine_trace(TRACE_LEVEL_ALWAYS, 
                 "OBJECT=[%s] true_anomaly [%f] eccentricity [%f] semimajor_axis [%f] true_anomaly_radius [%f]",
                 object->object_name, true_anomaly, object->eccentricity, object->semimajor_axis,
                 radius);
	}

	return radius;
}

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
)
{
	if(object && cartesian) {

		// The cartesian coordinates are obtained as follows: 
		// x = r (cos Ω cos (ω + ν) - sin Ω sin (ω + ν) cos i)
		// y = r (sin Ω cos (ω + ν) + cos Ω sin (ω + ν) cos i)
		// z = r (sin i sin (ω + ν))

		// Where:
		// r = anomaly radius (calculated)
		// Ω = longitude of ascending node  (provided by DB)
		// ω = argument of periapsis (provided by DB)
		// v = true anomaly (calculated)
		// i = inclination (provided by DB)

		// Fill the output coordinates using the previous formulas
		// We calculate the values of each item first to make it clearer
		// Represent Ω the with an 'm' (a character supported by C language)

		double cos_m = cos(DEG_TO_RADIANS(object->ascending_node_longitude));
		double sin_m = sin(DEG_TO_RADIANS(object->ascending_node_longitude));
		double cos_i = cos(DEG_TO_RADIANS(object->inclination));
		double sin_i = sin(DEG_TO_RADIANS(object->inclination));
		double cos_w_v = cos(DEG_TO_RADIANS(object->periapsis_argument + true_anomaly));
		double sin_w_v = sin(DEG_TO_RADIANS(object->periapsis_argument + true_anomaly));
		double r = true_anomaly_radius;
	
		cartesian->x = r * ((cos_m * cos_w_v) - (sin_m * sin_w_v * cos_i));
		cartesian->y = r * ((sin_m * cos_w_v) + (cos_m * sin_w_v * cos_i));
		cartesian->z = r * (sin_i * sin_w_v);


		engine_trace(TRACE_LEVEL_ALWAYS, 
                 "OBJECT=[%s] ascending_node_longitude [%f] inclination [%f] "
                 "periapsis_argument [%f] true_anomaly [%f] true_anomaly_radius [%f] coordinates [%f, %f, %f]",
                 object->object_name, object->ascending_node_longitude, object->inclination, object->periapsis_argument,
                 true_anomaly, true_anomaly_radius, cartesian->x, cartesian->y, cartesian->z);
	}
}

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
)
{
	ErrorCode_t result = ENGINE_INTERNAL_ERROR;

	if(object && cartesian) {

		// checkings are OK
		result = ENGINE_OK;

		// Get first the mean anomaly
		double mean_anomaly = orbits_get_mean_anomaly(object);

		// Use the mean anomaly to calculate eccentric_anomaly
		double eccentric_anomaly = orbits_get_eccentric_anomaly(object, mean_anomaly);

		// Use eccentric_anomaly to calculate true_anomaly
		double true_anomaly = orbits_get_true_anomaly(object, eccentric_anomaly);

		// Use eccentric_anomaly to calculate orbit radius
		double radius = orbits_get_true_anomaly_radius(object, true_anomaly);

		// Use all the previous params to get coordinates
		orbits_get_cartesian_coordinates(object, true_anomaly, radius, cartesian);
	}

	return result;
}

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
)
{
	// Add their coordinates to obtain the result vector

	if(vector_1 && vector_2 && vector_result) {
		vector_result->x = vector_1->x + vector_2->x;
		vector_result->y = vector_1->y + vector_2->y;
		vector_result->z = vector_1->z + vector_2->z;

		engine_trace(TRACE_LEVEL_ALWAYS, 
                 "Adding coordinates [%f, %f, %f] + [%f, %f, %f] = [%f, %f, %f]",
                 vector_1->x, vector_1->y, vector_1->z,
                 vector_2->x, vector_2->y, vector_2->z,
                 vector_result->x, vector_result->y, vector_result->z);
	}
}


/** ****************************************************************************

  @brief          Gets the distance between 2 objects (in Km)

  @param[in]      object1    First object
  @param[in]      object2    Second object

  @return         Distance  (Km)

*******************************************************************************/
double orbits_get_distance_between_objects
(
	ObjectOrbitInfo_t* object1,
	ObjectOrbitInfo_t* object2
)
{
	double distance = 0.0;

	if(object1 && object2) {
		// Get position for both objects
		CartesianInfo_t position1;
		CartesianInfo_t position2;

		if((orbits_get_object_position(object1, &position1) == ENGINE_OK) &&
			(orbits_get_object_position(object2, &position2) == ENGINE_OK))
		{
			// Calculate distance between both points
			// D = sqrt( pow(X1-X2, 2) + pow(Y1-Y2, 2) + pow(Z1-Z2, 2) )  = sqrt ( A + B + C) for sake of simplicity
			double A = pow(position1.x - position2.x, 2);
			double B = pow(position1.y - position2.y, 2);
			double C = pow(position1.z - position2.z, 2);

			distance = sqrt(A + B + C);

			engine_trace(TRACE_LEVEL_ALWAYS, 
                 "Distance between coordinates for [%s] [%f, %f, %f] and [%s] [%f, %f, %f] is [%f]",
                 object1->object_name,
                 position1.x, position1.y, position1.z,
                 object2->object_name,
                 position2.x, position2.y, position2.z,
                 distance);
		}
	}

	return distance;
}


/** ****************************************************************************

  @brief          Gets the distance to an object for a given cartesian position

  @param[in]      object1    First object
  @param[in]      position2  Second object position

  @return         Distance obtained (km)

*******************************************************************************/
double orbits_get_distance_from_object
(
	ObjectOrbitInfo_t* object1,
	CartesianInfo_t* position2
)
{
	double distance = 0.0;

	if(object1 && position2) {
		// Get position for both objects
		CartesianInfo_t position1;

		if(orbits_get_object_position(object1, &position1) == ENGINE_OK)
		{
			// Calculate distance between both points
			// D = sqrt( pow(X1-X2, 2) + pow(Y1-Y2, 2) + pow(Z1-Z2, 2) )  = sqrt ( A + B + C) for sake of simplicity
			double A = pow(position1.x - position2->x, 2);
			double B = pow(position1.y - position2->y, 2);
			double C = pow(position1.z - position2->z, 2);

			distance = sqrt(A + B + C);

			engine_trace(TRACE_LEVEL_ALWAYS, 
                 "Distance between coordinates for [%s] [%f, %f, %f] and [%f, %f, %f] is [%f]",
                 object1->object_name,
                 position1.x, position1.y, position1.z,
                 position2->x, position2->y, position2->z,
                 distance);
		}
	}

	return distance;
}

