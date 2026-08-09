import fs from 'node:fs'
import path from 'node:path'
import { expect, test } from '@playwright/test'
import { env } from './config/env'
import { withWebsiteAccessCookie } from './helpers/website-access'

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

test('authenticate once and persist storage state', async ({
  page,
  context,
}) => {
  fs.mkdirSync(authDir, { recursive: true })

  // Prefer email/password when available so storage state is captured against
  // this run's origin (localhost:4173). Session secret is a CI fast-path only
  // when credentials are not provided.
  const email = env.E2E_TEST_EMAIL
  const password = env.E2E_TEST_PASSWORD
  const canPasswordLogin = Boolean(email && password)

  if (env.E2E_TEST_SESSION_SECRET && !canPasswordLogin) {
    try {
      const storageState = withWebsiteAccessCookie(
        parseSessionSecret(env.E2E_TEST_SESSION_SECRET),
      )
      fs.writeFileSync(authPath, JSON.stringify(storageState, null, 2), 'utf-8')
      return
    } catch (error) {
      throw new Error(
        `Failed to parse E2E_TEST_SESSION_SECRET: ${(error as Error).message}`,
      )
    }
  }

  if (!email || !password) {
    throw new Error('E2E_TEST_EMAIL and E2E_TEST_PASSWORD must be set')
  }

  // Always sign in fresh so local credential changes take effect.
  if (fs.existsSync(authPath)) {
    fs.unlinkSync(authPath)
  }

  await test.step('sign in with E2E credentials', async () => {
    await page.goto('/sign-in', { waitUntil: 'domcontentloaded' })

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
  })

  // Cookie fallback (localStorage) is what the SDK uses cross-origin; storageState
  // captures both cookies and origin localStorage. Keep the soft-launch access
  // cookie so console tests are not redirected to /access.
  await context.storageState({ path: authPath })

  const state = withWebsiteAccessCookie(
    JSON.parse(fs.readFileSync(authPath, 'utf-8')) as StorageState,
  )
  fs.writeFileSync(authPath, JSON.stringify(state, null, 2), 'utf-8')

  const hasCookies = (state.cookies?.length ?? 0) > 0
  const hasOrigins = (state.origins?.length ?? 0) > 0
  expect(
    hasCookies || hasOrigins,
    'Expected session cookies or localStorage after login',
  ).toBeTruthy()
})
