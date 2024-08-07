#!/bin/sh
set -e

# Generate MariaDB configuration
cat << EOF > /etc/my.cnf.d/server.cnf
[mysqld]
bind-address = ${MYSQL_HOST:-0.0.0.0}
port = ${MYSQL_PORT:-3306}
EOF

echo "Generated MariaDB configuration with host ${MYSQL_HOST:-0.0.0.0} and port ${MYSQL_PORT:-3306}"

# Initialize MariaDB data directory
if [ ! -d "/var/lib/mysql/mysql" ]; then
    mysql_install_db --user=mysql --datadir=/var/lib/mysql
fi

# Start MariaDB server
/usr/bin/mysqld --user=mysql --datadir=/var/lib/mysql &

# Wait for MariaDB to start
until mysqladmin ping -h "${MYSQL_HOST:-localhost}" -P "${MYSQL_PORT:-3306}" >/dev/null 2>&1; do
    echo "Waiting for MariaDB to be ready..."
    sleep 1
done

# Initialize database
mysql -h "${MYSQL_HOST:-localhost}" -P "${MYSQL_PORT:-3306}" -u root <<-EOSQL
    SET @@SESSION.SQL_LOG_BIN=0;
    DELETE FROM mysql.user WHERE user NOT IN ('mysql.sys', 'mysqlxsys', 'root') OR host NOT IN ('localhost') ;
    DROP USER IF EXISTS root@'${HOSTNAME}';
    CREATE USER 'root'@'%' IDENTIFIED BY '${MYSQL_ROOT_PASSWORD}' ;
    GRANT ALL ON *.* TO 'root'@'%' WITH GRANT OPTION ;
    DROP DATABASE IF EXISTS test ;
    FLUSH PRIVILEGES ;
    
    -- Create mariadb.sys user and grant permissions
    CREATE USER IF NOT EXISTS 'mariadb.sys'@'localhost' IDENTIFIED BY 'password';
    GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER ON *.* TO 'mariadb.sys'@'localhost';
    FLUSH PRIVILEGES;
EOSQL

if [ ! -z "$MYSQL_DATABASE" ]; then
    mysql -h "${MYSQL_HOST:-localhost}" -P "${MYSQL_PORT:-3306}" -uroot -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
        CREATE DATABASE IF NOT EXISTS \`$MYSQL_DATABASE\` ;
EOSQL
fi

if [ ! -z "$MYSQL_USER" ] && [ ! -z "$MYSQL_PASSWORD" ]; then
    mysql -h "${MYSQL_HOST:-localhost}" -P "${MYSQL_PORT:-3306}" -uroot -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
        CREATE USER '$MYSQL_USER'@'%' IDENTIFIED BY '$MYSQL_PASSWORD' ;
        GRANT ALL ON \`$MYSQL_DATABASE\`.* TO '$MYSQL_USER'@'%' ;
        FLUSH PRIVILEGES ;
EOSQL
fi

# Stop MariaDB server
mysqladmin -h "${MYSQL_HOST:-localhost}" -P "${MYSQL_PORT:-3306}" -uroot -p"${MYSQL_ROOT_PASSWORD}" shutdown

# Start MariaDB server through supervisord
supervisorctl -c /etc/supervisor/supervisord.conf start mariadb-server

# Keep the script running
tail -f /dev/null