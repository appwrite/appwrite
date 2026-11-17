import type { Models } from '@appwrite.io/console'
import type { Page } from '@playwright/test'
import { expect, test } from './fixtures'

/**
 * An unverified account on the verify-email page, with every verification
 * email the page requests counted.
 */
async function mockUnverifiedAccount(page: Page) {
  const sent = { count: 0 }
  const now = '2026-11-17T00:00:00.000Z'
  const account: Models.User<Models.Preferences> = {
    $id: 'verify-test-user',
    $createdAt: now,
    $updatedAt: now,
    name: 'Verify Test',
    email: 'verify@example.com',
    registration: now,
    status: true,
    labels: [],
    passwordUpdate: now,
    phone: '',
    emailVerification: false,
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

    if (apiPath === '/account' && request.method() === 'GET') {
      return json(200, account)
    }
    if (
      apiPath === '/account/verifications/email' &&
      request.method() === 'POST'
    ) {
      sent.count += 1
      const token: Models.Token = {
        $id: `token-${sent.count}`,
        $createdAt: now,
        userId: account.$id,
        secret: '',
        expire: now,
        phrase: '',
      }
      return json(201, token)
    }
    return json(404, {
      code: 404,
      type: 'general_route_not_found',
      message: 'Mock API response',
    })
  })

  return sent
}

test.describe('verify email', () => {
  test('sends one email, then waits a minute between resends', async ({
    page,
  }) => {
    await page.clock.install()
    const sent = await mockUnverifiedAccount(page)

    // Arrange: sign-up lands here, and the page mails the link by itself.
    await page.goto('/verify-email')
    await expect.poll(() => sent.count).toBe(1)

    const waiting = page.getByRole('button', { name: /Resend email in \d+s/ })
    await expect(waiting).toBeDisabled()

    // Act: spam the button, as in the report.
    for (let i = 0; i < 10; i++) {
      await waiting.click({ force: true })
    }

    // Assert: still one email.
    expect(sent.count).toBe(1)

    // A reload neither mails another link nor resets the wait.
    await page.reload()
    await expect(waiting).toBeDisabled()
    expect(sent.count).toBe(1)

    // After a minute the button sends exactly one more email.
    await page.clock.fastForward('01:01')
    const resend = page.getByRole('button', {
      name: 'Resend verification email',
    })
    await expect(resend).toBeEnabled()
    await resend.click()
    await expect.poll(() => sent.count).toBe(2)
    await expect(waiting).toBeDisabled()
  })
})
