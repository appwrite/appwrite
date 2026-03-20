ARG POSTGRES_VERSION=17
FROM postgres:${POSTGRES_VERSION}

ARG POSTGRES_VERSION=17

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        postgresql-${POSTGRES_VERSION}-postgis-3 \
        postgresql-${POSTGRES_VERSION}-postgis-3-scripts \
        postgresql-${POSTGRES_VERSION}-pgvector \
    && rm -rf /var/lib/apt/lists/*
