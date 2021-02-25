#!/bin/bash bash

RED='\033[0;31m'
NC='\033[0m' # No Color

if [ -z "$1" ]
then
      echo "Missing tag number"
      exit 1
fi

if [ -z "$2" ]
then
      echo "Missing version number"
      exit 1
fi

if test $(find "./app/db/DBIP/dbip-country-lite-2021-02.mmdb" -mmin +259200)
then
    printf "${RED}GEO country DB has not been updated for more than 6 months. Go to https://db-ip.com/db/download/ip-to-country-lite to download a newer version${NC}\n"
fi

echo 'Starting build...'

docker build --build-arg VERSION="$2" --tag appwrite/appwrite:"$1" .

echo 'Pushing build to registry...'

docker push appwrite/appwrite:"$1"
