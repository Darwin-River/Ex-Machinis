# Ex-machinis

### Requirements

Ex-Machinis works on an Apache server that supports PHP (at least 7.0 version) and a MySQL database.
The mail server works on Linux using Postfix as MTA and Dovecot as e-mail retrieval service.
A SSL certificate is required for a public release.

### Installation

Copy all files and folders to a directory (game-engine folder is not necessary) where you want the project to be placed, then set in Apache's httpd.conf the "html" folder as web root (this guide explains it for Ubuntu <a href="https://www.digitalocean.com/community/tutorials/how-to-move-an-apache-web-root-to-a-new-location-on-ubuntu-16-04">https://www.digitalocean.com/community/tutorials/how-to-move-an-apache-web-root-to-a-new-location-on-ubuntu-16-04</a> ) . If the folders structure is changed, you may have to edit index.php on the "html" folder to set the paths.
In order to import the database, set the user/password on env.php and run 
```
php artisan migrate
```

### Mail application setup

The mail application uses Postfix and Dovecot. In order to install them on a Ubuntu distribution you can follow this tutorial <a href="https://www.digitalocean.com/community/tutorials/how-to-set-up-a-postfix-e-mail-server-with-dovecot">https://www.digitalocean.com/community/tutorials/how-to-set-up-a-postfix-e-mail-server-with-dovecot</a>.
Then you should set Postfix and Dovecot's configurations as follows:
main.cf:
```
myhostname = exmachinis.com
myorigin = /etc/mailname
mydestination = exmachinis.com, localhost, localhost.localdomain
relayhost =
mynetworks = 127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128
mailbox_size_limit = 0
recipient_delimiter = +
inet_interfaces = all

alias_maps = hash:/etc/aliases
alias_database = hash:/etc/aliases

smtpd_tls_cert_file=/etc/ssl/certs/server.pem
smtpd_tls_key_file=/etc/ssl/certs/exmachinis.com.key
smtpd_use_tls=yes
smtpd_tls_session_cache_database = btree:${data_directory}/smtpd_scache
smtp_tls_session_cache_database = btree:${data_directory}/smtp_scache
smtpd_tls_security_level=may
smtpd_tls_protocols = !SSLv2, !SSLv3

local_recipient_maps =
luser_relay = catchall
```
dovecot.conf:
```
dict {
  #quota = mysql:/etc/dovecot/dovecot-dict-sql.conf.ext
  #expire = sqlite:/etc/dovecot/dovecot-dict-sql.conf.ext
}
disable_plaintext_auth = no
mail_privileged_group = mail
mail_location = mbox:~/mail:INBOX=/var/mail/%u
auth_debug_passwords=yes
userdb {
  driver = passwd
}
passdb {
 # args = %s
  driver = pam
  args = failure_show_msg=yes
}
protocols = " imap "
protocol imap {
 # mail_plugins = " autocreate"
 imap_client_workarounds = tb-extra-mailbox-sep
}
plugin {
  autocreate = Trash
  autocreate2 = Sent
  autosubscribe = Trash
  autosubscribe2 = Sent
}
service auth {
  unix_listener /var/spool/postfix/private/auth {
    group = postfix
    mode = 0660
    user = postfix
  }
}
namespace inbox {
  inbox = yes

  mailbox Trash {
    auto = subscribe # autocreate and autosubscribe the Trash mailbox
    special_use = \Trash
  }
  mailbox Sent {
    auto = subscribe # autocreate and autosubscribe the Sent mailbox
    special_use = \Sent
  }
}
ssl=required
ssl_cert = </etc/ssl/certs/server.pem
ssl_key = </etc/ssl/certs/exmachinis.com.key
```


### Game engine setup

The game engine modules run under **forth** Linux user. This user belongs to **dev** group. 

```
uid=1006(forth) gid=1006(dev) 
```


At user's environment (.profile) we configure the following stuff:

```
# Define PLAT_HOME path where the game stuff is placed
export PLAT_HOME=$HOME/game-engine
export WORKSPACE=$HOME/workspace/game-engine

# Also some alias to move to the PLAT_HOME subdirectories
alias cdpl='cd $PLAT_HOME;pwd'
alias cdex='cd $PLAT_HOME/bin;pwd'
alias cdscr='cd $PLAT_HOME/bin/scripts;pwd'
alias cdcf='cd $PLAT_HOME/conf;pwd'
alias cdlog='cd $PLAT_HOME/log;pwd'
alias cdtmp='cd $PLAT_HOME/tmp;pwd'

# Alias for workspace, to compile the daemon
alias cdsrc='cd $WORKSPACE;pwd'

# Allow memory coredump generation when engine crashes
ulimit -c unlimited


# Put in the PATH binaries and scripts
export PATH=$PATH:$PLAT_HOME/bin:$PLAT_HOME/bin/scripts
```

All the game files are placed under $PLAT_HOME directory (/home/forth/game-engine) using the following structure of directories:

```
[forth@ExMachinis] # cd $PLAT_HOME
[forth@ExMachinis] # find . -type d
.
./conf
./bin
./bin/scripts
./bin/scripts/email
./backup
./tmp
./log
./test
```

At user's cron there are the following tasks programmed (they are just watchdog scripts to restart the suitable module if stopped).
We have one line to restart the email handler and another to restart the game engine (if they were stopped)

```
*/1 * * * * sh -c '. /home/forth/.profile; /home/forth/game-engine/bin/scripts/start_email_handler.sh' >> /dev/null 2>&1
*/1 * * * * sh -c '. /home/forth/.profile; /home/forth/game-engine/bin/scripts/start_game_engine.sh' >> /dev/null 2>&1
```

### Engine modules

#### Email handler

The email handler module reads periodically (period is configurable inside the script) the catch-all inbox. 

This module is basically a bash script: **/home/forth/game-engine/bin/scripts/start_email_handler.sh**

that invokes the following command: 

```
> curl -s https://www.exmachinis.com/get-mails/JJy3CC9cUtzsbLsY

where:

- **curl -s** : invokes HTTPS request

- **https://www.exmachinis.com** : domain we want to invoke (will depend on current server name)

- **get-mails** : is the PHP script in charge of processing the emails using Laravel terminology

- **JJy3CC9cUtzsbLsY** : It is the key configured at dovecot setup
```


When it receives any email, it checks the destination address and applies the suitable logic depending on this address, updating the Mysql database information.


#### Game engine

It reads MySQL database (periodically) and retrieves emails information stored by email handler module.

It runs the FORTH scripts received by email and updates user VM.


