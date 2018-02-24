#!/bin/bash
#
# Script that periodically checks emails received and process them
#

. ${HOME}/.profile


#-----------------------------------------------------------------
# Log function
#-----------------------------------------------------------------
log_msg()
{
  TODAY=`date +'%Y%m%d'`
  TIMESTAMP=`date +'%Y-%m-%d %H:%M:%S'`
  echo ${TIMESTAMP} ":" "$*" >> ${LOG_FILE_PREFIX}_${TODAY}.log
}

#-----------------------------------------------------------------
# PID management functions - save to check it later
#-----------------------------------------------------------------
save_pid()
{
  echo ${MYPID} > ${PLAT_HOME}/tmp/email.pid
}

#-----------------------------------------------------------------
# Signals handler function
#-----------------------------------------------------------------
signal_handler()
{
  log_msg "************************************************************"
  log_msg "WARNING: Process stopped by signal"
  log_msg "************************************************************" 
  exit 1
}

#-----------------------------------------------------------------
# Script configuration
#-----------------------------------------------------------------

# Check period (seconds)
DELAY_SECONDS=5

# Log file
LOG_FILE_PREFIX=${PLAT_HOME}/log/email_handler

# URL where we check emails presence
EMAIL_URL=https://www.exmachinis.com/get-mails/JJy3CC9cUtzsbLsY

# Tmp file where we store handler PID
PID_FILE=${PLAT_HOME}/tmp/email.pid

#-----------------------------------------------------------------
# Signals processing
#-----------------------------------------------------------------
trap signal_handler SIGINT SIGTERM

#-----------------------------------------------------------------
# Watchdog - abort if we are already running
#-----------------------------------------------------------------

if [ -f ${PID_FILE} ]
then
  PID=`cat ${PID_FILE}`

  # Check presence
  ps -p ${PID} > /dev/null

  if [ $? -eq 0 ]
  then
    # We are already running - stop here 
    echo "PID ${PID} is already running"
    exit 0
  else
    log_msg "WARNING: Email handler with PID ${PID} seems stopped, restarting ..."
    rm -f ${PID_FILE}
  fi
else
  # It is the first time - add cron watchdog
  # Add the watchdog line to cron
  (crontab -u ${USER} -l ; echo "*/1 * * * * sh -c '. $HOME/.profile; $PLAT_HOME/bin/scripts/start_email_handler.sh' >> /dev/null 2>&1") | crontab -u ${USER} -  
fi

# If reach this point a restart is required


#-----------------------------------------------------------------
# Loop execution - store PID to check later
#-----------------------------------------------------------------

MYPID=$$

save_pid

log_msg "************************************************************"
log_msg "Running email handler with PID ${MYPID}"
log_msg "************************************************************"

while :
do
  log_msg "Checking input emails"

  CHECK_OUTPUT=`curl -s ${EMAIL_URL}`

  log_msg ${CHECK_OUTPUT}

  sleep ${DELAY_SECONDS}
done
