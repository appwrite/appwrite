import type { BrowserContext, Page } from '@playwright/test'
import {
  COOKIE_CONSENT_STORAGE_KEY,
  COOKIE_CONSENT_VERSION,
} from '../../src/lib/cookie-consent/constants'

const ACCEPT_ALL = 'Accept all'
const SKIP_COMMUNITY_SUPPORT = /Skip for now/i
const seededContexts = new WeakSet<BrowserContext>()
const pagesWithOverlayHandler = new WeakSet<Page>()

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

function communitySupportSkipButton(page: Page) {
  return page.getByRole('button', { name: SKIP_COMMUNITY_SUPPORT }).first()
}

async function clickCommunitySupportSkip(skip: {
  click: (options?: { timeout?: number }) => Promise<void>
  evaluate: (fn: (el: HTMLElement) => void) => Promise<void>
  waitFor: (options: { state: 'hidden'; timeout: number }) => Promise<void>
}): Promise<void> {
  await skip.click({ timeout: 5_000 }).catch(async () => {
    await skip.evaluate((el: HTMLElement) => el.click())
  })
  await skip.waitFor({ state: 'hidden', timeout: 8_000 }).catch(() => undefined)
}

/**
 * Auto-dismiss the fullscreen "A note from the team" wizard whenever it
 * appears, the same way cookie consent is seeded so it never blocks clicks.
 */
export async function installCommunitySupportWizardHandler(
  page: Page,
): Promise<void> {
  if (pagesWithOverlayHandler.has(page)) return
  pagesWithOverlayHandler.add(page)

  await page.addLocatorHandler(communitySupportSkipButton(page), async (skip) => {
    await clickCommunitySupportSkip(skip)
  })
}

/**
 * If the community-support wizard is on screen, click "Skip for now".
 * Pass `waitMs` after login / first console navigation: prefs load async.
 */
export async function skipCommunitySupportWizardIfPresent(
  page: Page,
  options?: { waitMs?: number },
): Promise<void> {
  await installCommunitySupportWizardHandler(page)

  const skip = communitySupportSkipButton(page)
  const waitMs = options?.waitMs ?? 0
  if (waitMs > 0) {
    const appeared = await skip
      .waitFor({ state: 'visible', timeout: waitMs })
      .then(() => true)
      .catch(() => false)
    if (!appeared) return
  } else if (!(await skip.isVisible().catch(() => false))) {
    return
  }

  await clickCommunitySupportSkip(skip)
}

/**
 * If the GDPR cookie banner is on screen, click "Accept all" and wait for it
 * to close. No-op when the banner is absent (wrong locale, already accepted,
 * or feature flag off).
 */
export async function acceptCookieBannerIfPresent(page: Page): Promise<void> {
  await seedAcceptedCookieConsent(page.context())
  await installCommunitySupportWizardHandler(page)

  const button = acceptAllButton(page)
  if (await button.isVisible().catch(() => false)) {
    await button.click({ timeout: 5_000 })
    await button
      .waitFor({ state: 'hidden', timeout: 5_000 })
      .catch(() => undefined)
  }

  await skipCommunitySupportWizardIfPresent(page)
}

export async function newE2ePage(context: BrowserContext): Promise<Page> {
  await seedAcceptedCookieConsent(context)
  const page = await context.newPage()
  await installCommunitySupportWizardHandler(page)
  return page
}
