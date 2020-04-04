# Game contents

## Root directory

/home/forth/game-engine


## Folders & files structure

```
./tmp
./test
./db
./db/create_db.sql
./db/objects_load.sql
./db/objects.sql
./conf
./conf/events-engine.conf
./conf/profile.conf
./conf/game-engine.conf
./conf/crontab.conf
./log
./bin
./bin/scripts
./bin/scripts/db.sh
./bin/scripts/dump.sh
./bin/scripts/start_system.sh
./bin/scripts/start_email_handler.sh
./bin/scripts/.env
./bin/scripts/stop_system.sh
./bin/scripts/start_events_engine.sh
./bin/scripts/reset_db.sh
./bin/scripts/email
./bin/scripts/email/email_html.sh
./bin/scripts/email/email.sh
./bin/scripts/email/email.php
./bin/scripts/housekeeping.sh
./bin/scripts/stop_game_engine.sh
./bin/scripts/stop_email_handler.sh
./bin/scripts/stop_events_engine.sh
./bin/events-engine
./bin/eforth.blk
./bin/game-engine
./bin/embed.fth
```


### tmp

Just temporal folder used by the system when needed


### test

Another temporal folder used by us when we want to run tests


### db

Here we store some handy db management SQL scripts or dumps


### log

Application logs


### bin

Core binaries:

- Game engine
- Events engine
- Templates for VM

### bin\scripts

Administration scripts to stop/start the system as a whole or its individual components.

Also handy scripts to connect to db (**db.sh**).

IMPORTANT: The .env file is required, do NOT delete it.

We have also a housekeeping chore that runs from the cron to keep the filesystem healthy.

