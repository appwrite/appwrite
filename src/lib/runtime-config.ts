/**
 * Runtime (not build-time) public config - Vite-app side.
 *
 * Values were historically read via `import.meta.env.VITE_*`, which Vite inlines
 * as string literals at build time and forces a separate image per environment.
 * Instead we read them at runtime so a single image can be promoted across
 * environments:
 *
 *   - Server (SSR): values come from `process.env`.
 *   - Client: the SSR shell serializes config into `window.__APP_CONFIG__`
 *     (see `getRuntimeConfigScript()` in `__root.tsx`) and the client reads it.
 *   - Prerendered (FOR_SITES) pages would freeze config at build time, so the
 *     shell emits a placeholder server-side and `server.ts` replaces it from the
 *     live env at serve time (see runtime-config-shared.ts).
 *
 * Bun-safe primitives live in `runtime-config-shared.ts`; this module adds the
 * `import.meta.env`-dependent pieces and is only ever Vite-processed.
 *
 * Consumers should read via `getRuntimeConfig()` rather than `import.meta.env`.
 */

import {
  RUNTIME_CONFIG_PLACEHOLDER,
  RUNTIME_CONFIG_WINDOW_KEY,
  readRuntimeConfigFromEnv,
  serializeRuntimeConfig,
  type RuntimeConfig,
} from '@/lib/runtime-config-shared'

export type { RuntimeConfig }

const EMPTY_CONFIG: RuntimeConfig = {
  appwriteEndpoint: '',
  consoleProfile: '',
  fingerprintKey: '',
  growthEndpoint: '',
  stripePublishableKey: '',
  sentryDsn: '',
  plausibleScriptSrc: '',
  userVerification: '',
  cookieBanner: '',
  blogDrafts: '',
  usageStats: '',
  websiteAccess: '',
}

/**
 * Read config on the server.
 *
 * `import.meta.env.DEV` is a static build flag, so the dev branch is tree-shaken
 * out of the production server bundle - no `VITE_*` literals get inlined here.
 * In dev, Vite populates `import.meta.env` from `.env`; in production we read the
 * live `process.env`.
 */
function readServerRuntimeConfig(): RuntimeConfig {
  const env: Record<string, string | undefined> = import.meta.env.DEV
    ? (import.meta.env as unknown as Record<string, string | undefined>)
    : process.env
  return readRuntimeConfigFromEnv(env)
}

let cached: RuntimeConfig | null = null

/**
 * Universal accessor. On the client returns the SSR-injected `window.__APP_CONFIG__`;
 * during SSR returns the process-env config. Cached after first read (config is
 * constant for the life of a page / server process).
 */
export function getRuntimeConfig(): RuntimeConfig {
  if (cached) return cached
  if (typeof window !== 'undefined') {
    const injected = (window as unknown as Record<string, unknown>)[
      RUNTIME_CONFIG_WINDOW_KEY
    ] as Partial<RuntimeConfig> | undefined
    cached = { ...EMPTY_CONFIG, ...(injected ?? {}) }
  } else {
    cached = readServerRuntimeConfig()
  }
  return cached
}

/**
 * Inline script (rendered by the SSR shell) that publishes the config to the
 * browser before the app bundle runs.
 *
 *   - Dev / client hydration: emit the real values. In dev there's no server.ts
 *     to substitute; on the client we reflect the already-set `window` value so
 *     the markup matches what the server injected (no hydration mismatch).
 *   - Production server (SSR): emit config from the live process env. Appwrite
 *     Sites serves SSR without `server.ts`, so a bare placeholder would crash.
 *   - Production prerender: emit a placeholder. `server.ts` (K8s) or the
 *     runtime-config middleware (Sites SSR fallback) replaces it at serve time.
 */
export function getRuntimeConfigScript(): string {
  if (typeof window !== 'undefined') {
    return `window.${RUNTIME_CONFIG_WINDOW_KEY}=${serializeRuntimeConfig(getRuntimeConfig())}`
  }
  if (import.meta.env.DEV) {
    return `window.${RUNTIME_CONFIG_WINDOW_KEY}=${serializeRuntimeConfig(readServerRuntimeConfig())}`
  }
  if (process.env.TSS_PRERENDERING === 'true') {
    return `window.${RUNTIME_CONFIG_WINDOW_KEY}=${RUNTIME_CONFIG_PLACEHOLDER}`
  }
  return `window.${RUNTIME_CONFIG_WINDOW_KEY}=${serializeRuntimeConfig(readServerRuntimeConfig())}`
}
