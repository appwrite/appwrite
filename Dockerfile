FROM oven/bun:1.3 AS base

WORKDIR /app
COPY package.json package.json
COPY bun.lock bun.lock

FROM base AS build

# Brand constants are identical across environments, so they stay inlined by Vite
# (import.meta.env.VITE_*) at build time. All per-environment public config
# (endpoint, profile, fingerprint key, growth endpoint, Stripe key, Sentry DSN,
# instrumentation/Plausible script srcs) is now supplied at RUNTIME via the
# container env and injected into the browser by runtime-config.ts — so a single
# image can be promoted across environments.
ARG VITE_APPWRITE_PROJECT_ID
ENV VITE_APPWRITE_PROJECT_ID=${VITE_APPWRITE_PROJECT_ID}

ARG VITE_COMPANY_NAME
ENV VITE_COMPANY_NAME=${VITE_COMPANY_NAME}

ARG VITE_CONTACT_SALES_URL
ENV VITE_CONTACT_SALES_URL=${VITE_CONTACT_SALES_URL}

ARG VITE_LEGAL_EMAIL
ENV VITE_LEGAL_EMAIL=${VITE_LEGAL_EMAIL}

RUN bun install --frozen-lockfile
COPY . .
# FOR_SITES=true disables client/server sourcemaps and prerender marketing pages at build time.
RUN --mount=type=secret,id=sentry_auth_token \
    SENTRY_AUTH_TOKEN="$(cat /run/secrets/sentry_auth_token 2>/dev/null || true)" \
    FOR_SITES=true bun run build:node

FROM base AS prod-deps

RUN bun install --frozen-lockfile --production

FROM base AS final

ENV NODE_ENV=production
ENV PORT=3000

# server.ts (Bun.serve) serves dist/client and the dist/server SSR handler
COPY --from=build /app/dist/ dist
COPY --from=prod-deps /app/node_modules/ node_modules
COPY server.ts server.ts
# server.ts imports these modules at runtime; they aren't bundled into dist
COPY src/lib/marketing/prerender-paths.ts src/lib/marketing/prerender-paths.ts
COPY src/lib/runtime-config-shared.ts src/lib/runtime-config-shared.ts

EXPOSE 3000
CMD ["bun", "run", "server.ts"]
