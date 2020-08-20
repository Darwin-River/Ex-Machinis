#!/bin/bash
#
# Script that stops events engine
#

. ${HOME}/.profile

#-----------------------------------------------------------------
# Script configuration
#-----------------------------------------------------------------

# Tmp file where we store engine PID
PID_FILE=${PLAT_HOME}/tmp/events_engine.pid

# Stop the engine
if [ -f ${PID_FILE} ]
then
  PID=`cat ${PID_FILE}`

  # Check presence
  ps -p ${PID} > /dev/null

  if [ $? -eq 0 ]
  then
    # Remove watchdog line from cron
    crontab -u ${USER} -l | grep -v 'start_events_engine.sh'  | crontab -u ${USER} -
    # We stop it when running
    kill ${PID}
    echo "Events engine with PID ${PID} stopped"
    rm  ${PID_FILE}
    exit 0
  else
    echo "WARNING: Events engine seems stopped"  
    rm  ${PID_FILE}
    # Remove cron line anyways
    crontab -u ${USER} -l | grep -v 'start_events_engine.sh'  | crontab -u ${USER} -
  fi
else
  echo "WARNING: File ${PID_FILE} is not defined, could not stop events engine"
  # Remove cron line anyways
  crontab -u ${USER} -l | grep -v 'start_events_engine.sh'  | crontab -u ${USER} -  
fi

