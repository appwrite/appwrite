FROM alpine:3.14

RUN apk add --no-cache mariadb mariadb-client supervisor

# Set up necessary directories and permissions
RUN mkdir -p /var/lib/mysql /run/mysqld && \
    chown -R mysql:mysql /var/lib/mysql /run/mysqld && \
    chmod 1777 /run/mysqld

# Set up necessary directories for Supervisord
RUN mkdir -p /var/log/supervisor /var/run

# Copy supervisord configuration
COPY supervisord.conf /etc/supervisor/supervisord.conf

# Copy initialization script
COPY init-mariadb.sh /docker-entrypoint-initdb.d/init-mariadb.sh
RUN chmod +x /docker-entrypoint-initdb.d/init-mariadb.sh

EXPOSE 3306

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]