#!/bin/bash

if [ $1 ]
then
   mysql -u root exmachinis -p < $1
else
   echo "Invalid input file"
fi
