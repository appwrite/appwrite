import type { Models } from '@appwrite.io/console'
import type { Page } from '@playwright/test'
import { expect, test } from './fixtures'
import {
  CONSOLE_IMPERSONATION_OPERATOR_KEY,
  CONSOLE_IMPERSONATION_TARGET_KEY,
} from '../src/lib/console-impersonation'

const consent =
  '/oauth2/consent?client_id=app&redirect_uri=https://client.example/callback' +
  '&authorization_details=%5B%7B%22type%22%3A%22https://example.com/preview%22%7D%5D' +
  '&resource=https://api.example/a&resource=https://api.example/b&state=000123'

async function mockAccount(
  page: Page,
  signedIn: boolean,
  allowAccountSwitch = false,
) {
  let revocations = 0
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
    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })
    if (allowAccountSwitch) {
      if (
        apiPath === '/account/sessions/current' &&
        request.method() === 'DELETE'
      ) {
        revocations += 1
        // Enforce the backend contract: revoke the operator session, not the
        // impersonated user. The first attempt fails to exercise recovery.
        if (
          request.headers()['x-appwrite-impersonate-user-id'] ||
          revocations === 1
        ) {
          return json(503, {
            code: 503,
            type: 'general_server_error',
            message: 'Retry',
          })
        }
        signedIn = false
        return route.fulfill({ status: 204, headers })
      }
      if (apiPath.endsWith('/authorize')) {
        return json(200, { grantId: 'test-grant' })
      }
      if (apiPath.endsWith('/grants/test-grant')) {
        const grant: Models.Oauth2Grant = {
          $id: 'test-grant',
          $createdAt: now,
          $updatedAt: now,
          userId: account.$id,
          appId: 'app',
          scopes: ['profile'],
          resources: [],
          authorizationDetails: '',
          prompt: 'consent',
          redirectUri: 'https://client.example/callback',
          authTime: 0,
          expire: new Date(Date.now() + 600_000).toISOString(),
        }
        return json(200, grant)
      }
      if (apiPath === '/apps/app') {
        const app = {
          $id: 'app',
          name: 'Test OAuth app',
          description: '',
          url: 'https://client.example',
          logo: '',
          privacyPolicyUrl: '',
          termsUrl: '',
        } satisfies Pick<
          Models.App,
          | '$id'
          | 'name'
          | 'description'
          | 'url'
          | 'logo'
          | 'privacyPolicyUrl'
          | 'termsUrl'
        >
        return json(200, app)
      }
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
      await page.goto(`${path}?redirect=${encodeURIComponent(consent)}`)
      await expect(page).toHaveURL(new URL(consent, page.url()).href)
      await expect(
        page.getByRole('heading', { name: 'Authorization failed' }),
      ).toBeVisible()
      // The mocked authorize endpoint intentionally fails. The exact URL and
      // rendered outcome verify the handoff without prescribing how we navigate.
    })
  }

  test('impersonated consent can recover from a failed operator-session revocation', async ({
    page,
  }) => {
    await mockAccount(page, true, true)
    await page.addInitScript(
      ({ targetKey, operatorKey }) => {
        if (sessionStorage.getItem('mock-impersonation-seeded')) return
        sessionStorage.setItem('mock-impersonation-seeded', 'true')
        sessionStorage.setItem(targetKey, 'oauth-test-user')
        sessionStorage.setItem(
          operatorKey,
          JSON.stringify({
            $id: 'operator',
            name: 'Test Operator',
            email: 'operator@example.com',
          }),
        )
      },
      {
        targetKey: CONSOLE_IMPERSONATION_TARGET_KEY,
        operatorKey: CONSOLE_IMPERSONATION_OPERATOR_KEY,
      },
    )
    await page.goto(consent)
    const heading = page.getByRole('heading', {
      name: 'Authorize Test OAuth app',
      exact: true,
    })
    await expect(heading).toBeVisible()
    const accountChip = page.getByRole('button', { name: /oauth@example\.com/ })
    await accountChip.click()
    await page
      .getByRole('menuitem', { name: 'Use a different account' })
      .click()
    await expect(page.getByRole('alert')).toHaveText(
      'Could not sign out. Try switching accounts again.',
    )
    await expect(heading).toBeVisible()
    await accountChip.click()
    await page
      .getByRole('menuitem', { name: 'Use a different account' })
      .click()
    await expect(page).toHaveURL(/\/sign-in\?/)
    const resume = new URL(
      new URL(page.url()).searchParams.get('redirect')!,
      page.url(),
    )
    expect(resume.pathname).toBe('/oauth2/consent')
    expect(resume.searchParams.getAll('resource')).toEqual([
      'https://api.example/a',
      'https://api.example/b',
    ])
    expect(resume.searchParams.has('grant_id')).toBe(false)
    await expect(
      page.getByRole('heading', { name: 'Welcome back', exact: true }),
    ).toBeVisible()
  })

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
