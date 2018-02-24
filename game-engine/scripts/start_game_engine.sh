#!/bin/bash
#
# Script that starts game engine
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
  echo ${ENGINE_PID} > ${PID_FILE}
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

# Log file
LOG_FILE_PREFIX=${PLAT_HOME}/log/start_game_engine

# Tmp file where we store handler PID
PID_FILE=${PLAT_HOME}/tmp/engine.pid

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
    #echo "PID ${PID} is already running"
    exit 0
  else
    log_msg "WARNING: Game engine with PID ${PID} seems stopped, restarting ..."
    rm -f ${PID_FILE}
  fi
fi

# If reach this point a restart is required


#-----------------------------------------------------------------
# Launch game engine and store its PID
#-----------------------------------------------------------------

nohup $PLAT_HOME/bin/game-engine &
ENGINE_PID=$!
save_pid

# Add the watchdog line to cron
(crontab -u ${USER} -l ; echo "*/1 * * * * sh -c '. $HOME/.profile; $PLAT_HOME/bin/scripts/start_game_engine.sh' >> /dev/null 2>&1") | crontab -u ${USER} -

log_msg "************************************************************"
log_msg "Running game engine with PID ${ENGINE_PID}"
log_msg "************************************************************"
