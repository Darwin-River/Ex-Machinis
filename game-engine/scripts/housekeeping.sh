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

# Do a backup of DB static tables, keeping a copy of previous backup
BACKUP_FILE=$PLAT_HOME/tmp/exmachinis_tables.sql

if [ -f ${BACKUP_FILE} ]
then    
    mv ${BACKUP_FILE} ${BACKUP_FILE}.previous
fi  

# do a new backup
$PLAT_HOME/bin/scripts/dump.sh
