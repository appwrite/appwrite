/**
 * Runtime-config primitives shared by two execution contexts:
 *   - the Vite-bundled app (src/lib/runtime-config.ts), and
 *   - server.ts, which Bun runs RAW (not through Vite).
 *
 * Therefore this module must stay free of any Vite-only constructs
 * (`import.meta.env`, `?url` imports, path aliases). It is pure and depends
 * only on a plain env record, so it behaves identically in both contexts.
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

/**
 * Token emitted into prerendered/SSR HTML in place of the serialized config.
 * server.ts replaces it with the live config at serve time, so the value is
 * stamped by the process that serves the page, not the one that built it.
 */
export const RUNTIME_CONFIG_PLACEHOLDER = '__APPWRITE_RUNTIME_CONFIG__'

export const RUNTIME_CONFIG_WINDOW_KEY = '__APP_CONFIG__'

type EnvRecord = Record<string, string | undefined>

/** Build the config object from a plain env record (process.env or import.meta.env). */
export function readRuntimeConfigFromEnv(env: EnvRecord): RuntimeConfig {
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

// <, >, & and the JS line/paragraph separators (U+2028/U+2029) must be escaped
// so the serialized JSON can't break out of the surrounding <script> element.
const SCRIPT_UNSAFE = new RegExp('[<>&\\u2028\\u2029]', 'g')

/** Serialize a config to a <script>-safe JSON object literal. */
export function serializeRuntimeConfig(config: RuntimeConfig): string {
  return JSON.stringify(config).replace(
    SCRIPT_UNSAFE,
    (ch) => '\\u' + ch.charCodeAt(0).toString(16).padStart(4, '0'),
  )
}
