/*
 * The processor tax (#544): the database side. See compute.h for the
 * arithmetic.
 */
#ifndef __DB_COMPUTE_H__
#define __DB_COMPUTE_H__

#include "common_types.h"
#include "db.h"

/* Bills once a game hour */
#define COMPUTE_BILL_SECONDS 3600

/** Once a game hour: bills every company for the instructions its drones
    ran since the last bill (loading a released script is free), at
    house_rules.compute_tax_per_million; takes what it can of the bill and
    any debt from its credits, and keeps the rest as compute_debt */
ErrorCode_t db_compute_bill_due(void);

#endif
