#!/bin/bash
#
# Script that stops all modules
#

. ${HOME}/.profile

# Incoming mails module
$PLAT_HOME/bin/scripts/stop_email_handler.sh 

# Events engine
$PLAT_HOME/bin/scripts/stop_events_engine.sh

#  Game engine
$PLAT_HOME/bin/scripts/stop_game_engine.sh

