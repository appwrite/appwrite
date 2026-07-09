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

# librsvg (Sharp SVG export) resolves fonts via fontconfig on Linux, not SVG @font-face.
RUN apt-get update \
  && apt-get install -y --no-install-recommends fontconfig fonts-liberation \
  && rm -rf /var/lib/apt/lists/*

COPY --from=build /app/public/fonts-ttf/ /usr/share/fonts/appwrite/
RUN fc-cache -f

# server.ts (Bun.serve) serves dist/client and the dist/server SSR handler
COPY --from=build /app/dist/ dist
COPY --from=prod-deps /app/node_modules/ node_modules
COPY server.ts server.ts

# WORKAROUND: server.ts imports a handful of modules from src/ at runtime that
# aren't bundled into dist (marketing/marketing-build-paths, runtime-config-shared, and
# their transitive imports). Cherry-picking individual files here is fragile —
# every new local import in that tree silently breaks the production image while
# dev/CI stay green. Until server.ts and its runtime deps are bundled into a
# self-contained dist, copy the whole src/ tree so transitive imports resolve.
COPY src/ src/

EXPOSE 3000

# Probe /health via bun — the image ships no curl/wget.
HEALTHCHECK \
  --interval=30s \
  --timeout=5s \
  --retries=3 \
  --start-period=30s \
  CMD bun -e ' \
    fetch(`http://localhost:${process.env.PORT || 3000}/health`) \
      .then((r) => process.exit(r.ok ? 0 : 1)) \
      .catch(() => process.exit(1))'

CMD ["bun", "run", "server.ts"]
