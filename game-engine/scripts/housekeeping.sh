#!/bin/bash

# This script does a backup of the modules involved

#DATE=$(date +"%d-%m-%Y")
#BACKUP_FILE=GAME_ENGINE_backup_${DATE}.tar

# Skip backup for the moment
#  Do whole backup
#cd $PLAT_HOME
#tar cvf ${BACKUP_FILE} bin conf test log
#gzip ${BACKUP_FILE}

# Remove now those older than a date
find $PLAT_HOME/log -mtime +7 -delete
#find $PLAT_HOME/backup -mtime +1 -delete

#mv ${BACKUP_FILE}.gz ${PLAT_HOME}/backup

# Clean tables with old elements (provisional script, unsecure!!!)
DB_PASSW=c20e5596eee9bf8519074ad62f51b0eaa5a415e805efa4b9
DB_NAME=exmachinis
DB_USER=root
DB_HOST=localhost
mysql -h ${DB_HOST} -u${DB_USER} -p${DB_PASSW} ${DB_NAME} -e "DELETE FROM actions WHERE timestamp < (NOW() - interval 30 day);"
mysql -h ${DB_HOST} -u${DB_USER} -p${DB_PASSW} ${DB_NAME} -e "DELETE FROM events WHERE timestamp < (NOW() - interval 30 day);"