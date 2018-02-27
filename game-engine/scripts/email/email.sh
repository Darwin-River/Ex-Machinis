#!/bin/bash

if [ $# -ne 3 ]
then
  exit 10
fi

EMAIL_FROM=$2@exmachinis.com 
EMAIL_TO="$1"
EMAIL_SUBJECT="Command result"
EMAIL_CONTENT=$3

#echo ${EMAIL_FROM}
#echo ${EMAIL_TO}
#echo ${EMAIL_SUBJECT}
#echo ${EMAIL_CONTENT}

  {  
    echo "From: ${EMAIL_FROM}"
    echo "To: ${EMAIL_TO}"
    echo "Subject: ${EMAIL_SUBJECT}"
    echo "Content-Disposition: inline"
    echo 'Content-Type: text/html;boundary=${EMAIL_CONTENT}'
    echo "<HTML><BODY>${EMAIL_CONTENT}</BODY></HTML>"
 } | /usr/lib/sendmail -t
