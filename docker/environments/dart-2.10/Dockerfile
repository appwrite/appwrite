FROM google/dart:2.10

LABEL maintainer="team@appwrite.io"

RUN apt-get update -y && apt-get install -y tar

RUN mkdir /usr/local/src

WORKDIR /usr/local/src/

ENV PUB_CACHE=/usr/local/src/.appwrite