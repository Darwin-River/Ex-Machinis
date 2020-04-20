# Connect to DB

# Load conf from environment
. .env

# Execute SQL statements to clean DB and reset everything, we need to enter the password
mysql -u $DB_USER -p$DB_PASSWORD $DB_NAME

