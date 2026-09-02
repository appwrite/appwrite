import type { Page, Route } from '@playwright/test'
import { expect, test } from './fixtures'
import { env } from './config/env'
import { appwriteApiPath } from './helpers/appwrite-url'

/**
 * `/impersonate/$userId` deep link (HelpScout triage notes → Console).
 *
 * Impersonation needs a console account with the `impersonator` flag, which no
 * public API can grant, so the Appwrite API is mocked at the network layer and
 * the real browser flow is asserted: confirmation card, impersonation header on
 * the next `account.get`, banner after the hard navigation to `/account`.
 */

const IMPERSONATE_HEADER = 'x-appwrite-impersonate-user-id'

const OPERATOR = {
  $id: 'operator0000000000001',
  name: 'Ops Operator',
  email: 'operator@appwrite.io',
  impersonator: true,
}

const TARGET = {
  $id: 'ticketopener00000001',
  name: 'Ticket Opener',
  email: 'ticket.opener@example.com',
}

type MockUser = {
  $id: string
  name: string
  email: string
  impersonator?: boolean
  impersonatorUserId?: string
}

function mockUserResponse(overrides: MockUser) {
  const now = new Date().toISOString()
  return {
    $createdAt: now,
    $updatedAt: now,
    registration: now,
    status: true,
    labels: [],
    passwordUpdate: now,
    phone: '',
    emailVerification: true,
    phoneVerification: false,
    mfa: false,
    prefs: {},
    targets: [],
    accessedAt: now,
    ...overrides,
  }
}

const NOT_FOUND = {
  message: 'Not found',
  code: 404,
  type: 'general_route_not_found',
  version: '1.0',
}

const UNAUTHORIZED = {
  message: 'User (role: guests) missing scope (account)',
  code: 401,
  type: 'general_unauthorized_scope',
  version: '1.0',
}

type ApiScenario = {
  /** Account returned without an impersonation header. `null` = signed out. */
  account: MockUser | null
}

function corsHeaders(route: Route): Record<string, string> {
  const request = route.request()
  const origin = request.headers()['origin'] ?? 'http://localhost:4173'
  const requested = request.headers()['access-control-request-headers']
  return {
    'access-control-allow-origin': origin,
    'access-control-allow-credentials': 'true',
    'access-control-allow-methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
    'access-control-allow-headers':
      requested ?? 'content-type,x-appwrite-project,x-appwrite-response-format',
    'access-control-expose-headers': 'x-appwrite-session',
  }
}

async function mockAppwriteApi(page: Page, scenario: ApiScenario) {
  await page.route(`${env.VITE_APPWRITE_ENDPOINT}/**`, async (route) => {
    const request = route.request()
    const headers = corsHeaders(route)

    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers })
      return
    }

    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })

    const path = appwriteApiPath(request.url())

    if (request.method() === 'GET' && path === '/account') {
      if (!scenario.account) return json(401, UNAUTHORIZED)

      const impersonated = request.headers()[IMPERSONATE_HEADER]
      if (impersonated === TARGET.$id) {
        // Server resolves `targetUser`; the operator is only reported by id.
        return json(
          200,
          mockUserResponse({
            ...TARGET,
            impersonatorUserId: scenario.account.$id,
          }),
        )
      }
      return json(200, mockUserResponse(scenario.account))
    }

    if (request.method() === 'GET' && path === '/account/prefs') {
      return json(200, {})
    }

    if (request.method() === 'GET' && path === `/users/${TARGET.$id}`) {
      return json(200, mockUserResponse(TARGET))
    }

    return json(404, NOT_FOUND)
  })
}

test.describe('impersonation deep link (mocked API)', () => {
  test('operator confirms and lands impersonated on /account', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { account: OPERATOR })

    await page.goto(`/impersonate/${TARGET.$id}`, {
      waitUntil: 'domcontentloaded',
    })

    const card = page.locator('#main-content')
    await expect(
      card.getByRole('heading', { name: 'Impersonate user' }),
    ).toBeVisible()
    await expect(card.getByText(TARGET.name)).toBeVisible()
    await expect(card.getByText(TARGET.email)).toBeVisible()
    await expect(card.getByText(OPERATOR.name)).toBeVisible()

    const start = page.getByRole('button', { name: 'Start impersonation' })
    await expect(start).toBeEnabled()

    const impersonatedAccountRequest = page.waitForRequest(
      (request) =>
        request.method() === 'GET' &&
        appwriteApiPath(request.url()) === '/account' &&
        request.headers()[IMPERSONATE_HEADER] === TARGET.$id,
      { timeout: 30_000 },
    )

    await start.click()

    await impersonatedAccountRequest
    await expect(page).toHaveURL(/\/account(?:\/|$|\?)/, { timeout: 30_000 })

    const banner = page.getByRole('status', { name: /Impersonation active/ })
    await expect(banner).toBeVisible({ timeout: 30_000 })
    await expect(banner).toContainText(TARGET.name)
    await expect(banner).toContainText(OPERATOR.name)

    const storedTarget = await page.evaluate(() =>
      window.sessionStorage.getItem('console.impersonation.targetUserId'),
    )
    expect(storedTarget).toBe(TARGET.$id)
  })

  test('exit from the banner clears the session and header', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { account: OPERATOR })

    await page.goto(`/impersonate/${TARGET.$id}`, {
      waitUntil: 'domcontentloaded',
    })
    await page.getByRole('button', { name: 'Start impersonation' }).click()

    const banner = page.getByRole('status', { name: /Impersonation active/ })
    await expect(banner).toBeVisible({ timeout: 30_000 })

    const plainAccountRequest = page.waitForRequest(
      (request) =>
        request.method() === 'GET' &&
        appwriteApiPath(request.url()) === '/account' &&
        !request.headers()[IMPERSONATE_HEADER],
      { timeout: 30_000 },
    )

    await banner.getByRole('button', { name: 'Exit impersonation' }).click()

    await plainAccountRequest
    await expect(page).toHaveURL(/\/account(?:\/|$|\?)/, { timeout: 30_000 })
    await expect(banner).toHaveCount(0)

    const storedTarget = await page.evaluate(() =>
      window.sessionStorage.getItem('console.impersonation.targetUserId'),
    )
    expect(storedTarget).toBeNull()
  })

  test('non-operator is sent to /account without a confirm step', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      account: { ...OPERATOR, impersonator: false },
    })

    await page.goto(`/impersonate/${TARGET.$id}`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(page).toHaveURL(/\/account(?:\/|$|\?)/, { timeout: 30_000 })
    await expect(
      page.getByRole('button', { name: 'Start impersonation' }),
    ).toHaveCount(0)
    await expect(
      page.getByRole('status', { name: /Impersonation active/ }),
    ).toHaveCount(0)
  })

  test('signed-out visitor is sent to sign-in with the link preserved', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { account: null })

    await page.goto(`/impersonate/${TARGET.$id}`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(page).toHaveURL(/\/sign-in/, { timeout: 30_000 })
    const redirect = new URL(page.url()).searchParams.get('redirect')
    expect(redirect).toBe(`/impersonate/${TARGET.$id}`)
  })
})
