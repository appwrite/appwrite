import type { BrowserContext, Page } from '@playwright/test'
import {
  COOKIE_CONSENT_STORAGE_KEY,
  COOKIE_CONSENT_VERSION,
} from '../../src/lib/cookie-consent/constants'

const ACCEPT_ALL = 'Accept all'
const seededContexts = new WeakSet<BrowserContext>()

function acceptAllButton(page: Page) {
  return page
    .getByRole('dialog')
    .filter({ has: page.locator('#cookie-consent-title') })
    .getByRole('button', { name: ACCEPT_ALL, exact: true })
}

/**
 * Persist "Accept all" before page scripts run so the GDPR banner never
 * overlays the app. Tests still click the button if it appears anyway.
 */
export async function seedAcceptedCookieConsent(
  context: BrowserContext,
): Promise<void> {
  if (seededContexts.has(context)) return
  seededContexts.add(context)

  await context.addInitScript(
    ({ key, version }) => {
      try {
        const existing = window.localStorage.getItem(key)
        if (existing) {
          const parsed = JSON.parse(existing) as { version?: number }
          if (parsed?.version === version) return
        }
        window.localStorage.setItem(
          key,
          JSON.stringify({
            version,
            analytics: true,
            updatedAt: new Date().toISOString(),
          }),
        )
      } catch {
        // Private mode / blocked storage.
      }
    },
    { key: COOKIE_CONSENT_STORAGE_KEY, version: COOKIE_CONSENT_VERSION },
  )
}

/**
 * If the GDPR cookie banner is on screen, click "Accept all" and wait for it
 * to close. No-op when the banner is absent (wrong locale, already accepted,
 * or feature flag off).
 */
export async function acceptCookieBannerIfPresent(page: Page): Promise<void> {
  await seedAcceptedCookieConsent(page.context())

  const button = acceptAllButton(page)
  if (!(await button.isVisible().catch(() => false))) return

  await button.click({ timeout: 5_000 })
  await button.waitFor({ state: 'hidden', timeout: 5_000 }).catch(() => undefined)
}

export async function newE2ePage(context: BrowserContext): Promise<Page> {
  await seedAcceptedCookieConsent(context)
  return context.newPage()
}
