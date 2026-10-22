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

# Values come from players: keep the subject to one line, and print with
# printf (echo would treat text like "-n" as an option).
EMAIL_SUBJECT=$(printf '%s' "${EMAIL_SUBJECT}" | tr '\r\n' '  ')
{
    printf 'From: %s\n' "${EMAIL_FROM}"
    printf 'To: %s\n' "${EMAIL_TO}"
    printf 'Subject: %s\n' "${EMAIL_SUBJECT}"
    printf 'Content-Type: text/plain;charset="utf-8"\n\n'
    printf '%s\n' "${EMAIL_CONTENT}"
} | /usr/lib/sendmail -t -f "${EMAIL_FROM}"
