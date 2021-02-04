FROM mysql

LABEL maintainer="team@appwrite.io"

# Add appwrite schema and tables
ADD ./all.sql /docker-entrypoint-initdb.d/all.sql

RUN chown -R mysql:mysql /docker-entrypoint-initdb.d/