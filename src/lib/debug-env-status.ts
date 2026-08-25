/**
 * Presence-only env status for the debug menu.
 * Never returns or exposes env values - only whether each key is set.
 */

import { getRuntimeConfig } from '@/lib/runtime-config'

export type DebugEnvGroup =
  | 'Runtime'
  | 'Threads'
  | 'Init ticket storage'
  | 'Other'

export type DebugEnvEntry = {
  /** Primary env key shown in the UI. */
  key: string
  /** Optional aliases that also count as set (shown as a hint only). */
  aliases?: readonly string[]
  group: DebugEnvGroup
  description?: string
}

export type DebugEnvStatus = DebugEnvEntry & {
  set: boolean
}

function isNonEmpty(value: unknown): boolean {
  return typeof value === 'string' && value.trim().length > 0
}

/**
 * Known client-facing env keys. Values are read via static property access
 * (Vite-safe) or runtime config; never returned from these helpers.
 */
export const DEBUG_ENV_CATALOG: readonly DebugEnvEntry[] = [
  {
    key: 'VITE_APPWRITE_ENDPOINT',
    aliases: ['APPWRITE_ENDPOINT', 'PUBLIC_APPWRITE_ENDPOINT'],
    group: 'Runtime',
    description: 'Appwrite API endpoint',
  },
  {
    key: 'VITE_CONSOLE_PROFILE',
    group: 'Runtime',
    description: 'Console profile (cloud / self-hosted / native)',
  },
  {
    key: 'VITE_CONSOLE_FINGERPRINT_KEY',
    aliases: ['PUBLIC_CONSOLE_FINGERPRINT_KEY'],
    group: 'Runtime',
    description: 'Console fingerprint HMAC key',
  },
  {
    key: 'VITE_GROWTH_ENDPOINT',
    group: 'Runtime',
    description: 'Growth / feedback / support API',
  },
  {
    key: 'VITE_STRIPE_PUBLISHABLE_KEY',
    group: 'Runtime',
    description: 'Stripe publishable key',
  },
  {
    key: 'VITE_SENTRY_DSN',
    group: 'Runtime',
    description: 'Sentry error reporting',
  },
  {
    key: 'VITE_PLAUSIBLE_SCRIPT_SRC',
    group: 'Runtime',
    description:
      'Upstream Plausible script URL (proxied via /r/v.js and /r/e)',
  },
  {
    key: 'VITE_CONSOLE_USER_VERIFICATION',
    group: 'Runtime',
    description: 'Override post-signup email verification',
  },
  {
    key: 'VITE_CONSOLE_COOKIE_BANNER',
    group: 'Runtime',
    description: 'Override cookie consent banner',
  },
  {
    key: 'VITE_CONSOLE_BLOG_DRAFTS',
    group: 'Runtime',
    description: 'Override draft blog post visibility',
  },
  {
    key: 'VITE_CONSOLE_WEBSITE_ACCESS',
    group: 'Runtime',
    description: 'Override demo / soft-launch website password gate',
  },
  {
    key: 'VITE_CONSTRUCTION',
    group: 'Other',
    description: 'Vite DEV header construction bar (false/0/off to hide; unset = on)',
  },
  {
    key: 'VITE_THREADS_APPWRITE_ENDPOINT',
    group: 'Threads',
    description: 'Threads Appwrite endpoint',
  },
  {
    key: 'VITE_THREADS_APPWRITE_PROJECT_ID',
    group: 'Threads',
    description: 'Threads project ID',
  },
  {
    key: 'VITE_THREADS_DB_ID',
    group: 'Threads',
    description: 'Threads database ID',
  },
  {
    key: 'VITE_THREADS_COL_THREADS_ID',
    group: 'Threads',
    description: 'Threads collection ID',
  },
  {
    key: 'VITE_THREADS_COL_MESSAGES_ID',
    group: 'Threads',
    description: 'Messages collection ID',
  },
  {
    key: 'VITE_THREADS_COL_AUTHORS_ID',
    group: 'Threads',
    description: 'Authors collection ID',
  },
  {
    key: 'VITE_INIT_TICKET_STORAGE_ENDPOINT',
    group: 'Init ticket storage',
    description: 'Init ticket storage endpoint',
  },
  {
    key: 'VITE_INIT_TICKET_STORAGE_PROJECT_ID',
    group: 'Init ticket storage',
    description: 'Init ticket storage project ID',
  },
  {
    key: 'VITE_INIT_TICKET_STORAGE_BUCKET_ID',
    group: 'Init ticket storage',
    description: 'Init ticket storage bucket ID',
  },
  {
    key: 'VITE_CONTACT_SALES_URL',
    group: 'Other',
    description: 'Contact sales URL override',
  },
  {
    key: 'VITE_COMPANY_NAME',
    group: 'Other',
    description: 'Company name override',
  },
  {
    key: 'VITE_LEGAL_EMAIL',
    group: 'Other',
    description: 'Legal contact email override',
  },
  {
    key: 'VITE_APPWRITE_MCP_URL',
    group: 'Other',
    description: 'Appwrite MCP endpoint (assistant + OAuth resource)',
  },
  {
    key: 'VITE_APPWRITE_AGENT_OAUTH_CLIENT_ID',
    group: 'Other',
    description: 'Pre-registered OAuth client id for Agent MCP connect',
  },
  {
    key: 'VITE_SITE_ORIGIN',
    group: 'Other',
    description: 'Site origin (sitemap / absolute URLs)',
  },
] as const

/** Static presence map for build-time `import.meta.env` keys (Vite-safe). */
function readBuildTimePresence(): Record<string, boolean> {
  return {
    VITE_THREADS_APPWRITE_ENDPOINT: isNonEmpty(
      import.meta.env.VITE_THREADS_APPWRITE_ENDPOINT,
    ),
    VITE_THREADS_APPWRITE_PROJECT_ID: isNonEmpty(
      import.meta.env.VITE_THREADS_APPWRITE_PROJECT_ID,
    ),
    VITE_THREADS_DB_ID: isNonEmpty(import.meta.env.VITE_THREADS_DB_ID),
    VITE_THREADS_COL_THREADS_ID: isNonEmpty(
      import.meta.env.VITE_THREADS_COL_THREADS_ID,
    ),
    VITE_THREADS_COL_MESSAGES_ID: isNonEmpty(
      import.meta.env.VITE_THREADS_COL_MESSAGES_ID,
    ),
    VITE_THREADS_COL_AUTHORS_ID: isNonEmpty(
      import.meta.env.VITE_THREADS_COL_AUTHORS_ID,
    ),
    VITE_INIT_TICKET_STORAGE_ENDPOINT: isNonEmpty(
      import.meta.env.VITE_INIT_TICKET_STORAGE_ENDPOINT,
    ),
    VITE_INIT_TICKET_STORAGE_PROJECT_ID: isNonEmpty(
      import.meta.env.VITE_INIT_TICKET_STORAGE_PROJECT_ID,
    ),
    VITE_INIT_TICKET_STORAGE_BUCKET_ID: isNonEmpty(
      import.meta.env.VITE_INIT_TICKET_STORAGE_BUCKET_ID,
    ),
    VITE_CONTACT_SALES_URL: isNonEmpty(import.meta.env.VITE_CONTACT_SALES_URL),
    VITE_COMPANY_NAME: isNonEmpty(import.meta.env.VITE_COMPANY_NAME),
    VITE_LEGAL_EMAIL: isNonEmpty(import.meta.env.VITE_LEGAL_EMAIL),
    VITE_APPWRITE_MCP_URL: isNonEmpty(import.meta.env.VITE_APPWRITE_MCP_URL),
    VITE_APPWRITE_AGENT_OAUTH_CLIENT_ID: isNonEmpty(
      import.meta.env.VITE_APPWRITE_AGENT_OAUTH_CLIENT_ID,
    ),
    VITE_SITE_ORIGIN: isNonEmpty(import.meta.env.VITE_SITE_ORIGIN),
    VITE_CONSTRUCTION: isNonEmpty(import.meta.env.VITE_CONSTRUCTION),
  }
}

function readRuntimePresence(): Record<string, boolean> {
  const config = getRuntimeConfig()
  return {
    VITE_APPWRITE_ENDPOINT: isNonEmpty(config.appwriteEndpoint),
    VITE_CONSOLE_PROFILE: isNonEmpty(config.consoleProfile),
    VITE_CONSOLE_FINGERPRINT_KEY: isNonEmpty(config.fingerprintKey),
    VITE_GROWTH_ENDPOINT: isNonEmpty(config.growthEndpoint),
    VITE_STRIPE_PUBLISHABLE_KEY: isNonEmpty(config.stripePublishableKey),
    VITE_SENTRY_DSN: isNonEmpty(config.sentryDsn),
    VITE_PLAUSIBLE_SCRIPT_SRC: isNonEmpty(config.plausibleScriptSrc),
    VITE_CONSOLE_USER_VERIFICATION: isNonEmpty(config.userVerification),
    VITE_CONSOLE_COOKIE_BANNER: isNonEmpty(config.cookieBanner),
    VITE_CONSOLE_BLOG_DRAFTS: isNonEmpty(config.blogDrafts),
    VITE_CONSOLE_WEBSITE_ACCESS: isNonEmpty(config.websiteAccess),
  }
}

/** Returns set/unset for each catalog entry. Never includes values. */
export function getDebugEnvStatuses(): DebugEnvStatus[] {
  const presence = {
    ...readRuntimePresence(),
    ...readBuildTimePresence(),
  }

  return DEBUG_ENV_CATALOG.map((entry) => ({
    ...entry,
    set: Boolean(presence[entry.key]),
  }))
}

export function summarizeDebugEnvStatuses(statuses: DebugEnvStatus[]): {
  setCount: number
  unsetCount: number
  total: number
} {
  const setCount = statuses.filter((s) => s.set).length
  return {
    setCount,
    unsetCount: statuses.length - setCount,
    total: statuses.length,
  }
}
