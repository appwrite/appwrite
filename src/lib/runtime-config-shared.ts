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
  plausibleScriptSrc: string
  /** Override for the profile's userVerification feature ('' = profile default). */
  userVerification: string
  /** Override for the profile's cookieBanner feature ('' = profile default). */
  cookieBanner: string
  /** Override for the profile's blogDrafts feature ('' = profile default). */
  blogDrafts: string
  /** Override for backend-powered usage statistics ('' = Console variables). */
  usageStats: string
  /**
   * Demo / soft-launch website password gate (`/access`, Appwrite2 cookie).
   * '' = enabled; `false` / `0` / `disabled` turns the gate off.
   */
  websiteAccess: string
}

/**
 * Token emitted into prerendered/SSR HTML in place of the serialized config.
 * server.ts replaces it with the live config at serve time, so the value is
 * stamped by the process that serves the page, not the one that built it.
 */
export const RUNTIME_CONFIG_PLACEHOLDER = '__APPWRITE_RUNTIME_CONFIG__'

export const RUNTIME_CONFIG_WINDOW_KEY = '__APP_CONFIG__'

/** Default Appwrite Cloud API endpoint when no env override is configured. */
export const DEFAULT_CLOUD_APPWRITE_ENDPOINT = 'https://cloud.appwrite.io/v1'

type EnvRecord = Record<string, string | undefined>

function readEnvValue(env: EnvRecord, key: string): string {
  return (env[key] ?? '').toString().trim()
}

export function isSelfHostedConsoleProfile(consoleProfile: string): boolean {
  return (
    consoleProfile.toLowerCase().trim().replace(/\s+/g, '-') === 'self-hosted'
  )
}

export function shouldWarnAboutMissingAppwriteEndpoint(
  config: Pick<RuntimeConfig, 'appwriteEndpoint' | 'consoleProfile'>,
  sameOriginFallback: string,
): boolean {
  return (
    !config.appwriteEndpoint &&
    !(
      isSelfHostedConsoleProfile(config.consoleProfile) &&
      ['1', 'true'].includes(sameOriginFallback.toLowerCase().trim())
    )
  )
}

/**
 * Read the Appwrite API endpoint from env. Accepts VITE_APPWRITE_ENDPOINT (primary),
 * APPWRITE_ENDPOINT, and PUBLIC_APPWRITE_ENDPOINT so Helm/runtime configs that use
 * the server-side name still reach the browser.
 */
export function readAppwriteEndpointFromEnv(env: EnvRecord): string {
  return (
    readEnvValue(env, 'VITE_APPWRITE_ENDPOINT') ||
    readEnvValue(env, 'APPWRITE_ENDPOINT') ||
    readEnvValue(env, 'PUBLIC_APPWRITE_ENDPOINT')
  )
}

/**
 * Fallback when no endpoint env var is set. Self-hosted consoles use the current
 * host (same-origin API). Cloud consoles must not use the frontend host (e.g.
 * vibes.appwrite.io) as the API endpoint.
 */
export function resolveAppwriteEndpointFallback(
  consoleProfile: string,
  location?: { protocol: string; host: string },
): string {
  if (isSelfHostedConsoleProfile(consoleProfile) && location) {
    return `${location.protocol}//${location.host}/v1`
  }
  return DEFAULT_CLOUD_APPWRITE_ENDPOINT
}

/** Build the config object from a plain env record (process.env or import.meta.env). */
export function readRuntimeConfigFromEnv(env: EnvRecord): RuntimeConfig {
  const read = (key: string) => readEnvValue(env, key)
  return {
    appwriteEndpoint: readAppwriteEndpointFromEnv(env),
    consoleProfile: read('VITE_CONSOLE_PROFILE'),
    fingerprintKey:
      read('VITE_CONSOLE_FINGERPRINT_KEY') ||
      read('PUBLIC_CONSOLE_FINGERPRINT_KEY'),
    growthEndpoint: read('VITE_GROWTH_ENDPOINT'),
    stripePublishableKey: read('VITE_STRIPE_PUBLISHABLE_KEY'),
    sentryDsn: read('VITE_SENTRY_DSN'),
    plausibleScriptSrc: read('VITE_PLAUSIBLE_SCRIPT_SRC'),
    userVerification: read('VITE_CONSOLE_USER_VERIFICATION'),
    cookieBanner: read('VITE_CONSOLE_COOKIE_BANNER'),
    blogDrafts: read('VITE_CONSOLE_BLOG_DRAFTS'),
    usageStats: read('VITE_CONSOLE_USAGE_STATS'),
    websiteAccess: read('VITE_CONSOLE_WEBSITE_ACCESS'),
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

const RUNTIME_CONFIG_SCRIPT_ASSIGNMENT = /window\.__APP_CONFIG__=[^;]*;/g

/** Stamp live runtime config into HTML (SSR, prerender, or static). */
export function injectRuntimeConfigIntoHtml(
  html: string,
  configJson: string,
): string {
  if (html.includes(RUNTIME_CONFIG_PLACEHOLDER)) {
    return html.split(RUNTIME_CONFIG_PLACEHOLDER).join(configJson)
  }

  if (!html.includes('window.__APP_CONFIG__=')) {
    return html
  }

  return html.replace(
    RUNTIME_CONFIG_SCRIPT_ASSIGNMENT,
    `window.__APP_CONFIG__=${configJson};`,
  )
}
