# Connect to DB

# Load conf from environment
. .env

# Execute SQL statements to clean DB and reset everything, we need to enter the password
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME event_types hulls location_effects market_effects objects protocols resource_effects resources queries > $PLAT_HOME/tmp/exmachinis_tables.sql

