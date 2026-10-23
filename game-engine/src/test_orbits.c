/*
 * Checks the orbit maths (orbits.c) against real, observed positions, using
 * the same elements as game_data.sql. Run with: make test
 *
 * The engine computes a body's position for "now". To evaluate another date,
 * each test shifts the epoch by (now - date): only the time since the epoch
 * matters.
 */
#include <math.h>
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#include "orbits.c"

/* The tests run at scale 1: game time is real time */
double game_time_now(void)
{
    return (double)time(NULL);
}

void engine_trace(TraceLevel_t level, const char *trace, ...)
{
    (void)level;
    (void)trace;
}

#define AU 149597870.7

static int failures = 0;

static time_t utc(int y, int mo, int d)
{
    struct tm tm;
    memset(&tm, 0, sizeof(tm));
    tm.tm_year = y - 1900;
    tm.tm_mon = mo - 1;
    tm.tm_mday = d;
    return timegm(&tm);
}

/* Elements as stored in the objects table (heliocentric unless noted) */
static ObjectOrbitInfo_t body(const char *name, time_t epoch, double a_au, double e, double w,
                              double M, double i, double node)
{
    ObjectOrbitInfo_t o;
    memset(&o, 0, sizeof(o));
    snprintf(o.object_name, sizeof(o.object_name), "%s", name);
    o.epoch = epoch;
    o.semimajor_axis = a_au * AU;
    o.eccentricity = e;
    o.periapsis_argument = w;
    o.mean_anomaly = M;
    o.inclination = i;
    o.ascending_node_longitude = node;
    o.mean_angular_motion = 0.9856076686 / pow(a_au, 1.5);
    return o;
}

static CartesianInfo_t position_at(ObjectOrbitInfo_t o, time_t when)
{
    CartesianInfo_t c;
    o.epoch += time(NULL) - when;
    orbits_get_object_position(&o, &c);
    return c;
}

static double norm(CartesianInfo_t a)
{
    return sqrt(a.x * a.x + a.y * a.y + a.z * a.z);
}

static double dist(CartesianInfo_t a, CartesianInfo_t b)
{
    CartesianInfo_t d = { a.x - b.x, a.y - b.y, a.z - b.z };
    return norm(d);
}

static void check(const char *what, double got, double want, double tolerance)
{
    int ok = fabs(got - want) <= tolerance;
    printf("%s %-55s got %.4f, expected %.4f +- %.4f\n", ok ? "ok  " : "FAIL", what, got, want, tolerance);
    if (!ok)
        failures++;
}

int main(void)
{
    time_t j2000 = utc(2000, 1, 1) + 12 * 3600;
    ObjectOrbitInfo_t earth = body("Earth", j2000, 1.00000261, 0.01671123, 102.937682, 357.52689, -0.00001531, 0);
    ObjectOrbitInfo_t mars = body("Mars", j2000, 1.52371034, 0.09339410, 286.496831, 19.390198, 1.84969142, 49.55953891);
    ObjectOrbitInfo_t jupiter = body("Jupiter", j2000, 5.20288700, 0.04838624, 274.254571, 19.667961, 1.30439695, 100.47390909);
    ObjectOrbitInfo_t halley = body("Halley", utc(2025, 11, 21), 17.86, 0.967941, 112.42, 189.56, 162.16, 59.52);
    ObjectOrbitInfo_t cg67p = body("67P", utc(2025, 11, 21), 3.46, 0.649733, 22.22, 226.64, 3.87, 36.31);

    /* Kepler's equation: E - e sin E = M, in degrees, even for comets */
    double es[] = { 0, 0.0167, 0.5, 0.9, 0.97, 0.99 };
    for (size_t k = 0; k < sizeof(es) / sizeof(es[0]); k++) {
        for (double M = 0; M < 360; M += 7.5) {
            ObjectOrbitInfo_t o = earth;
            o.eccentricity = es[k];
            double E = orbits_get_eccentric_anomaly(&o, M);
            double back = E - RADIANS_TO_DEG(es[k] * sin(DEG_TO_RADIANS(E)));
            double err = fabs(fmod(back - M + 540.0, 360.0) - 180.0);
            if (err > 1e-5) {
                printf("FAIL Kepler e=%.3f M=%.1f: E=%f gives M=%f\n", es[k], M, E, back);
                failures++;
            }
        }
    }
    printf("ok   Kepler's equation solved for e up to 0.99\n");

    /* Earth's perihelion and aphelion distances in 2026 */
    check("Earth-Sun distance at perihelion, 3 Jan 2026 (au)", norm(position_at(earth, utc(2026, 1, 3))) / AU, 0.98330, 0.0005);
    check("Earth-Sun distance at aphelion, 6 Jul 2026 (au)", norm(position_at(earth, utc(2026, 7, 6))) / AU, 1.01670, 0.0005);

    /* Mars' closest approach, 12 Jan 2025: 96.1 million km */
    check("Earth-Mars distance, 12 Jan 2025 (au)",
          dist(position_at(earth, utc(2025, 1, 12)), position_at(mars, utc(2025, 1, 12))) / AU, 0.642, 0.01);

    /* Jupiter at opposition, 10 Jan 2026: about 633 million km */
    check("Earth-Jupiter distance, 10 Jan 2026 (au)",
          dist(position_at(earth, utc(2026, 1, 10)), position_at(jupiter, utc(2026, 1, 10))) / AU, 4.23, 0.05);

    /* Halley's aphelion, 9 Dec 2023: about 35.1 au from the Sun */
    check("Halley-Sun distance at aphelion, 9 Dec 2023 (au)", norm(position_at(halley, utc(2023, 12, 9))) / AU, 35.1, 0.3);

    /* 67P's perihelion, 2 Nov 2021: 1.21 au */
    check("67P-Sun distance at perihelion, 2 Nov 2021 (au)", norm(position_at(cg67p, utc(2021, 11, 2))) / AU, 1.21, 0.03);

    if (failures) {
        printf("%d check(s) failed\n", failures);
        return 1;
    }
    printf("All orbit checks passed\n");
    return 0;
}
