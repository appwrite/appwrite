import type { Models } from '@appwrite.io/console'
import type { Page } from '@playwright/test'
import { expect, test } from './fixtures'

const consent =
  '/oauth2/consent?client_id=app&redirect_uri=https://client.example/callback' +
  '&authorization_details=%5B%7B%22type%22%3A%22https://example.com/preview%22%7D%5D' +
  '&resource=https://api.example/a&resource=https://api.example/b&state=000123'

async function mockAccount(page: Page, signedIn: boolean) {
  const now = '2026-09-08T00:00:00.000Z'
  const account: Models.User<Models.Preferences> = {
    $id: 'oauth-test-user',
    $createdAt: now,
    $updatedAt: now,
    name: 'OAuth Test',
    email: 'oauth@example.com',
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
  }
  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
    const apiPath = url.pathname.match(/^\/v1(\/.*)$/)?.[1]
    if (url.origin === localOrigin && !apiPath) return route.continue()
    const headers = {
      'access-control-allow-origin': request.headers().origin ?? '*',
      'access-control-allow-credentials': 'true',
      'access-control-allow-methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
      'access-control-allow-headers':
        request.headers()['access-control-request-headers'] ?? '*',
    }
    if (request.method() === 'OPTIONS' || !apiPath) {
      return route.fulfill({ status: 204, headers })
    }
    const isAccount = apiPath === '/account'
    const status = isAccount ? (signedIn ? 200 : 401) : 404
    return route.fulfill({
      status,
      headers: { ...headers, 'content-type': 'application/json' },
      body: JSON.stringify(
        isAccount && signedIn
          ? account
          : {
              code: status,
              type: isAccount
                ? 'general_unauthorized_scope'
                : 'general_route_not_found',
              message: 'Mock API response',
            },
      ),
    })
  })
}

test.describe('Raw OAuth consent redirects (mocked API)', () => {
  for (const path of ['/sign-in', '/sign-up', '/mfa', '/verify-email']) {
    test(`${path} resumes the exact consent URL for an authenticated account`, async ({
      page,
    }) => {
      await mockAccount(page, true)
      const documents: string[] = []
      page.on('request', (request) => {
        if (request.isNavigationRequest()) documents.push(request.url())
      })
      await page.goto(`${path}?redirect=${encodeURIComponent(consent)}`)
      await expect(page).toHaveURL(new URL(consent, page.url()).href)
      await expect(
        page.getByRole('heading', { name: 'Authorization failed' }),
      ).toBeVisible()
      // The mocked authorize endpoint intentionally fails. We assert the auth
      // handoff, including a document request rather than lossy SPA serialization.
      expect(documents).toContain(new URL(consent, page.url()).href)
    })
  }

  test('PAR-unavailable guest fallback retains raw input at sign-in', async ({
    page,
  }) => {
    await mockAccount(page, false)
    await page.goto(consent)
    await expect(page).toHaveURL(/\/sign-in\?/)
    expect(new URL(page.url()).searchParams.get('redirect')).toBe(consent)
    await expect(
      page.getByRole('heading', { name: 'Welcome back', exact: true }),
    ).toBeVisible()
  })
})
