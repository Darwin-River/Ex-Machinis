# Backup the database
To backup DB at exmachinis.com, execute the following commands at command line as the __root__ user:
```
export DB_USER=root
export DB_NAME=exmachinis
export DB_PASSWORD=[DB Password]
mysqldump --no-data -u $DB_USER -p$DB_PASSWORD $DB_NAME > $PLAT_HOME/tmp/exmachinis_model_backup_YYMMDD.sql
mysqldump --no-create-info -u $DB_USER -p$DB_PASSWORD $DB_NAME > $PLAT_HOME/tmp/exmachinis_data_backup_YYMMDD.sql
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME resources > $PLAT_HOME/tmp/exmachinis_resources_backup_YYMMDD.sql
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME agents > $PLAT_HOME/tmp/exmachinis_agents_backup_YYMMDD.sql
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME commands > $PLAT_HOME/tmp/exmachinis_commands_backup_YYMMDD.sql
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME companies > $PLAT_HOME/tmp/exmachinis_companies_backup_YYMMDD.sql
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME migrations > $PLAT_HOME/tmp/exmachinis_migrations_backup_YYMMDD.sql
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME password_resets > $PLAT_HOME/tmp/exmachinis_password_resets_backup_YYMMDD.sql
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME users > $PLAT_HOME/tmp/exmachinis_users_backup_YYMMDD.sql
```
