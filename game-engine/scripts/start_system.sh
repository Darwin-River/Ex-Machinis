#!/bin/bash
#
# Script that starts all modules
#

. ${HOME}/.profile

#  Game engine
$PLAT_HOME/bin/scripts/start_game_engine.sh > /dev/null 2>&1

# Events engine
$PLAT_HOME/bin/scripts/start_events_engine.sh > /dev/null 2>&1

# Incoming mails module
$PLAT_HOME/bin/scripts/start_email_handler.sh & > /dev/null 2>&1
