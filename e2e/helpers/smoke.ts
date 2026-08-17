import { expect, type Page } from '@playwright/test'
import { acceptCookieBannerIfPresent } from './cookie-banner'

const FATAL_PAGE_COPY = [
  /Something went wrong/i,
  /Unexpected Application Error/i,
  /This page could not be found/i,
]

/** Headless Chromium often cannot create WebGL; marketing pages still render. */
const IGNORED_PAGE_ERRORS = [
  /Error creating WebGL context/i,
  /Could not create a WebGL context/i,
  /WebGLRenderingContext/i,
]

function isIgnoredPageError(message: string) {
  return IGNORED_PAGE_ERRORS.some((pattern) => pattern.test(message))
}

/**
 * Navigate to a path and assert the page rendered without fatal UI / JS errors.
 * Read-only: never clicks create/update/delete controls.
 */
export async function expectPageRenders(
  page: Page,
  path: string,
  options?: {
    /** Pathname pattern that must match after navigation settles. */
    url?: RegExp
    /** Extra locator that must become visible (page-specific shell). */
    ready?: () => ReturnType<Page['locator']>
    timeout?: number
  },
) {
  const timeout = options?.timeout ?? 45_000
  const pageErrors: string[] = []
  const onPageError = (error: Error) => {
    if (isIgnoredPageError(error.message)) return
    pageErrors.push(error.message)
  }

  page.on('pageerror', onPageError)

  try {
    const response = await page.goto(path, {
      waitUntil: 'domcontentloaded',
      timeout,
    })

    // Soft check: document loaded (SPA may return 200 even for client routes).
    if (response) {
      expect(
        response.status(),
        `Unexpected HTTP ${response.status()} for ${path}`,
      ).toBeLessThan(500)
    }

    await expect(page.locator('body')).toBeVisible({ timeout })
    await acceptCookieBannerIfPresent(page)

    if (options?.url) {
      await expect(page).toHaveURL(options.url, { timeout })
    }

    for (const pattern of FATAL_PAGE_COPY) {
      await expect(
        page.getByText(pattern),
        `Fatal copy matching ${pattern} on ${path}`,
      ).toHaveCount(0)
    }

    if (options?.ready) {
      await expect(options.ready()).toBeVisible({ timeout })
    }

    // Give client routers a beat to settle redirects / loaders.
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {
      // Some pages keep long-lived connections (realtime); ignore.
    })

    expect(
      pageErrors,
      `Uncaught page errors on ${path}:\n${pageErrors.join('\n')}`,
    ).toEqual([])
  } finally {
    page.off('pageerror', onPageError)
  }
}
