#!/bin/bash bash

RED='\033[0;31m'
NC='\033[0m' # No Color

echo "Updating git repository"

git fetch origin
git reset --hard origin/master

if test `find "./app/db/GeoLite2/GeoLite2-Country.mmdb" -mmin +259200`
then
    printf "${RED}GEO country DB has not been updated for more than 6 months. Go to https://dev.maxmind.com/geoip/geoip2/geolite2/ for more info${NC}\n"
fi

echo "Setting Version #"

echo -e "<?php\nconst VERSION = '$1';\n\nreturn VERSION;" > app/config/version.php

echo 'Updating PHP dependencies and auto-loading...'

composer update --ignore-platform-reqs --optimize-autoloader --no-dev --no-plugins --no-scripts --prefer-dist

echo 'Starting build...'

docker build -t appwrite/appwrite:$1 .

echo 'Pushing build to registry...'

docker push appwrite/appwrite:$1