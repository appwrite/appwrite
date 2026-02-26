import { expect, test } from '../fixtures/base'
import fs from 'node:fs'
import path from 'node:path'

type StorageState = {
  cookies?: unknown[]
}

function parseSessionSecret(secret: string): StorageState {
  try {
    const state: StorageState = JSON.parse(secret)
    return state
  } catch {
    const decoded = Buffer.from(secret, 'base64').toString('utf-8')
    const state: StorageState = JSON.parse(decoded)
    return state
  }
}

test('authenticate once and persist storage state', async ({
  page,
  context,
}) => {
  const email = process.env.E2E_TEST_EMAIL
  const password = process.env.E2E_TEST_PASSWORD
  const sessionSecret = process.env.E2E_TEST_SESSION_SECRET

  const authDir = path.join('e2e', '.auth')
  const authPath = path.join(authDir, 'auth.json')

  if (sessionSecret) {
    fs.mkdirSync(authDir, { recursive: true })
    try {
      const storageState = parseSessionSecret(sessionSecret)
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

  if (fs.existsSync(authPath)) {
    return
  }

  // Log in once and reuse storage state across tests to avoid creating new sessions/users.
  await test.step('sign in', async () => {
    await page.goto('/sign-in', { waitUntil: 'domcontentloaded' })

    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill(password)
    const sessionPromise = page.waitForResponse(
      (response) =>
        response.url().includes('/sessions') &&
        response.request().method() === 'POST',
    )
    await page.getByRole('button', { name: 'Login', exact: true }).click()
    const response = await sessionPromise
    if (!response.ok()) {
      console.log('Login failed:', response.status(), await response.text())
    }

    await page.waitForURL(
      (url) => {
        const pathname = new URL(url).pathname
        return (
          pathname === '/onboarding' ||
          pathname.startsWith('/organizations/')
        )
      },
      { timeout: 15000 },
    )
  })

  const cookies = await context.cookies()
  const sessionCookie = cookies.find((cookie) => /session/i.test(cookie.name))
  expect(sessionCookie, 'Expected a session cookie after login').toBeTruthy()

  fs.mkdirSync(authDir, { recursive: true })

  await context.storageState({ path: authPath })
})
