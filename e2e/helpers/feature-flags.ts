import type { Page } from '@playwright/test'

/**
 * Debug localStorage key used by `src/lib/console-profiles.ts`.
 * A stored profile merges feature overrides on top of the canonical cloud profile.
 */
const DEBUG_PROFILE_KEY = 'debug:consoleProfile'

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

/** @deprecated Use DATABASE_E2E_FEATURE_OVERRIDES */
export const MYSQL_E2E_FEATURE_OVERRIDES = DATABASE_E2E_FEATURE_OVERRIDES

/**
 * Force database feature flags on for the current page origin.
 * Must run before navigating to console routes that gate on these flags.
 */
export async function enableDatabaseFeatureFlags(page: Page): Promise<void> {
  await page.addInitScript(
    ({ key, features }) => {
      const payload = {
        id: 'cloud',
        label: 'Cloud',
        description: 'E2E override - database feature flags enabled',
        features,
      }
      try {
        window.localStorage.setItem(key, JSON.stringify(payload))
      } catch {
        // Ignore quota / private-mode failures; cloud profile defaults still apply.
      }
    },
    { key: DEBUG_PROFILE_KEY, features: DATABASE_E2E_FEATURE_OVERRIDES },
  )

  // Also set on the current document if a page is already open.
  await page
    .evaluate(
      ({ key, features }) => {
        const payload = {
          id: 'cloud',
          label: 'Cloud',
          description: 'E2E override - database feature flags enabled',
          features,
        }
        window.localStorage.setItem(key, JSON.stringify(payload))
      },
      { key: DEBUG_PROFILE_KEY, features: DATABASE_E2E_FEATURE_OVERRIDES },
    )
    .catch(() => {
      // No document yet (about:blank) - init script covers the next navigation.
    })
}

/** @deprecated Use enableDatabaseFeatureFlags */
export const enableMysqlFeatureFlags = enableDatabaseFeatureFlags
