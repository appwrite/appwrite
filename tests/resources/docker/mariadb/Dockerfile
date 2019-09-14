FROM mariadb:10.2

LABEL maintainer="team@appwrite.io"

# Add appwrite schema and tables
ADD ./all.sql /docker-entrypoint-initdb.d/all.sql

RUN chown -R mysql:mysql /docker-entrypoint-initdb.d/

CMD [ "mysqld", "--innodb-flush-method=fsync" ]