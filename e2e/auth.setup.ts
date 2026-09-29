import fs from 'node:fs'
import path from 'node:path'
import type { Page } from '@playwright/test'
import { expect, test } from './fixtures'
import { env } from './config/env'
import {
  acceptCookieBannerIfPresent,
  skipCommunitySupportWizardIfPresent,
} from './helpers/cookie-banner'
import { cleanupE2eProjects } from './helpers/e2e-project-cleanup'

type StorageState = {
  cookies?: unknown[]
  origins?: unknown[]
}

function parseSessionSecret(secret: string): StorageState {
  try {
    return JSON.parse(secret) as StorageState
  } catch {
    const decoded = Buffer.from(secret, 'base64').toString('utf-8')
    return JSON.parse(decoded) as StorageState
  }
}

const authDir = path.join('e2e', '.auth')
const authPath = path.join(authDir, 'auth.json')

async function sweepStaleE2eProjects(): Promise<void> {
  if (!env.E2E_ORG_ID) return
  try {
    await cleanupE2eProjects({ includeStaleLeftovers: true })
  } catch (error) {
    console.error('[e2e] Stale project cleanup failed:', error)
  }
}

async function signIn(page: Page, email: string, password: string) {
  await page.goto('/sign-in', { waitUntil: 'domcontentloaded' })
  await acceptCookieBannerIfPresent(page)

  await page.locator('input[name="email"]').fill(email)
  await page.locator('input[name="password"]').fill(password)

  const sessionPromise = page.waitForResponse(
    (response) =>
      response.url().includes('/sessions') &&
      response.request().method() === 'POST',
    { timeout: 30_000 },
  )

  await page.getByRole('button', { name: 'Login', exact: true }).click()

  const response = await sessionPromise
  if (!response.ok()) {
    throw new Error(
      `Login failed: ${response.status()} ${await response.text()}`,
    )
  }

  await page.waitForURL(
    (url) => {
      const pathname = new URL(url).pathname
      return (
        pathname === '/account' ||
        pathname.startsWith('/organizations/') ||
        pathname === '/'
      )
    },
    { timeout: 30_000 },
  )
}

/** Load the app with an existing session in the SDK's `cookieFallback` slot. */
async function reuseSession(page: Page, fallbackCookies: string) {
  await page.context().addInitScript((value) => {
    window.localStorage.setItem('cookieFallback', value)
  }, fallbackCookies)

  const accountPromise = page.waitForResponse(
    (response) =>
      new URL(response.url()).pathname.endsWith('/account') &&
      response.request().method() === 'GET',
    { timeout: 30_000 },
  )

  await page.goto('/account', { waitUntil: 'domcontentloaded' })
  await acceptCookieBannerIfPresent(page)

  const response = await accountPromise
  if (!response.ok()) {
    throw new Error(
      `Shared session rejected: ${response.status()} ${await response.text()}`,
    )
  }
}

test('authenticate once and persist storage state', async ({
  page,
  context,
}) => {
  if (env.E2E_ORG_ID) {
    // Leftover sweep can delete hundreds of crashed-run projects.
    test.setTimeout(20 * 60_000)
  }

  fs.mkdirSync(authDir, { recursive: true })

  // The console keeps 10 sessions per user and deletes the oldest on overflow,
  // so a login per lane evicts the sessions of overlapping runs mid-suite. CI
  // signs in once per workflow run and every lane reuses that session.
  const fallbackCookies = env.E2E_FALLBACK_COOKIES
  if (env.CI && !fallbackCookies) {
    throw new Error(
      'E2E_FALLBACK_COOKIES must be set in CI so every lane shares one session',
    )
  }

  // Prefer email/password when available so storage state is captured against
  // this run's origin (localhost:4173). Session secret is a fast-path only
  // when credentials are not provided.
  const email = env.E2E_TEST_EMAIL
  const password = env.E2E_TEST_PASSWORD
  const canPasswordLogin = Boolean(email && password)

  if (!fallbackCookies && env.E2E_TEST_SESSION_SECRET && !canPasswordLogin) {
    try {
      const storageState = parseSessionSecret(env.E2E_TEST_SESSION_SECRET)
      fs.writeFileSync(authPath, JSON.stringify(storageState, null, 2), 'utf-8')
    } catch (error) {
      throw new Error(
        `Failed to parse E2E_TEST_SESSION_SECRET: ${(error as Error).message}`,
      )
    }
    await sweepStaleE2eProjects()
    return
  }

  // Always start from a fresh session so local credential changes take effect.
  if (fs.existsSync(authPath)) {
    fs.unlinkSync(authPath)
  }

  if (fallbackCookies) {
    await test.step('reuse the workflow session', () =>
      reuseSession(page, fallbackCookies))
  } else {
    if (!email || !password) {
      throw new Error('E2E_TEST_EMAIL and E2E_TEST_PASSWORD must be set')
    }
    await test.step('sign in with E2E credentials', () =>
      signIn(page, email, password))
  }

  // Same idea as the cookie banner: dismiss this overlay at session start
  // so later tests are not blocked. Prefs load after login, so wait briefly.
  await skipCommunitySupportWizardIfPresent(page, { waitMs: 8_000 })

  // Cookie fallback (localStorage) is what the SDK uses cross-origin; storageState
  // captures both cookies and origin localStorage.
  await context.storageState({ path: authPath })

  const state = JSON.parse(fs.readFileSync(authPath, 'utf-8')) as StorageState

  const hasCookies = (state.cookies?.length ?? 0) > 0
  const hasOrigins = (state.origins?.length ?? 0) > 0
  expect(
    hasCookies || hasOrigins,
    'Expected session cookies or localStorage after login',
  ).toBeTruthy()

  await sweepStaleE2eProjects()
})
