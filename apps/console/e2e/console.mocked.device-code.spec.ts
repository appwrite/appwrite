import type { Models } from '@appwrite.io/console'
import type { Page } from '@playwright/test'
import { expect, test } from './fixtures'

// Cloud's console project issues eight-character codes, and other projects can
// configure anything from six to twelve. The page cannot read that setting --
// it runs before sign-in -- so it must not assume a length.
const USER_CODE = 'MDF2TN39'

/**
 * Serve a signed-in account and capture the user code the page submits.
 */
async function mockDeviceFlow(page: Page): Promise<() => string | null> {
  const now = '2026-09-08T00:00:00.000Z'
  let submittedUserCode: string | null = null

  const account: Models.User<Models.Preferences> = {
    $id: 'device-test-user',
    $createdAt: now,
    $updatedAt: now,
    name: 'Device Test',
    email: 'device@example.com',
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

    if (apiPath === '/account') return json(200, account)

    if (apiPath.endsWith('/grants') && request.method() === 'POST') {
      submittedUserCode = request.postDataJSON()?.user_code ?? null
      const grant: Models.Oauth2Grant = {
        $id: 'device-grant',
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
      return json(200, {
        $id: 'app',
        name: 'Test OAuth app',
        description: '',
        url: 'https://client.example',
        logo: '',
        privacyPolicyUrl: '',
        termsUrl: '',
      })
    }

    return json(404, {
      code: 404,
      type: 'general_route_not_found',
      message: 'Not found',
    })
  })

  return () => submittedUserCode
}

test('carries a full-length device user code through to the grant', async ({
  page,
}) => {
  const submitted = await mockDeviceFlow(page)

  await page.goto(`/oauth2/device?user_code=${USER_CODE}`)

  // The prefill from verification_uri_complete must survive intact.
  await expect(page.locator('#user-code')).toHaveValue(USER_CODE)

  await page.getByRole('button', { name: 'Continue' }).click()

  await expect.poll(submitted).toBe(USER_CODE)
})
