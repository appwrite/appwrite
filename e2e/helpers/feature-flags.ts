import type { BrowserContext, Page } from '@playwright/test'

/**
 * Debug localStorage key used by `src/lib/console-profiles.ts`.
 * A stored profile merges feature overrides on top of the canonical cloud profile.
 */
const DEBUG_PROFILE_KEY = 'debug:consoleProfile'
const DEBUG_PROFILE_COOKIE = 'debug_console_profile'
const DEBUG_PROFILE_COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24 * 365

/** Feature flags required for dedicated / native / product database UI against Cloud. */
export const DATABASE_E2E_FEATURE_OVERRIDES = {
  dedicatedDbsSupport: true,
  dedicatedDbsDocumentsDB: true,
  dedicatedDbsVectorsDB: true,
  nativeDbsPostgres: true,
  nativeDbsMySQL: true,
  nativeDbsMongo: true,
  databaseBackups: true,
  billing: true,
  multiRegion: true,
} as const

const DATABASE_E2E_DEBUG_PROFILE = {
  id: 'cloud' as const,
  label: 'Cloud',
  description: 'E2E override - database feature flags enabled',
  features: DATABASE_E2E_FEATURE_OVERRIDES,
}

/** @deprecated Use DATABASE_E2E_FEATURE_OVERRIDES */
export const MYSQL_E2E_FEATURE_OVERRIDES = DATABASE_E2E_FEATURE_OVERRIDES

const seededContexts = new WeakSet<BrowserContext>()

function persistDatabaseFeatureFlagsInPage(args: {
  key: string
  cookieName: string
  maxAge: number
  profile: typeof DATABASE_E2E_DEBUG_PROFILE
}) {
  const { key, cookieName, maxAge, profile } = args
  try {
    window.localStorage.setItem(key, JSON.stringify(profile))
  } catch {
    // Ignore quota / private-mode failures; cloud profile defaults still apply.
  }
  try {
    const payload = encodeURIComponent(
      JSON.stringify({ id: profile.id, features: profile.features }),
    )
    const secure = window.location.protocol === 'https:' ? '; Secure' : ''
    document.cookie = `${cookieName}=${payload}; path=/; max-age=${maxAge}; SameSite=Lax${secure}`
  } catch {
    // Cookie writes can fail on about:blank before the first app navigation.
  }
}

/**
 * Persist database feature flags for every document in this context.
 * Must run before the first console navigation so wizard cards are not
 * "coming soon" (DocumentsDB / VectorsDB are off in the canonical cloud profile).
 */
export async function seedDatabaseFeatureFlags(
  context: BrowserContext,
): Promise<void> {
  if (seededContexts.has(context)) return
  seededContexts.add(context)

  await context.addInitScript(persistDatabaseFeatureFlagsInPage, {
    key: DEBUG_PROFILE_KEY,
    cookieName: DEBUG_PROFILE_COOKIE,
    maxAge: DEBUG_PROFILE_COOKIE_MAX_AGE_SECONDS,
    profile: DATABASE_E2E_DEBUG_PROFILE,
  })
}

/**
 * Force database feature flags on for the current page origin.
 * Seeds the browser context (all future navigations) and writes storage on
 * the current document when one is already open.
 */
export async function enableDatabaseFeatureFlags(page: Page): Promise<void> {
  await seedDatabaseFeatureFlags(page.context())

  await page
    .evaluate(persistDatabaseFeatureFlagsInPage, {
      key: DEBUG_PROFILE_KEY,
      cookieName: DEBUG_PROFILE_COOKIE,
      maxAge: DEBUG_PROFILE_COOKIE_MAX_AGE_SECONDS,
      profile: DATABASE_E2E_DEBUG_PROFILE,
    })
    .catch(() => {
      // No document yet (about:blank) - init script covers the next navigation.
    })

  await page
    .evaluate(() => {
      window.dispatchEvent(new CustomEvent('consoleProfileChange'))
    })
    .catch(() => undefined)
}

/** @deprecated Use enableDatabaseFeatureFlags */
export const enableMysqlFeatureFlags = enableDatabaseFeatureFlags
