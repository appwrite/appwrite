import type { Models } from '@appwrite.io/console'
import type { Page } from '@playwright/test'
import { expect, test } from './fixtures'

/**
 * The consent card hands the browser a redirect to the OAuth2 client and stays
 * on screen until that client sends its first byte. A slow client (a Sites
 * preview cold-starting behind edge) must not let the user press the buttons
 * again in the meantime. The redirect target here never answers, so every
 * observation after the first press runs over CDP: Playwright's own actions
 * wait for the pending navigation to settle, which by design it never does.
 */

const now = '2026-09-21T00:00:00.000Z'
const account: Models.User<Models.Preferences> = {
  $id: 'consent-user',
  $createdAt: now,
  $updatedAt: now,
  name: 'Preview reader',
  email: 'reader@example.com',
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
const grant: Models.Oauth2Grant = {
  $id: 'test-grant',
  $createdAt: now,
  $updatedAt: now,
  userId: account.$id,
  appId: 'app',
  scopes: ['openid'],
  resources: [],
  authorizationDetails: '',
  prompt: 'consent',
  redirectUri: 'https://preview-auth.example/callback',
  authTime: 0,
  expire: new Date(Date.now() + 600_000).toISOString(),
}
const app: Pick<
  Models.App,
  | '$id'
  | 'name'
  | 'description'
  | 'clientUri'
  | 'logoUri'
  | 'privacyPolicyUrl'
  | 'termsUrl'
> = {
  $id: 'app',
  name: 'Sites preview',
  description: '',
  clientUri: 'https://preview-auth.example',
  logoUri: '',
  privacyPolicyUrl: '',
  termsUrl: '',
}

type Calls = { approve: number; reject: number }

async function mockConsent(page: Page, slowClient: string): Promise<Calls> {
  const calls: Calls = { approve: 0, reject: 0 }
  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    if (url.href === slowClient) {
      // The client never sends its first byte: the consent page stays on screen.
      return new Promise<void>(() => {})
    }
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
    if (apiPath.endsWith('/grants/test-grant')) return json(200, grant)
    if (apiPath === '/apps/app') return json(200, app)
    if (apiPath.endsWith('/approve')) {
      calls.approve += 1
      return json(200, { redirectUrl: slowClient })
    }
    if (apiPath.endsWith('/reject')) {
      calls.reject += 1
      return json(200, { redirectUrl: slowClient })
    }
    return json(404, {
      code: 404,
      type: 'general_route_not_found',
      message: 'Mock API response',
    })
  })
  return calls
}

/**
 * Runs `scenario` inside the page and returns whatever it resolves to. It is
 * handed `press(label)`, `read()` and `sleep(ms)`. The whole scenario is sent
 * in a single evaluation issued before the first press: once the redirect is
 * pending, no further evaluation gets an answer.
 */
async function runScenario(page: Page, scenario: string): Promise<string[][]> {
  await page.goto('/oauth2/consent?grant_id=test-grant')
  await expect(
    page.getByRole('button', { name: 'Authorize', exact: true }),
  ).toBeEnabled()
  await expect(
    page.getByRole('button', { name: 'Cancel', exact: true }),
  ).toBeEnabled()
  const cdp = await page.context().newCDPSession(page)
  const { result } = await cdp.send('Runtime.evaluate', {
    expression: `(async () => {
      const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
      const buttons = () => Array.from(document.querySelectorAll('button'))
      const read = () =>
        buttons().map((button) => (button.disabled ? '[disabled] ' : '') + button.textContent.trim())
      const press = (label) => {
        const button = buttons().find((candidate) => candidate.textContent.trim() === label)
        if (button && !button.disabled) button.click()
      }
      const restoreFromBfcache = () =>
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }))
      ${scenario}
    })()`,
    awaitPromise: true,
    returnByValue: true,
  })
  return result.value as string[][]
}

function slowClientOf(): string {
  return new URL('/slow-client', String(test.info().project.use.baseURL)).href
}

test.describe('OAuth consent slow client redirect (mocked API)', () => {
  test('authorize stays disabled and reports progress until the client answers', async ({
    page,
  }) => {
    const calls = await mockConsent(page, slowClientOf())
    const [pressed, pressedAgain] = await runScenario(
      page,
      `press('Authorize')
       await sleep(1500)
       const pressed = read()
       press('Authorize'); press('Redirecting…'); press('Cancel')
       await sleep(1000)
       return [pressed, read()]`,
    )
    expect(pressed).toEqual(
      expect.arrayContaining(['[disabled] Redirecting…', '[disabled] Cancel']),
    )
    expect(pressedAgain).toEqual(pressed)
    expect(calls).toEqual({ approve: 1, reject: 0 })
  })

  test('cancel shows its own progress while the client redirect is pending', async ({
    page,
  }) => {
    const calls = await mockConsent(page, slowClientOf())
    const [pressed, pressedAgain] = await runScenario(
      page,
      `press('Cancel')
       await sleep(1500)
       const pressed = read()
       press('Authorize'); press('Redirecting…')
       await sleep(1000)
       return [pressed, read()]`,
    )
    expect(pressed).toEqual(
      expect.arrayContaining([
        '[disabled] Authorize',
        '[disabled] Redirecting…',
      ]),
    )
    expect(pressedAgain).toEqual(pressed)
    expect(calls).toEqual({ approve: 0, reject: 1 })
  })

  test('a back navigation restores the buttons', async ({ page }) => {
    await mockConsent(page, slowClientOf())
    const [pressed, restored] = await runScenario(
      page,
      `press('Authorize')
       await sleep(1500)
       const pressed = read()
       restoreFromBfcache()
       await sleep(500)
       return [pressed, read()]`,
    )
    expect(pressed).toContain('[disabled] Redirecting…')
    expect(restored).toEqual(expect.arrayContaining(['Authorize', 'Cancel']))
    expect(restored).not.toContain('[disabled] Redirecting…')
  })
})
