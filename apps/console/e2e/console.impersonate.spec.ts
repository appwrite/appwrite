import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'
import { env } from './config/env'
import { appwriteApiPath } from './helpers/appwrite-url'
import { acceptCookieBannerIfPresent } from './helpers/cookie-banner'

/**
 * `/impersonate/$userId` deep link against a real backend.
 *
 * The operator suite runs only when the signed-in e2e account carries the
 * `impersonator` flag (operators on staging / cloud); the target is any other
 * console user visible through `users.list`. The non-operator suite covers the
 * standard e2e account and asserts the redirect back to `/account`.
 */

const IMPERSONATE_HEADER = 'x-appwrite-impersonate-user-id'
const TARGET_STORAGE_KEY = 'console.impersonation.targetUserId'

type ConsoleUser = Models.User<Models.Preferences> & {
  impersonator?: boolean
  impersonatorUserId?: string
}

type OperatorContext = {
  account: ConsoleUser
  candidates: ConsoleUser[]
}

/** Same auth the SDK uses cross-origin: session cookie plus the localStorage fallback. */
async function readOperatorContext(page: import('@playwright/test').Page) {
  return page.evaluate(async (endpoint): Promise<OperatorContext> => {
    const headers: Record<string, string> = {
      'X-Appwrite-Project': 'console',
      'X-Fallback-Cookies': window.localStorage.getItem('cookieFallback') ?? '',
    }
    const get = async (path: string) => {
      const response = await fetch(`${endpoint}${path}`, {
        headers,
        credentials: 'include',
      })
      if (!response.ok) {
        throw new Error(
          `${path} failed: ${response.status} ${await response.text()}`,
        )
      }
      return response.json()
    }

    const account = (await get('/account')) as ConsoleUser
    if (!account.impersonator) return { account, candidates: [] }

    const limit = encodeURIComponent(
      JSON.stringify({ method: 'limit', values: [10] }),
    )
    const list = (await get(`/users?queries[]=${limit}`)) as {
      users: ConsoleUser[]
    }
    return { account, candidates: list.users ?? [] }
  }, env.VITE_APPWRITE_ENDPOINT)
}

test.describe('impersonation deep link (non-operator account)', () => {
  test('is sent straight to /account', async ({ page }) => {
    await page.goto('/account', { waitUntil: 'domcontentloaded' })
    await acceptCookieBannerIfPresent(page)
    await expect(page).toHaveURL(/\/account(?:\/|$|\?)/, { timeout: 45_000 })

    const { account } = await readOperatorContext(page)
    test.skip(
      !!account.impersonator,
      'e2e account is an operator; covered by the operator suite',
    )

    const targetLookup = page.waitForResponse(
      (response) =>
        response.request().method() === 'GET' &&
        appwriteApiPath(response.url()) === `/users/${account.$id}`,
      { timeout: 45_000 },
    )

    await page.goto(`/impersonate/${account.$id}`, {
      waitUntil: 'domcontentloaded',
    })

    // The backend refuses the console users API for non-operators.
    expect((await targetLookup).status()).toBe(401)
    await expect(page).toHaveURL(/\/account(?:\/|$|\?)/, { timeout: 45_000 })
    await expect(
      page.getByRole('button', { name: 'Start impersonation' }),
    ).toHaveCount(0)
  })
})

test.describe('impersonation deep link (operator account)', () => {
  let operator: ConsoleUser
  let target: ConsoleUser

  test.beforeEach(async ({ page }) => {
    await page.goto('/account', { waitUntil: 'domcontentloaded' })
    await acceptCookieBannerIfPresent(page)
    await expect(page).toHaveURL(/\/account(?:\/|$|\?)/, { timeout: 45_000 })

    const context = await readOperatorContext(page)
    test.skip(
      !context.account.impersonator,
      'e2e account is not an operator (no impersonator flag)',
    )
    operator = context.account

    const candidate = context.candidates.find(
      (user) => user.$id !== operator.$id,
    )
    test.skip(!candidate, 'no other console user visible to the operator')
    target = candidate!
  })

  test.afterEach(async ({ page }) => {
    await page
      .evaluate(
        (key) => window.sessionStorage.removeItem(key),
        TARGET_STORAGE_KEY,
      )
      .catch(() => undefined)
  })

  test('confirms, runs as the target, and exits back to the operator', async ({
    page,
  }) => {
    await page.goto(`/impersonate/${target.$id}`, {
      waitUntil: 'domcontentloaded',
    })

    const card = page.locator('#main-content')
    await expect(
      card.getByRole('heading', { name: 'Impersonate user' }),
    ).toBeVisible({ timeout: 45_000 })
    await expect(card.getByText(target.$id)).toBeVisible()
    if (target.email) {
      await expect(card.getByText(target.email)).toBeVisible()
    }

    const start = card.getByRole('button', { name: 'Start impersonation' })
    await expect(start).toBeEnabled({ timeout: 30_000 })

    const impersonatedAccount = page.waitForResponse(
      (response) =>
        response.request().method() === 'GET' &&
        appwriteApiPath(response.url()) === '/account' &&
        response.request().headers()[IMPERSONATE_HEADER] === target.$id,
      { timeout: 45_000 },
    )

    await start.click()

    const response = await impersonatedAccount
    expect(response.ok(), await response.text()).toBeTruthy()
    const resolved = (await response.json()) as ConsoleUser
    // The backend swaps the effective user and reports the operator by id.
    expect(resolved.$id).toBe(target.$id)
    expect(resolved.impersonatorUserId).toBe(operator.$id)

    await expect(page).toHaveURL(/\/account(?:\/|$|\?)/, { timeout: 45_000 })

    const banner = page.getByRole('status', { name: /Impersonation active/ })
    await expect(banner).toBeVisible({ timeout: 45_000 })
    await expect(banner).toContainText(
      target.name || target.email || target.$id,
    )
    await expect(banner).toContainText(
      operator.name || operator.email || operator.$id,
    )

    expect(
      await page.evaluate(
        (key) => window.sessionStorage.getItem(key),
        TARGET_STORAGE_KEY,
      ),
    ).toBe(target.$id)

    const operatorAccount = page.waitForResponse(
      (response) =>
        response.request().method() === 'GET' &&
        appwriteApiPath(response.url()) === '/account' &&
        !response.request().headers()[IMPERSONATE_HEADER],
      { timeout: 45_000 },
    )

    await banner.getByRole('button', { name: 'Exit impersonation' }).click()

    const restored = (await (await operatorAccount).json()) as ConsoleUser
    expect(restored.$id).toBe(operator.$id)
    await expect(page).toHaveURL(/\/account(?:\/|$|\?)/, { timeout: 45_000 })
    await expect(banner).toHaveCount(0)
    expect(
      await page.evaluate(
        (key) => window.sessionStorage.getItem(key),
        TARGET_STORAGE_KEY,
      ),
    ).toBeNull()
  })

  test('unknown user id cannot be confirmed', async ({ page }) => {
    await page.goto('/impersonate/no-such-user-e2e', {
      waitUntil: 'domcontentloaded',
    })

    const card = page.locator('#main-content')
    await expect(
      card.getByRole('heading', { name: 'Impersonate user' }),
    ).toBeVisible({ timeout: 45_000 })
    await expect(
      card.getByRole('button', { name: 'Start impersonation' }),
    ).toBeDisabled({ timeout: 30_000 })
  })
})
