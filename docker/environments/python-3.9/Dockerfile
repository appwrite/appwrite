FROM python:3.9-alpine

LABEL maintainer="team@appwrite.io"

RUN apk add tar

RUN mkdir /usr/local/src

WORKDIR /usr/local/src/

ENV PYTHONPATH "${PYTHONPATH}:/usr/local/src/.appwrite"