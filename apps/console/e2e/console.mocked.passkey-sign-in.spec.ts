import { generateKeyPairSync } from 'node:crypto'
import type { CDPSession, Page, Request } from '@playwright/test'
import { expect, test } from './fixtures'

/**
 * The sign-in page's "Sign in with a passkey" button. The Appwrite API is mocked
 * at the network layer and Chromium's virtual authenticator answers the browser's
 * passkey prompt, so the specs assert the requests the button sends.
 */

const CREDENTIAL_ID = Buffer.from('passkey-sign-in-spec')
const USER_ID = 'user000000000000000001'
const NOW = '2026-10-12T09:30:00.000+00:00'

type Calls = {
  /** Runs before the next challenge is answered. */
  beforeChallenge?: () => Promise<void>
  challenges: Request[]
  verifications: Request[]
  sessions: Request[]
}

async function mockAppwriteApi(page: Page): Promise<Calls> {
  const calls: Calls = { challenges: [], verifications: [], sessions: [] }
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
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

    if (apiPath === '/account/tokens/passkey' && request.method() === 'POST') {
      calls.challenges.push(request)
      const beforeChallenge = calls.beforeChallenge
      calls.beforeChallenge = undefined
      await beforeChallenge?.()
      return json(201, {
        $id: `challenge${calls.challenges.length}`,
        $createdAt: NOW,
        passkeyId: '',
        expire: new Date(Date.now() + 300_000).toISOString(),
        publicKey: {
          challenge: 'Y2hhbGxlbmdlLWZvci10aGUtc3BlYw',
          timeout: 300_000,
          rpId: 'localhost',
          allowCredentials: [],
          userVerification: 'required',
        },
      })
    }
    if (apiPath === '/account/tokens/passkey' && request.method() === 'PUT') {
      calls.verifications.push(request)
      return json(201, {
        $id: 'token',
        $createdAt: NOW,
        userId: USER_ID,
        secret: 'passkey-secret',
        expire: new Date(Date.now() + 60_000).toISOString(),
        phrase: '',
      })
    }
    if (apiPath === '/account/sessions/token') {
      calls.sessions.push(request)
      return json(201, {
        $id: 'session',
        $createdAt: NOW,
        userId: USER_ID,
        provider: 'passkey',
        factors: ['passkey'],
      })
    }
    return json(apiPath === '/account' ? 401 : 404, {
      code: 401,
      type: 'general_unauthorized_scope',
      message: 'Mock API response',
    })
  })
  return calls
}

async function addAuthenticator(page: Page, withPasskey: boolean) {
  const cdp = await page.context().newCDPSession(page)
  await cdp.send('WebAuthn.enable')
  // No presence until the button is clicked, so the email field's autofill
  // request cannot answer first.
  const { authenticatorId } = await cdp.send(
    'WebAuthn.addVirtualAuthenticator',
    {
      options: {
        protocol: 'ctap2',
        transport: 'internal',
        hasResidentKey: true,
        hasUserVerification: true,
        isUserVerified: true,
        automaticPresenceSimulation: false,
      },
    },
  )
  if (withPasskey) {
    const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'P-256' })
    await cdp.send('WebAuthn.addCredential', {
      authenticatorId,
      credential: {
        credentialId: CREDENTIAL_ID.toString('base64'),
        isResidentCredential: true,
        rpId: 'localhost',
        privateKey: privateKey
          .export({ format: 'der', type: 'pkcs8' })
          .toString('base64'),
        userHandle: Buffer.from(USER_ID).toString('base64'),
        signCount: 0,
      },
    })
  }
  return { cdp, authenticatorId }
}

async function allowPresence({
  cdp,
  authenticatorId,
}: {
  cdp: CDPSession
  authenticatorId: string
}) {
  await cdp.send('WebAuthn.setAutomaticPresenceSimulation', {
    authenticatorId,
    enabled: true,
  })
}

function passkeyButton(page: Page) {
  return page.getByRole('button', { name: 'Sign in with a passkey' })
}

test.describe('Passkey sign-in button (mocked API)', () => {
  test('signs in with the passkey the browser offers', async ({ page }) => {
    const calls = await mockAppwriteApi(page)
    const authenticator = await addAuthenticator(page, true)

    await page.goto('/sign-in')
    await expect(passkeyButton(page)).toBeEnabled()
    await expect.poll(() => calls.challenges.length).toBeGreaterThan(0)
    await page.waitForLoadState('networkidle')
    const autofillChallenges = calls.challenges.length
    // The user is present for the prompt, not for the autofill request it replaces.
    calls.beforeChallenge = () => allowPresence(authenticator)
    await passkeyButton(page).click()

    await expect.poll(() => calls.sessions.length).toBe(1)
    expect(calls.verifications).toHaveLength(1)
    const verification = calls.verifications[0].postDataJSON()
    expect(verification.challengeId).toBe(`challenge${autofillChallenges + 1}`)
    expect(verification.credential.id).toBe(CREDENTIAL_ID.toString('base64url'))
    expect(calls.sessions[0].postDataJSON()).toEqual({
      userId: USER_ID,
      secret: 'passkey-secret',
    })
  })

  test('a passkey prompt that ends without a passkey stays quiet', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page)
    const authenticator = await addAuthenticator(page, false)

    await page.goto('/sign-in')
    await expect(passkeyButton(page)).toBeEnabled()
    await expect.poll(() => calls.challenges.length).toBeGreaterThan(0)
    await page.waitForLoadState('networkidle')
    const autofillChallenges = calls.challenges.length
    await allowPresence(authenticator)
    await passkeyButton(page).click()

    // The prompt's challenge, then autofill comes back with a new one.
    await expect
      .poll(() => calls.challenges.length)
      .toBeGreaterThanOrEqual(autofillChallenges + 2)
    await expect(passkeyButton(page)).toBeEnabled()
    await expect(page.locator('[data-sonner-toast]')).toHaveCount(0)
    expect(calls.verifications).toHaveLength(0)
    await expect(page).toHaveURL(/\/sign-in$/)
  })

  test('the sign-up page has no passkey button', async ({ page }) => {
    await mockAppwriteApi(page)

    await page.goto('/sign-up')
    await expect(
      page.getByRole('button', { name: 'Sign up', exact: true }),
    ).toBeVisible()
    await expect(passkeyButton(page)).toHaveCount(0)
  })
})
