/**
 * Runtime (not build-time) public config.
 *
 * Historically these values were read via `import.meta.env.VITE_*`, which Vite
 * inlines as string literals at build time. That forces a separate image per
 * environment. Instead we read them at runtime so a single image can be promoted
 * across environments:
 *
 *   - Server (SSR): values come from `process.env` (injected by the container env).
 *   - Client: the SSR shell serializes the config into `window.__APP_CONFIG__`
 *     (see `getRuntimeConfigScript()` rendered in `__root.tsx`), and the client
 *     reads from there.
 *
 * Consumers should read via `getRuntimeConfig()` rather than `import.meta.env`.
 */

export interface RuntimeConfig {
  appwriteEndpoint: string
  consoleProfile: string
  /** HMAC key for the console fingerprint token. Public (ships to the browser). */
  fingerprintKey: string
  growthEndpoint: string
  stripePublishableKey: string
  sentryDsn: string
  instrumentationScriptSrc: string
  plausibleScriptSrc: string
}

const EMPTY_CONFIG: RuntimeConfig = {
  appwriteEndpoint: '',
  consoleProfile: '',
  fingerprintKey: '',
  growthEndpoint: '',
  stripePublishableKey: '',
  sentryDsn: '',
  instrumentationScriptSrc: '',
  plausibleScriptSrc: '',
}

const WINDOW_KEY = '__APP_CONFIG__'

/**
 * Read config on the server from the process environment.
 *
 * `import.meta.env.DEV` is a static build flag, so the dev branch is tree-shaken
 * out of the production server bundle — that keeps any literal `VITE_*` references
 * from being inlined here. The reads use a local variable (`env[...]`), which Vite
 * does NOT statically replace, so values stay truly runtime in production.
 */
function readServerRuntimeConfig(): RuntimeConfig {
  const env: Record<string, string | undefined> = import.meta.env.DEV
    ? (import.meta.env as unknown as Record<string, string | undefined>)
    : process.env

  const read = (key: string): string => (env[key] ?? '').toString().trim()

  return {
    appwriteEndpoint: read('VITE_APPWRITE_ENDPOINT'),
    consoleProfile: read('VITE_CONSOLE_PROFILE'),
    fingerprintKey:
      read('VITE_CONSOLE_FINGERPRINT_KEY') ||
      read('PUBLIC_CONSOLE_FINGERPRINT_KEY'),
    growthEndpoint: read('VITE_GROWTH_ENDPOINT'),
    stripePublishableKey: read('VITE_STRIPE_PUBLISHABLE_KEY'),
    sentryDsn: read('VITE_SENTRY_DSN'),
    instrumentationScriptSrc: read('VITE_INSTRUMENTATION_SCRIPT_SRC'),
    plausibleScriptSrc: read('VITE_PLAUSIBLE_SCRIPT_SRC'),
  }
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
      WINDOW_KEY
    ] as Partial<RuntimeConfig> | undefined
    cached = { ...EMPTY_CONFIG, ...(injected ?? {}) }
  } else {
    cached = readServerRuntimeConfig()
  }
  return cached
}

// <, >, & and the JS line/paragraph separators (U+2028/U+2029) must be escaped
// so the serialized JSON can't break out of the surrounding <script> element.
const SCRIPT_UNSAFE = new RegExp('[<>&\\u2028\\u2029]', 'g')

/**
 * Inline script (rendered by the SSR shell) that publishes the config to the
 * browser before the app bundle runs. Reads via `getRuntimeConfig()` so the
 * string is identical on server and during client hydration (no mismatch):
 * the server serializes process-env values, the client reflects the already-set
 * window value.
 */
export function getRuntimeConfigScript(): string {
  const json = JSON.stringify(getRuntimeConfig()).replace(
    SCRIPT_UNSAFE,
    (ch) => '\\u' + ch.charCodeAt(0).toString(16).padStart(4, '0'),
  )
  return `window.${WINDOW_KEY}=${json}`
}
