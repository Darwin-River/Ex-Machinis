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

/******************************* DEFINES *************************************/


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
		//(t-t0) = he difference in times expressed in days

		anomaly = object->mean_anomaly + object->mean_angular_motion * diff_in_days;
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

		//                  
		//                  Ei -  (M + e * sin(Ei))
		//  E    =  E   - (------------------------)
		//   i+1     i         1 -  e * cos(Ei)
		//

		// We start with E0 = M and iterate, with M = mean_anomaly previously calculated

		double Ei  = mean_anomaly;
		double Ei1 = mean_anomaly;

		// we iterate till the abs difference of Ei and Ei1 is lower than a pre-defined value (we take for the moment 0.01)
		// first round we start with Ei = mean_anomaly and then calculate the next
		do {
			Ei = Ei1; // last value = current value
			double numerator = Ei - (mean_anomaly + M_E * sin(Ei));
			double denominator = 1 - (M_E * cos(Ei));

			Ei1 = Ei - (numerator/denominator);

		} while (abs(Ei - Ei1) >= 0.01);
	}

	return anomaly;
}

/** ****************************************************************************

  @brief          Gets the true anomaly for a given object

  @param[in]      eccentric_anomaly  Current object info

  @return         Anomaly obtained or -1 when error

*******************************************************************************/
double orbits_get_true_anomaly(double eccentric_anomaly)
{
	double anomaly = -1.0;

	// The true anomaly is obtained as follows
	// v = atan2(sqrt(1-E) * cos(E/2), sqrt(1+E) * sin(E/2));

	// we simplify this function doing: atan2(x, y), first we calculate X and then Y

	double X = sqrt(1 - eccentric_anomaly) * cos(eccentric_anomaly/2);
	double Y = sqrt(1 + eccentric_anomaly) * sin(eccentric_anomaly/2);

	// now get anomaly as atan2(X,Y)
	anomaly = atan2(X, Y);

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

		// The radius depends on semimajor axis (A) and true anomaly (v) as follows, also depends on the e number
		// r = A * ((1 - pow(e, 2))/(1 + e * cos(v))) 
		// For the sake of simplicity let's simplify the formula as follows and calculate the parts:
		// r = A *(numerator/denominator);

		double A = object->semimajor_axis;
		double numerator = 1 * pow(M_E, 2);  // M_E = e in maths library
		double denominator = 1 + (M_E * cos(true_anomaly));

		radius = A * (numerator/denominator);
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

		double cos_m = cos(object->ascending_node_longitude);
		double sin_m = sin(object->ascending_node_longitude);
		double cos_i = cos(object->inclination);
		double sin_i = sin(object->inclination);
		double cos_w_v = cos(object->periapsis_argument + true_anomaly);
		double sin_w_v = sin(object->periapsis_argument + true_anomaly);
		double r = true_anomaly_radius;
	
		cartesian->x = r * ((cos_m * cos_w_v) - (sin_m * sin_w_v * cos_i));
		cartesian->y = r * ((sin_m * cos_w_v) + (cos_m * sin_w_v * cos_i));
		cartesian->z = r * (sin_i * sin_w_v);
	}
}


