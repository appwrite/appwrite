#!/bin/bash

chown -Rf www-data.www-data /usr/share/nginx/html/

# Function to update the fpm configuration to make the service environment variables available
function setEnvironmentVariable() {
    if [ -z "$2" ]; then
            echo "Environment variable '$1' not set."
            return
    fi

    # Check whether variable already exists
    if grep -q $1 /etc/php/7.2/fpm/pool.d/www.conf; then
        # Reset variable
        sed -i "s/^env\[$1.*/env[$1] = $2/g" /etc/php/7.2/fpm/pool.d/www.conf
    else
        # Add variable
        echo "env[$1] = $2" >> /etc/php/7.2/fpm/pool.d/www.conf
    fi
}

# Grep for variables that look like MySQL (APP_)
for _curVar in `env | grep _APP_ | awk -F = '{print $1}'`;do
    # awk has split them by the equals sign
    # Pass the name and value to our function
    setEnvironmentVariable ${_curVar} ${!_curVar}
done

echo newrelic.license = \"$_APP_NEWRELIC_KEY\" > /etc/php/7.2/fpm/conf.d/newrelic.ini
echo newrelic.license = \"$_APP_NEWRELIC_KEY\" > /etc/php/7.2/cli/conf.d/newrelic.ini

# Start supervisord and services
/usr/bin/supervisord -n -c /etc/supervisord.conf