import { generateKeyPairSync, randomBytes } from 'node:crypto'
import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
// Type-only: a value import would pull `@/lib/appwrite/sdk` and its `import.meta.env`
// reads into Playwright's loader, which has no Vite transform and crashes on them.
import type { Passkey } from '@/lib/passkeys'
import { expect, test } from './fixtures'

/**
 * Console passkeys: signing in to the console with one, and managing them from
 * Account > Security.
 *
 * The Appwrite API is mocked at the network layer, and a CDP virtual authenticator
 * answers `navigator.credentials`, so the real browser ceremony runs end to end. The
 * mocked backend accepts any credential; what is asserted is what the console sends
 * and shows.
 */

const NOW = '2026-09-09T09:30:00.000+00:00'

const ACCOUNT = {
  $id: 'user000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Demo Operator',
  registration: NOW,
  status: true,
  labels: [],
  passwordUpdate: NOW,
  email: 'demo@example.com',
  phone: '',
  emailVerification: true,
  phoneVerification: false,
  mfa: false,
  prefs: {},
  targets: [],
  accessedAt: NOW,
} satisfies Partial<Models.User<Models.Preferences>>

const TOKEN_SECRET = 'passkey-token-secret'

const SYNCED: Passkey = {
  $id: 'passkey00000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'MacBook',
  accessedAt: NOW,
  backedUp: true,
}

const DEVICE_BOUND: Passkey = {
  $id: 'passkey00000000000000002',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'YubiKey',
  accessedAt: '',
  backedUp: false,
}

type ApiError = { message: string; code: number; type: string }

type MockOptions = {
  signedIn?: boolean
  passkeys?: Passkey[]
  /** When set, adding a passkey is refused with this error. */
  createError?: ApiError
}

type Calls = {
  requests: { method: string; path: string; request: Request }[]
}

function base64url(bytes: Buffer): string {
  return bytes.toString('base64url')
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

/** Every `/v1` call is answered here; the app itself is served live. */
async function mockAppwriteApi(
  page: Page,
  options: MockOptions = {},
): Promise<Calls> {
  let signedIn = options.signedIn ?? false
  let passkeys = [...(options.passkeys ?? [])]
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  const calls: Calls = { requests: [] }

  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const apiPath = url.pathname.match(/^\/v1(\/.*)$/)?.[1]
    if (url.origin === localOrigin && !apiPath) return route.continue()

    const headers = corsHeaders(route)
    if (!apiPath || request.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers })
    }
    const method = request.method()
    calls.requests.push({ method, path: apiPath, request })
    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })
    const hostname = new URL(localOrigin).hostname

    if (apiPath === '/account/tokens/passkey' && method === 'POST') {
      return json(201, {
        $id: 'challenge000000000000001',
        $createdAt: NOW,
        expire: NOW,
        passkeyId: '',
        publicKey: {
          challenge: base64url(randomBytes(32)),
          rpId: hostname,
          allowCredentials: [],
          userVerification: 'preferred',
          timeout: 60_000,
        },
      })
    }
    if (apiPath === '/account/tokens/passkey' && method === 'PUT') {
      return json(201, {
        $id: 'token0000000000000000001',
        $createdAt: NOW,
        userId: ACCOUNT.$id,
        secret: TOKEN_SECRET,
        expire: NOW,
        phrase: '',
      })
    }
    if (apiPath === '/account/sessions/token' && method === 'POST') {
      signedIn = true
      return json(201, {
        $id: 'session00000000000000001',
        $createdAt: NOW,
        userId: ACCOUNT.$id,
        provider: 'token',
        factors: ['passkey', 'userVerification'],
        current: true,
        secret: '',
      })
    }

    if (!signedIn && apiPath.startsWith('/account')) {
      return json(401, {
        message: 'User (role: guests) missing scopes (["account"])',
        code: 401,
        type: 'general_unauthorized_scope',
        version: '1.0',
      })
    }

    if (apiPath === '/account/passkeys' && method === 'GET') {
      return json(200, { total: passkeys.length, passkeys })
    }
    if (apiPath === '/account/passkeys' && method === 'POST') {
      if (options.createError) {
        return json(options.createError.code, options.createError)
      }
      return json(201, {
        $id: 'challenge000000000000002',
        $createdAt: NOW,
        expire: NOW,
        passkeyId: 'passkey00000000000000003',
        publicKey: {
          rp: { id: hostname, name: 'Appwrite' },
          user: {
            id: base64url(Buffer.from(ACCOUNT.$id)),
            name: ACCOUNT.email,
            displayName: ACCOUNT.name,
          },
          challenge: base64url(randomBytes(32)),
          pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
          authenticatorSelection: {
            residentKey: 'required',
            userVerification: 'preferred',
          },
          attestation: 'none',
          timeout: 60_000,
        },
      })
    }
    const passkeyId = apiPath.match(/^\/account\/passkeys\/([^/]+)/)?.[1]
    if (passkeyId && apiPath.endsWith('/verification') && method === 'PUT') {
      const pending = calls.requests.find(
        (call) => call.method === 'POST' && call.path === '/account/passkeys',
      )
      const created: Passkey = {
        $id: passkeyId,
        $createdAt: NOW,
        $updatedAt: NOW,
        name: pending?.request.postDataJSON()?.name ?? '',
        accessedAt: '',
        backedUp: false,
      }
      passkeys = [created, ...passkeys]
      return json(200, created)
    }
    if (passkeyId && method === 'PATCH') {
      const name = request.postDataJSON()?.name ?? ''
      passkeys = passkeys.map((passkey) =>
        passkey.$id === passkeyId ? { ...passkey, name } : passkey,
      )
      return json(
        200,
        passkeys.find((passkey) => passkey.$id === passkeyId),
      )
    }
    if (passkeyId && method === 'DELETE') {
      passkeys = passkeys.filter((passkey) => passkey.$id !== passkeyId)
      return route.fulfill({ status: 204, headers })
    }

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') return json(200, {})
    if (apiPath === '/account/identities')
      return json(200, { total: 0, identities: [] })
    if (apiPath === '/account/mfa/factors')
      return json(200, {
        totp: false,
        email: false,
        phone: false,
        recoveryCode: false,
      })
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/organizations' || apiPath === '/teams')
      return json(200, { total: 0, teams: [] })
    if (apiPath === '/console/variables') return json(200, {})

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return calls
}

/**
 * A platform authenticator that always verifies the user, so `navigator.credentials`
 * resolves without a prompt. With `withCredential`, it already holds a discoverable
 * passkey for this origin, as if one had been registered earlier.
 */
async function addVirtualAuthenticator(
  page: Page,
  { withCredential = false } = {},
) {
  const session = await page.context().newCDPSession(page)
  await session.send('WebAuthn.enable')
  const { authenticatorId } = await session.send(
    'WebAuthn.addVirtualAuthenticator',
    {
      options: {
        protocol: 'ctap2',
        transport: 'internal',
        hasResidentKey: true,
        hasUserVerification: true,
        isUserVerified: true,
        automaticPresenceSimulation: true,
      },
    },
  )
  if (withCredential) {
    const { privateKey } = generateKeyPairSync('ec', {
      namedCurve: 'prime256v1',
    })
    await session.send('WebAuthn.addCredential', {
      authenticatorId,
      credential: {
        credentialId: randomBytes(16).toString('base64'),
        isResidentCredential: true,
        rpId: new URL(String(test.info().project.use.baseURL)).hostname,
        privateKey: privateKey
          .export({ format: 'der', type: 'pkcs8' })
          .toString('base64'),
        userHandle: Buffer.from(ACCOUNT.$id).toString('base64'),
        signCount: 0,
      },
    })
  }
}

/** Makes the browser prompt behave as if the user closed it. */
async function cancelBrowserPrompts(page: Page) {
  await page.addInitScript(() => {
    const cancel = () =>
      Promise.reject(
        new DOMException('The operation was cancelled.', 'NotAllowedError'),
      )
    navigator.credentials.get = cancel
    navigator.credentials.create = cancel
  })
}

/**
 * Pins the console profile for the page. A dev server started without
 * VITE_CONSOLE_PROFILE picks one from the endpoint, so the specs never rely on it.
 */
async function useProfile(page: Page, id: 'cloud' | 'self-hosted') {
  await page.addInitScript((profileId) => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: profileId, features: {} }),
    )
  }, id)
}

function passkeyButton(page: Page) {
  return page.getByRole('button', { name: 'Sign in with a passkey' })
}

function passkeysCard(page: Page) {
  return page.locator('[data-card-id="passkeys"]')
}

function countCalls(calls: Calls, method: string, path: string) {
  return calls.requests.filter(
    (call) => call.method === method && call.path === path,
  ).length
}

async function openSignIn(page: Page) {
  await page.goto('/sign-in?redirect=%2Faccount%2Fsecurity', {
    waitUntil: 'domcontentloaded',
  })
  await expect(
    page.getByRole('heading', { name: 'Welcome back', exact: true }),
  ).toBeVisible({ timeout: 30_000 })
}

async function openSecurity(page: Page) {
  await page.goto('/account/security', { waitUntil: 'domcontentloaded' })
  await expect(
    page.getByRole('heading', { name: 'Update password', exact: true }),
  ).toBeVisible({ timeout: 30_000 })
}

test.describe('console passkeys (mocked API)', () => {
  test('signing in with a passkey exchanges the token for a session', async ({
    page,
  }) => {
    await useProfile(page, 'cloud')
    const calls = await mockAppwriteApi(page)
    await addVirtualAuthenticator(page, { withCredential: true })
    await openSignIn(page)

    await passkeyButton(page).click()

    await expect(page).toHaveURL(/\/account\/security$/, { timeout: 30_000 })
    await expect(passkeysCard(page)).toBeVisible()

    const paths = calls.requests.map((call) => `${call.method} ${call.path}`)
    const challenge = paths.indexOf('POST /account/tokens/passkey')
    const verify = paths.indexOf('PUT /account/tokens/passkey')
    const session = paths.indexOf('POST /account/sessions/token')
    expect(challenge).toBeGreaterThanOrEqual(0)
    expect(verify).toBeGreaterThan(challenge)
    expect(session).toBeGreaterThan(verify)

    const verification = calls.requests[verify].request
    expect(verification.headers()['x-appwrite-project']).toBe('console')
    const body = verification.postDataJSON()
    expect(body.challengeId).toBe('challenge000000000000001')
    expect(body.credential.type).toBe('public-key')
    expect(typeof body.credential.id).toBe('string')
    expect(typeof body.credential.response.signature).toBe('string')

    expect(calls.requests[session].request.postDataJSON()).toEqual({
      userId: ACCOUNT.$id,
      secret: TOKEN_SECRET,
    })
  })

  test('closing the passkey prompt leaves the sign-in page usable', async ({
    page,
  }) => {
    await useProfile(page, 'cloud')
    const calls = await mockAppwriteApi(page)
    await cancelBrowserPrompts(page)
    await openSignIn(page)

    await passkeyButton(page).click()

    await expect
      .poll(() => countCalls(calls, 'POST', '/account/tokens/passkey'))
      .toBe(1)
    await expect(passkeyButton(page)).toBeEnabled()
    await expect(
      page.getByRole('button', { name: 'Login', exact: true }),
    ).toBeEnabled()
    await expect(page.locator('[data-sonner-toast]')).toHaveCount(0)
    expect(countCalls(calls, 'PUT', '/account/tokens/passkey')).toBe(0)
    expect(countCalls(calls, 'POST', '/account/sessions/token')).toBe(0)
    await expect(page).toHaveURL(/\/sign-in/)
  })

  test('the account lists, adds, renames and deletes passkeys', async ({
    page,
  }) => {
    await useProfile(page, 'cloud')
    const calls = await mockAppwriteApi(page, {
      signedIn: true,
      passkeys: [SYNCED, DEVICE_BOUND],
    })
    await addVirtualAuthenticator(page)
    await openSecurity(page)

    const card = passkeysCard(page)
    const synced = card.getByRole('row').filter({ hasText: 'MacBook' })
    const bound = card.getByRole('row').filter({ hasText: 'YubiKey' })
    await expect(synced).toContainText('Synced')
    await expect(bound).toContainText('This device')
    await expect(bound).toContainText('Never')

    // Add
    await card.getByRole('button', { name: 'Add passkey' }).click()
    const addDialog = page.getByRole('dialog', { name: 'Add passkey' })
    await addDialog.getByLabel('Name').fill('Work laptop')
    await addDialog.getByRole('button', { name: 'Continue' }).click()
    await expect(page.getByText('Passkey added')).toBeVisible()
    await expect(addDialog).toBeHidden()
    await expect(
      card.getByRole('row').filter({ hasText: 'Work laptop' }),
    ).toBeVisible()
    const create = calls.requests.find(
      (call) => call.method === 'POST' && call.path === '/account/passkeys',
    )
    expect(create?.request.postDataJSON()).toEqual({ name: 'Work laptop' })
    const verification = calls.requests.find(
      (call) =>
        call.method === 'PUT' &&
        call.path === '/account/passkeys/passkey00000000000000003/verification',
    )
    const verificationBody = verification?.request.postDataJSON()
    expect(verificationBody.challengeId).toBe('challenge000000000000002')
    expect(verificationBody.credential.type).toBe('public-key')
    expect(typeof verificationBody.credential.response.attestationObject).toBe(
      'string',
    )

    // Rename
    await bound.getByRole('button', { name: 'Rename passkey' }).click()
    const nameInput = card.getByRole('textbox', { name: 'Passkey name' })
    await nameInput.fill('Security key')
    await nameInput.press('Enter')
    await expect(page.getByText('Passkey renamed')).toBeVisible()
    await expect(
      card.getByRole('row').filter({ hasText: 'Security key' }),
    ).toBeVisible()
    const rename = calls.requests.find(
      (call) =>
        call.method === 'PATCH' &&
        call.path === `/account/passkeys/${DEVICE_BOUND.$id}`,
    )
    expect(rename?.request.postDataJSON()).toEqual({ name: 'Security key' })

    // Delete
    await synced.getByRole('button', { name: 'Delete passkey' }).click()
    const deleteDialog = page.getByRole('dialog', { name: 'Delete passkey' })
    await expect(deleteDialog).toContainText('MacBook')
    await deleteDialog
      .getByRole('button', { name: 'Delete', exact: true })
      .click()
    await expect(page.getByText('Passkey deleted')).toBeVisible()
    await expect(synced).toHaveCount(0)
    expect(countCalls(calls, 'DELETE', `/account/passkeys/${SYNCED.$id}`)).toBe(
      1,
    )
  })

  test('a stale session is told to sign in again', async ({ page }) => {
    await useProfile(page, 'cloud')
    await mockAppwriteApi(page, {
      signedIn: true,
      createError: {
        message: 'Reauthentication required.',
        code: 401,
        type: 'user_reauthentication_required',
      },
    })
    await addVirtualAuthenticator(page)
    await openSecurity(page)

    await passkeysCard(page)
      .getByRole('button', { name: 'Add passkey' })
      .click()
    await page
      .getByRole('dialog', { name: 'Add passkey' })
      .getByRole('button', { name: 'Continue' })
      .click()

    await expect(
      page.getByText(
        'For your security, sign in again before changing your passkeys.',
      ),
    ).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Sign in again' }),
    ).toBeVisible()
  })

  test('browsers without passkey support see a note instead', async ({
    page,
  }) => {
    await useProfile(page, 'cloud')
    await mockAppwriteApi(page, { signedIn: true, passkeys: [SYNCED] })
    await page.addInitScript(() => {
      Reflect.deleteProperty(window, 'PublicKeyCredential')
    })
    await openSecurity(page)

    await expect(passkeysCard(page)).toContainText(
      'Passkeys are not supported in this browser.',
    )
    await expect(
      passkeysCard(page).getByRole('button', { name: 'Add passkey' }),
    ).toHaveCount(0)
    await expect(passkeysCard(page)).toContainText('MacBook')
  })

  test('self-hosted consoles have no passkey sign-in or account card', async ({
    page,
  }) => {
    await useProfile(page, 'self-hosted')
    await mockAppwriteApi(page)
    await openSignIn(page)
    await expect(
      page.getByRole('button', { name: 'Login', exact: true }),
    ).toBeVisible()
    await expect(passkeyButton(page)).toHaveCount(0)

    await page.unrouteAll({ behavior: 'ignoreErrors' })
    const calls = await mockAppwriteApi(page, {
      signedIn: true,
      passkeys: [SYNCED],
    })
    await openSecurity(page)
    await expect(passkeysCard(page)).toHaveCount(0)
    expect(countCalls(calls, 'GET', '/account/passkeys')).toBe(0)
  })
})
