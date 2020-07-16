FROM ruby:2.7-alpine

LABEL maintainer="team@appwrite.io"

RUN apk add tar

RUN mkdir /usr/local/src

WORKDIR /usr/local/src/

ENV GEM_PATH=/usr/local/src/gems/2.1.0
ENV GEM_SPEC_CACHE=/usr/local/src/gems/specs