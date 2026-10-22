#!/bin/bash
#
# Script that starts game engine
#

. ${HOME}/.profile

#-----------------------------------------------------------------
# Script configuration
#-----------------------------------------------------------------

# Tmp file where we store email handler PID
PID_FILE=${PLAT_HOME}/tmp/email.pid

# Stop the engine
if [ -f ${PID_FILE} ]
then
  PID=`cat ${PID_FILE}`

  # Check presence
  ps -p ${PID} > /dev/null

  if [ $? -eq 0 ]
  then
    # Remove watchdog line from cron
    crontab -u ${USER} -l | grep -v 'start_email_handler.sh'  | crontab -u ${USER} -
    # We stop it when running
    kill ${PID}
    echo "Email handler with PID ${PID} stopped"
    rm ${PID_FILE}
    exit 0
  else
    echo "WARNING: Email handler seems stopped"  
    rm ${PID_FILE}
    # Remove watchdog line from cron
    crontab -u ${USER} -l | grep -v 'start_email_handler.sh'  | crontab -u ${USER} -
  fi
else
  echo "WARNING: File ${PID_FILE} is not defined, could not stop email handler module"
  # Remove watchdog line from cron
  crontab -u ${USER} -l | grep -v 'start_email_handler.sh'  | crontab -u ${USER} -
fi

