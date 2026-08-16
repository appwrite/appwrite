import type { Page } from '@playwright/test'

/**
 * Debug localStorage key used by `src/lib/console-profiles.ts`.
 * A stored profile merges feature overrides on top of the canonical cloud profile.
 */
const DEBUG_PROFILE_KEY = 'debug:consoleProfile'

/** Feature flags required for dedicated / native MySQL UI against Cloud. */
export const MYSQL_E2E_FEATURE_OVERRIDES = {
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

/**
 * Force MySQL / dedicated-DB feature flags on for the current page origin.
 * Must run before navigating to console routes that gate on these flags.
 */
export async function enableMysqlFeatureFlags(page: Page): Promise<void> {
  await page.addInitScript(
    ({ key, features }) => {
      const payload = {
        id: 'cloud',
        label: 'Cloud',
        description: 'E2E override - MySQL feature flags enabled',
        features,
      }
      try {
        window.localStorage.setItem(key, JSON.stringify(payload))
      } catch {
        // Ignore quota / private-mode failures; cloud profile defaults still apply.
      }
    },
    { key: DEBUG_PROFILE_KEY, features: MYSQL_E2E_FEATURE_OVERRIDES },
  )

  // Also set on the current document if a page is already open.
  await page
    .evaluate(
      ({ key, features }) => {
        const payload = {
          id: 'cloud',
          label: 'Cloud',
          description: 'E2E override - MySQL feature flags enabled',
          features,
        }
        window.localStorage.setItem(key, JSON.stringify(payload))
      },
      { key: DEBUG_PROFILE_KEY, features: MYSQL_E2E_FEATURE_OVERRIDES },
    )
    .catch(() => {
      // No document yet (about:blank) - init script covers the next navigation.
    })
}
