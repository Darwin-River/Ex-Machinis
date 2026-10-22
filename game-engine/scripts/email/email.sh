#!/bin/bash

if [ $# -ne 4 ]
then
  exit 10
fi

EMAIL_FROM=$3@${MAIL_DOMAIN:-exmachinis.com}
EMAIL_TO="$1"
EMAIL_SUBJECT="Re: "$2
EMAIL_CONTENT=$4

#echo ${EMAIL_FROM}
#echo ${EMAIL_TO}
#echo ${EMAIL_SUBJECT}
#echo ${EMAIL_CONTENT}

  {  
    echo "From: ${EMAIL_FROM}"
    echo "To: ${EMAIL_TO}"
    echo "Subject: ${EMAIL_SUBJECT}"
    echo 'Content-Type: text/plain;charset="utf-8"'
    echo "${EMAIL_CONTENT}"
 } | /usr/lib/sendmail -t -f "${EMAIL_FROM}"
