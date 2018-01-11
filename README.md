# Ex-machinis

### Requirements

Ex-Machinis works on an Apache server that supports PHP (at least 7.0 version) and a MySQL database.
The mail server works on Linux using Postfix as MTA and Dovecot as e-mail retrieval service.
A SSL certificate is required for a public release.

### Installation

Copy all files and folders to a directory where you want the project to be placed, then set in Apache's httpd.conf the "html" folder as web root (this guide explains it for Ubuntu <a href="https://www.digitalocean.com/community/tutorials/how-to-move-an-apache-web-root-to-a-new-location-on-ubuntu-16-04">https://www.digitalocean.com/community/tutorials/how-to-move-an-apache-web-root-to-a-new-location-on-ubuntu-16-04</a> ) . If the folders structure is changed, you may have to edit index.php on the "html" folder to set the paths.
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
A cron job needs to be created to execute incoming mails processing. First set the CRON_KEY on the .env configuration file. Then you can edit the cron table on Linux with the command "crontab -e". To make the process run once every five minutes (replace "CRON_KEY" with the key code):
```
*/5 * * * * wget -O /dev/null -o /dev/null  https://www.exmachinis.com/get-mail/{CRON_KEY}
```




