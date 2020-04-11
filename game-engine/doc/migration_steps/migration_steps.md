# Migration steps for GAME engine

## Backup everything

Before going forward, we MUST generate backups for both: DB and game files.

### Backup DB content

Current exmachinis DB is too old, does not have the latest DB model, so we are only interested in the users, agents, companies created so far.
We will incorporate them into the final DB once upgraded.

To backup DB at exmachinis server, execute the following commands at command line as forth/root user:

```
export DB_USER=root
export DB_NAME=exmachinis
export DB_PASSWORD=c20e5596eee9bf8519074ad62f51b0eaa5a415e805efa4b9
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME agents > $PLAT_HOME/tmp/exmachinis_agents_backup_YYMMDD.sql
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME commands > $PLAT_HOME/tmp/exmachinis_commands_backup_YYMMDD.sql
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME companies > $PLAT_HOME/tmp/exmachinis_companies_backup_YYMMDD.sql
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME migrations > $PLAT_HOME/tmp/exmachinis_migrations_backup_YYMMDD.sql
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME password_resets > $PLAT_HOME/tmp/exmachinis_password_resets_backup_YYMMDD.sql
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME users > $PLAT_HOME/tmp/exmachinis_users_backup_YYMMDD.sql
```

### Backup game files

Stop the system running as forth user:

```
stop_system.sh
```

Go to user's home and backup whole platform folder:

```
cd $HOME
cp -R game-engine game-engine.bk.MMDDYYYY
```

This way we will have under **game-engine.bk.MMDDYYYY** a copy of the current game folders in case of disaster.

## DB model migration

Ex-machinis server is seriously outdated so my advise here would be to create here a fresh/new DB using latest model (using a backup from advolition server)
and then add current users and agents already present at exmachinis DB.

This section describes the changes required to migrate the existing tables at ex-machinis server to the new model:

- Create a fresh DB
- Insert old users data into new DB

### Creating a fresh DB using advolition backup

At exmachinis remove current DB:

```
export DB_USER=root
export DB_PASSWORD=c20e5596eee9bf8519074ad62f51b0eaa5a415e805efa4b9
mysql -u $DB_USER -p$DB_PASSWORD
> drop database exmachinis;
> show databases;
> quit;
```

At advolition server generate an sql files with the current model and data sets:

```
export DB_USER=root
export DB_PASSWORD=c20e5596eee9bf8519074ad62f51b0eaa5a415e805efa4b9
export DB_NAME=exmachinis
mysqldump --no-data -u $DB_USER -p$DB_PASSWORD $DB_NAME > advolition_model_YYMMDD.sql
mysqldump --no-create-info -u $DB_USER -p$DB_PASSWORD $DB_NAME > advolition_data_YYMMDD.sql
```

Import the new model into exmachinis server (FTP the sql file generated here and run the following command):

```
export DB_USER=root
export DB_PASSWORD=c20e5596eee9bf8519074ad62f51b0eaa5a415e805efa4b9
mysql -u $DB_USER -p$DB_PASSWORD < advolition_model_YYMMDD.sql
```

Import the game data into exmachinis server:
```
[forth@advolition] # sftp forth@45.55.171.135    (SFTP session to Ex-Machinis)
forth@45.55.171.135's password:  xxxxxxxxxxxx  (I can provide it if you do not have this)
Connected to 45.55.171.135.


sftp> cd game-engine/tmp
sftp> put advolition_data_YYMMDD.sql
sftp> put advolition_model_YYMMDD.sql
sftp> exit
```

Run the following command from the exmachinis server:

```
export DB_USER=root
export DB_PASSWORD=c20e5596eee9bf8519074ad62f51b0eaa5a415e805efa4b9
mysql -u $DB_USER -p$DB_PASSWORD exmachinis < advolition_data_YYMMDD.sql
```

Now it is time to do the hardest part, incorporate the old users into new model :-) :-). These steps are described below, with the changes to be done to
accomodate old users data into new model.


### users table

The new model is this:

```
mysql> desc users;
+----------------+------------------+------+-----+---------+----------------+
| Field          | Type             | Null | Key | Default | Extra          |
+----------------+------------------+------+-----+---------+----------------+
| user_id        | int(10) unsigned | NO   | PRI | NULL    | auto_increment |
| name           | varchar(255)     | NO   |     | NULL    |                |
| email          | varchar(255)     | NO   | UNI | NULL    |                |
| password       | varchar(255)     | YES  |     | NULL    |                |
| remember_token | varchar(100)     | YES  |     | NULL    |                |
| created_at     | timestamp        | YES  |     | NULL    |                |
| updated_at     | timestamp        | YES  |     | NULL    |                |
| credits        | int(10) unsigned | YES  |     | NULL    |                |
+----------------+------------------+------+-----+---------+----------------+
```

Old model was:

```
mysql> desc users;
+----------------+------------------+------+-----+---------+----------------+
| Field          | Type             | Null | Key | Default | Extra          |
+----------------+------------------+------+-----+---------+----------------+
| id             | int(10) unsigned | NO   | PRI | NULL    | auto_increment |
| name           | varchar(255)     | NO   |     | NULL    |                |
| email          | varchar(255)     | NO   | UNI | NULL    |                |
| password       | varchar(255)     | YES  |     | NULL    |                |
| remember_token | varchar(100)     | YES  |     | NULL    |                |
| created_at     | timestamp        | YES  |     | NULL    |                |
| updated_at     | timestamp        | YES  |     | NULL    |                |
+----------------+------------------+------+-----+---------+----------------+
```

Changes:

- Old **id** fiels is now **user_id**
- We have a new field **credits** where we store the balance for a given user (it can be initialized with the value: 1000 for the moment)


To make these changes (at exmachinis server as forth user), we need to  modify the file generated at backup step as follows:

```
cd $PLAT_HOME/tmp
cp exmachinis_users_backup_YYMMDD.sql exmachinis_users_newmodel_YYMMDD.sql
```

To make these changes (at exmachinis server as forth user), we need to  modify the file generated at backup step as follows:

```
cd $PLAT_HOME/tmp
cp exmachinis_users_backup_YYMMDD.sql exmachinis_users_newmodel_YYMMDD.sql
```

At the new model file, we need to take into account the changes described above to insert the old information inside the new table.

This statement:

```
INSERT INTO `users` VALUES (1,'Williamtroke','codydavis3434@yahoo.com','$2y$10$maRcbyOV5SOexfj02ptLg.X64XcemvA5ic2U2oiaqV/v43.zfRa12',NULL,'2017-11-15 23:15:12','2017-11-15 23:15:12')
```

would be now (notice that we add now the 1000 credits at the end):

```
INSERT INTO `users` VALUES (1,'Williamtroke','codydavis3434@yahoo.com','$2y$10$maRcbyOV5SOexfj02ptLg.X64XcemvA5ic2U2oiaqV/v43.zfRa12',NULL,'2017-11-15 23:15:12','2017-11-15 23:15:12', 1000)
```


### agents table


The new model is this:

```
+-----------+------------------+------+-----+---------+----------------+
| Field     | Type             | Null | Key | Default | Extra          |
+-----------+------------------+------+-----+---------+----------------+
| agent_id  | int(10) unsigned | NO   | PRI | NULL    | auto_increment |
| user_id   | int(10) unsigned | NO   | MUL | NULL    |                |
| name      | varchar(255)     | NO   | MUL | NULL    |                |
| vm        | mediumblob       | YES  |     | NULL    |                |
| input     | longtext         | YES  |     | NULL    |                |
| output    | longtext         | YES  |     | NULL    |                |
| subject   | varchar(2048)    | YES  |     | NULL    |                |
| object_id | int(11)          | NO   | MUL | 187     |                |
| hull_type | int(2) unsigned  | YES  | MUL | NULL    |                |
+-----------+------------------+------+-----+---------+----------------+
```

Old model was:

```
+------------+------------------+------+-----+---------+----------------+
| Field      | Type             | Null | Key | Default | Extra          |
+------------+------------------+------+-----+---------+----------------+
| id         | int(10) unsigned | NO   | PRI | NULL    | auto_increment |
| name       | varchar(255)     | NO   |     | NULL    |                |
| company_id | int(10) unsigned | NO   | MUL | NULL    |                |
| coord_x    | double(8,2)      | NO   |     | NULL    |                |
| coord_y    | double(8,2)      | NO   |     | NULL    |                |
| coord_z    | double(8,2)      | NO   |     | NULL    |                |
| speed_x    | double(8,2)      | NO   |     | 0.00    |                |
| speed_y    | double(8,2)      | NO   |     | 0.00    |                |
| speed_z    | double(8,2)      | NO   |     | 0.00    |                |
| vm         | mediumblob       | YES  |     | NULL    |                |
| input      | longtext         | YES  |     | NULL    |                |
| output     | longtext         | YES  |     | NULL    |                |
| subject    | varchar(2048)    | YES  |     | NULL    |                |
| object_id  | int(11)          | NO   |     | 187     |                |
+------------+------------------+------+-----+---------+----------------+
```

Changes:

- Old **id** fiels is now **agent_id**
- We store also at this table the **user_id** to keep track of the user it belongs to (We will need to do this manually and carefully)
- Fields **company_id**, **coord_x**,  **coord_x**, **coord_x**, **speed_x**,  **speed_y**, **speed_z** are deprecated and no longer required.
- New field **hull_type** is required.

To make these changes (at exmachinis server as forth user), we need to  modify the file generated at backup step as follows:

```
cd $PLAT_HOME/tmp
cp exmachinis_agents_backup_YYMMDD.sql exmachinis_agents_newmodel_YYMMDD.sql
```

At the new model file, we need to take into account the changes described above to insert the old information inside the new table.

This statement:

```
INSERT INTO `agents` VALUES (1,'SN667554',1,0.00,0.00,0.00,0.00,0.00,0.00,NULL,NULL,' 5',NULL,187),
```

would be now (notice that we need to find some fields at DB or assign a value, this is indicated with ? to reflect the same):

```
INSERT INTO `agents` VALUES (1, ? (user_id for this drone), 'SN667554', NULL, NULL,' 5', NULL, 187, ? (hull_type to be assigned)),
```

### companies table

This table is no longer required and can be removed at new model

### commands table

This table does not change

### New password_resets table

This table does not change



## Engine migration

The best way (once backup is done) to update the game engines is just to copy whole content into the target server.

Copying the entire folder:  **/home/forth/game-engine** from one server into the other should work.

Do the previous step with system stopped.

Run sftp from **/home/forth** on the advolition server:

```
[forth@advolition] # sftp forth@45.55.171.135    (SFTP session to Ex-Machinis)
forth@45.55.171.135's password:  xxxxxxxxxxxx 
Connected to 45.55.171.135.

sftp> put -R game-engine (-R recursive with overwrite, -r recursive without overwrite)
sftp> exit
```


### Crontab adjustements

Finally we will need to add a new crontab task added recently to run the events-engine or restart it when crashed, this step can be done as follows:

```
cd /tmp
crontab -l > crontab_tasks.txt
echo "*/1 * * * * sh -c '. /home/forth/.profile; /home/forth/game-engine/bin/scripts/start_events_engine.sh' >> /dev/null 2>&1" >> crontab_tasks.txt
crontab < crontab_tasks.txt
```
