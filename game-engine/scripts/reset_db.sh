# The purpose of this script is to reset all dynamic tables when restoring an environment
# We clean tables live: livescore, participants, betodd, events, etc, ... that contain dynamic data
# We also restore auto-increment index at these tables

# Load conf from environment
. .env

# We first generate a temporal SQL file
TMP_FILE=/tmp/clean_db.sql
touch  ${TMP_FILE}
echo "use $DB_NAME;" > ${TMP_FILE}
echo "delete from agents;" >> ${TMP_FILE}
echo "delete from events;" >> ${TMP_FILE}
echo "delete from actions;" >> ${TMP_FILE}
echo "delete from commands;" >> ${TMP_FILE}
echo "delete from password_resets;" >> ${TMP_FILE}
echo "delete from observations;" >> ${TMP_FILE}
echo "delete from users;" >> ${TMP_FILE}
echo "ALTER TABLE agents AUTO_INCREMENT = 1;" >> ${TMP_FILE}
echo "ALTER TABLE events AUTO_INCREMENT = 1;" >> ${TMP_FILE}
echo "ALTER TABLE actions AUTO_INCREMENT = 1;" >> ${TMP_FILE}
echo "ALTER TABLE commands AUTO_INCREMENT = 1;" >> ${TMP_FILE}
echo "ALTER TABLE observations AUTO_INCREMENT = 1;" >> ${TMP_FILE}
echo "ALTER TABLE users AUTO_INCREMENT = 1;" >> ${TMP_FILE}

# Execute SQL statements to clean DB and reset everything, we need to enter the password
mysql -u $DB_USER -p$DB_PASSWORD < ${TMP_FILE}

# Remove temporal file
rm -f ${TMP_FILE}

