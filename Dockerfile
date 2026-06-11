FROM oven/bun:1.3 AS base

WORKDIR /app
COPY package.json package.json
COPY bun.lock bun.lock

FROM base AS build

# Build-time public config inlined by Vite (import.meta.env.VITE_*)
ARG VITE_APPWRITE_ENDPOINT
ENV VITE_APPWRITE_ENDPOINT=${VITE_APPWRITE_ENDPOINT}

ARG VITE_APPWRITE_PROJECT_ID
ENV VITE_APPWRITE_PROJECT_ID=${VITE_APPWRITE_PROJECT_ID}

ARG VITE_STRIPE_PUBLISHABLE_KEY
ENV VITE_STRIPE_PUBLISHABLE_KEY=${VITE_STRIPE_PUBLISHABLE_KEY}

ARG VITE_GROWTH_ENDPOINT
ENV VITE_GROWTH_ENDPOINT=${VITE_GROWTH_ENDPOINT}

ARG VITE_COMPANY_NAME
ENV VITE_COMPANY_NAME=${VITE_COMPANY_NAME}

ARG VITE_CONTACT_SALES_URL
ENV VITE_CONTACT_SALES_URL=${VITE_CONTACT_SALES_URL}

ARG VITE_LEGAL_EMAIL
ENV VITE_LEGAL_EMAIL=${VITE_LEGAL_EMAIL}

ARG VITE_INSTRUMENTATION_SCRIPT_SRC
ENV VITE_INSTRUMENTATION_SCRIPT_SRC=${VITE_INSTRUMENTATION_SCRIPT_SRC}

ARG VITE_PLAUSIBLE_SCRIPT_SRC
ENV VITE_PLAUSIBLE_SCRIPT_SRC=${VITE_PLAUSIBLE_SCRIPT_SRC}

ARG VITE_SENTRY_DSN
ENV VITE_SENTRY_DSN=${VITE_SENTRY_DSN}

RUN bun install --frozen-lockfile
COPY . .
# SENTRY_AUTH_TOKEN is mounted as a BuildKit secret (never baked into a layer) and
# exposed in the environment only for this build step, to enable Sentry sourcemap
# upload (see vite.config.ts). Absent secret -> empty token -> upload is skipped.
RUN --mount=type=secret,id=sentry_auth_token \
    SENTRY_AUTH_TOKEN="$(cat /run/secrets/sentry_auth_token 2>/dev/null || true)" \
    bun run build

FROM base AS prod-deps

RUN bun install --frozen-lockfile --production

FROM base AS final

ENV NODE_ENV=production
ENV PORT=3000

# server.ts (Bun.serve) serves dist/client and the dist/server SSR handler
COPY --from=build /app/dist/ dist
COPY --from=prod-deps /app/node_modules/ node_modules
COPY server.ts server.ts

EXPOSE 3000
CMD ["bun", "run", "server.ts"]
