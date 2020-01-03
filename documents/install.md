# Ex-machinis Installation

### Requirements

Ex-Machinis works on an Apache server that supports PHP (at least 7.0 version) and a MySQL database.
The mail server works on Linux using Postfix as MTA and Dovecot as e-mail retrieval service.
A SSL certificate is required for a public release.

### Installation

Copy all files and folders to a directory (game-engine folder is not necessary) where you want the project to be placed, then set in Apache's httpd.conf the "html" folder as web root (this guide explains it for Ubuntu <a href="https://www.digitalocean.com/community/tutorials/how-to-move-an-apache-web-root-to-a-new-location-on-ubuntu-16-04">https://www.digitalocean.com/community/tutorials/how-to-move-an-apache-web-root-to-a-new-location-on-ubuntu-16-04</a> ) . If the folders structure is changed, you may have to edit index.php on the "html" folder to set the paths.
There is a "model of .env" file you'd have to use to create a .env file where you have to set all the credentials for the PHP application.
In order to import the database, set the user/password on env.php and run: 
```
php artisan migrate
```
In case the Laravel dependencies weren't copied/downloaded, you will have to run this command from the project's folder:
```
php composer install
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

smtpd_tls_cert_file=/etc/ssl/certs/server.crt
smtpd_tls_key_file=/etc/ssl/certs/exmachinis.com.key
smtpd_use_tls=yes
smtpd_tls_session_cache_database = btree:${data_directory}/smtpd_scache
smtp_tls_session_cache_database = btree:${data_directory}/smtp_scache
smtpd_tls_security_level=may
smtpd_tls_protocols = !SSLv2, !SSLv3

local_recipient_maps =
luser_relay = catchall
```
master.cf (active lines only):  
```
submission inet n       -       -       -       -       smtpd
  -o syslog_name=postfix/submission
  -o smtpd_tls_wrappermode=no
  -o smtpd_tls_security_level=encrypt
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_reject_unlisted_recipient=no
  -o smtpd_recipient_restrictions=permit_sasl_authenticated,reject
  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject
  -o milter_macro_daemon_name=ORIGINATING
  -o smtpd_sasl_type=dovecot
  -o smtpd_sasl_path=private/auth
pickup    unix  n       -       y       60      1       pickup
cleanup   unix  n       -       y       -       0       cleanup
qmgr      unix  n       -       n       300     1       qmgr
#qmgr     unix  n       -       n       300     1       oqmgr
tlsmgr    unix  -       -       y       1000?   1       tlsmgr
rewrite   unix  -       -       y       -       -       trivial-rewrite
bounce    unix  -       -       y       -       0       bounce
defer     unix  -       -       y       -       0       bounce
trace     unix  -       -       y       -       0       bounce
verify    unix  -       -       y       -       1       verify
flush     unix  n       -       y       1000?   0       flush
proxymap  unix  -       -       n       -       -       proxymap
proxywrite unix -       -       n       -       1       proxymap
smtp      unix  -       -       y       -       -       smtp
relay     unix  -       -       y       -       -       smtp
showq     unix  n       -       y       -       -       showq
error     unix  -       -       y       -       -       error
retry     unix  -       -       y       -       -       error
discard   unix  -       -       y       -       -       discard
local     unix  -       n       n       -       -       local
virtual   unix  -       n       n       -       -       virtual
lmtp      unix  -       -       y       -       -       lmtp
anvil     unix  -       -       y       -       1       anvil
scache    unix  -       -       y       -       1       scache
maildrop  unix  -       n       n       -       -       pipe
  flags=DRhu user=vmail argv=/usr/bin/maildrop -d ${recipient}


uucp      unix  -       n       n       -       -       pipe
  flags=Fqhu user=uucp argv=uux -r -n -z -a$sender - $nexthop!rmail ($recipient)

ifmail    unix  -       n       n       -       -       pipe
  flags=F user=ftn argv=/usr/lib/ifmail/ifmail -r $nexthop ($recipient)
bsmtp     unix  -       n       n       -       -       pipe
  flags=Fq. user=bsmtp argv=/usr/lib/bsmtp/bsmtp -t$nexthop -f$sender $recipient
scalemail-backend unix	-	n	n	-	2	pipe
  flags=R user=scalemail argv=/usr/lib/scalemail/bin/scalemail-store ${nexthop} ${user} ${extension}
mailman   unix  -       n       n       -       -       pipe
  flags=FR user=list argv=/usr/lib/mailman/bin/postfix-to-mailman.py
  ${nexthop} ${user}
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
ssl_cert = </etc/ssl/certs/abec1e3c92ff8964.crt
ssl_key = </etc/ssl/certs/exmachinis.com.key
```
Note that other Dovecot configuration files also need to be modified as according to https://www.digitalocean.com/community/tutorials/how-to-configure-a-mail-server-using-postfix-dovecot-mysql-and-spamassassin : 10-mail.conf, 10-auth.conf, 10-master.conf and 10-ssl.conf.  

### Creation of email accounts
Two email accounts have to be created: one for registration and a "catchall" account to process mails that go to any name under the same domain.
On Linux, accounts can be created using
```
adduser registrar
```
These accounts' credentials and everything related to mail should be sent on the '.env' file on the Laravel's project root folder:
```
MAIL_DRIVER=mail
MAIL_HOST=exmachinis.com
MAIL_PORT=587
MAIL_USERNAME=administrator
MAIL_PASSWORD=password
MAIL_ENCRYPTION=tls
MAIL_REGISTRATION_ACCOUNT=registrar
MAIL_REGISTRATION_PASSWORD=password
MAIL_CATCHALL_ACCOUNT=catchall
MAIL_CATCHALL_PASSWORD=password
MAIL_INCOMING_PORT=143
```
#### Notes about certificates
Postfix requires the SSL certificate and Intermediate CA need to be in a single file, as stated here https://knowledge.digicert.com/solution/SO13616.html .  
If you install new certificates, you may have to run this command to re-generate the symbolic links:
```
update-ca-certificates --fresh
```

### Game engine setup

External dependencies, we need to install the following libraries/packages required by the game engine (as root user run):

```
apt-get install libconfig-dev libmysqlclient-dev gdb 
```

The game engine modules run under **forth** Linux user. This user belongs to **dev** group. 

```
uid=1006(forth) gid=1006(dev) 
```

The commands to achieve this are (as root user):
```
# addgroup dev
# adduser --ingroup dev -shell /bin/bash forth
```


At user's environment (.profile) we configure the following stuff:

```
# Default Prompt format
PS1="[\u@\h] # "


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

All the game files are placed under **$PLAT_HOME** directory (**/home/forth/game-engine**) using the following structure of directories:

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

At user's cron we have the following tasks programmed (they are just watchdog scripts to restart the suitable module if stopped).
We have one line to restart the email handler and another to restart the game engine (only in case they were stopped)

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

- curl -s : invokes HTTPS request

- https://www.exmachinis.com : domain we want to invoke (will depend on current server name)

- get-mails : is the PHP script in charge of processing the emails using Laravel terminology

- JJy3CC9cUtzsbLsY : It is the key configured at dovecot setup
```


When it receives any email, it checks the destination address and applies the suitable logic depending on this address, updating the Mysql database information.


#### Game engine

It reads MySQL database (periodically) and retrieves emails information stored by email handler module.

It runs the FORTH scripts received by email and updates user VM.


