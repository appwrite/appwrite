import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Passkeys: the relying party policy and the auth method it gates.
 *
 * The Appwrite API is mocked at the network layer and the real console pages are
 * asserted: what the policy card reads back, what its Update button sends, and how
 * turning the Passkey auth method on asks for a domain when the policy needs one.
 */

const NOW = '2026-09-09T09:30:00.000+00:00'

/** Mirrors `ProjectPolicyId.Passkey` and `ProjectAuthMethodId.Passkey`. */
const PASSKEY_ID = 'passkey'

const PASSKEY_POLICY_PATH = `/project/policies/${PASSKEY_ID}`
const PASSKEY_METHOD_PATH = `/project/auth-methods/${PASSKEY_ID}`

const RP_ID_LOCKED_ERROR =
  'The relying party ID cannot be changed while users have registered passkeys.'

const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
// An owner with every console permission and every service on, so nothing about
// navigation or gating stands between the specs and the pages they open.
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'databases.read',
  'databases.write',
  'tables.write',
  'collections.write',
  'rows.write',
  'documents.write',
  'functions.read',
  'functions.write',
  'buckets.read',
  'buckets.write',
  'keys.write',
  'platforms.write',
  'webhooks.write',
  'users.write',
  'teams.read',
  'teams.write',
  'messages.read',
  'messages.write',
  'topics.write',
  'providers.write',
  'subscribers.write',
  'sites.read',
  'sites.write',
  'domains.write',
  'executions.write',
  'migrations.write',
  'vcs.write',
  'rules.write',
  'events.read',
  'billing.read',
] satisfies Models.Roles['scopes']
const ALL_SERVICES = [
  'account',
  'avatars',
  'databases',
  'tablesdb',
  'locale',
  'health',
  'project',
  'storage',
  'teams',
  'users',
  'vcs',
  'sites',
  'functions',
  'proxy',
  'graphql',
  'migrations',
  'messaging',
  'advisor',
  'oauth2',
] as const
const AUTH_METHODS = [
  'email-password',
  'magic-url',
  'email-otp',
  'anonymous',
  'invites',
  'jwt',
] as const

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

const ORGANIZATION = {
  $id: 'org00000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme Docs',
  billingPlan: 'tier-0',
  billingEmail: ACCOUNT.email,
  status: 'active',
  prefs: {},
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT_ID = 'proj0000000000000000001'

function webPlatform(hostname: string) {
  return {
    $id: `web-${hostname}`,
    $createdAt: NOW,
    $updatedAt: NOW,
    name: hostname,
    type: 'web',
    hostname,
  }
}

/** Google's sample debug key, the one project-sesame-426206.appspot.com trusts. */
const FINGERPRINT =
  '4F:20:47:1F:D9:9A:BA:96:47:8D:59:27:C2:C8:A6:EA:8E:D2:8D:14:C0:B6:A2:39:99:9F:A3:4D:47:3D:FA:11'
const ANDROID_ORIGIN =
  'android:apk-key-hash:TyBHH9maupZHjVknwsim6o7SjRTAtqI5mZ-jTUc9-hE'

function androidPlatform(fingerprints: string[] = []) {
  return {
    $id: 'android-app',
    $createdAt: NOW,
    $updatedAt: NOW,
    name: 'Android app',
    type: 'android',
    applicationId: 'com.example.app',
    fingerprints,
  }
}

type Platform =
  | ReturnType<typeof webPlatform>
  | ReturnType<typeof androidPlatform>

function hostnames(platforms: Platform[]): string[] {
  return platforms.flatMap((platform) =>
    'hostname' in platform ? [platform.hostname] : [],
  )
}

/**
 * Mirrors the server: Android apps by their signing certificates and web
 * platforms on the relying party ID, plus localhost.
 */
function passkeyOrigins(rpId: string, platforms: Platform[]): string[] {
  const production = rpId !== '' && rpId !== 'localhost'
  const apps = platforms.flatMap((platform) =>
    production && 'fingerprints' in platform
      ? platform.fingerprints.map(
          (fingerprint) =>
            `android:apk-key-hash:${Buffer.from(fingerprint.replaceAll(':', ''), 'hex').toString('base64url')}`,
        )
      : [],
  )
  const origins = hostnames(platforms)
    .filter(
      (hostname) =>
        production && (hostname === rpId || hostname.endsWith(`.${rpId}`)),
    )
    .map((hostname) => `https://${hostname}`)
  return hostnames(platforms).includes('localhost')
    ? [...apps, ...origins, 'http://localhost', 'https://localhost']
    : [...apps, ...origins]
}

const CONFIGURED_PLATFORMS = [
  webPlatform('example.com'),
  webPlatform('app.example.com'),
]

const APP_PLATFORMS = [webPlatform('app.example.com'), webPlatform('localhost')]

/**
 * Like the server's V29 filter: `passkey` is listed in authMethods only for
 * the 2.4.0 response format and later, so a console that asks for an older
 * format never sees the method's real state.
 */
function listsPasskey(request: Request): boolean {
  const format = (request.headers()['x-appwrite-response-format'] ?? '')
    .split('.')
    .map(Number)
  const [major = 0, minor = 0] = format
  return major > 2 || (major === 2 && minor >= 4)
}

function project(passkeyEnabled: boolean, request: Request) {
  return {
    $id: PROJECT_ID,
    $createdAt: NOW,
    $updatedAt: NOW,
    name: 'Acme Docs',
    teamId: ORGANIZATION.$id,
    region: 'default',
    status: 'active',
    // The SDK types service ids as enums, so the literals need the cast.
    services: ALL_SERVICES.map(($id) => ({
      $id,
      enabled: true,
    })) as Models.Project['services'],
    authMethods: [
      ...AUTH_METHODS.map(($id) => ({ $id, enabled: true })),
      ...(listsPasskey(request)
        ? [{ $id: PASSKEY_ID, enabled: passkeyEnabled }]
        : []),
    ] as Models.Project['authMethods'],
  } satisfies Partial<Models.Project>
}

type MockOptions = {
  /** The stored relying party ID; `example.com` by default. */
  rpId?: string
  /** Whether the Passkey auth method starts on. */
  passkeyEnabled?: boolean
  /** When set, the policy PATCH is refused with this JSON body. */
  patchError?: { message: string; code: number; type: string }
  /** The Sites domain from the console variables; unset by default. */
  sitesDomain?: string
  /** The project's platforms; `example.com` and `app.example.com` by default. */
  platforms?: Platform[]
}

type Calls = {
  policyPatches: Request[]
  methodPatches: Request[]
  platformUpdates: Request[]
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

/** Every `/v1` call, on any region host, is answered here; the app itself is served live. */
async function mockAppwriteApi(
  page: Page,
  options: MockOptions = {},
): Promise<Calls> {
  let rpId = options.rpId ?? 'example.com'
  const platforms = [...(options.platforms ?? CONFIGURED_PLATFORMS)]
  let passkeyEnabled = options.passkeyEnabled ?? false
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  const calls: Calls = {
    policyPatches: [],
    methodPatches: [],
    platformUpdates: [],
  }

  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const apiPath = url.pathname.match(/^\/v1(\/.*)$/)?.[1]
    if (url.origin === localOrigin && !apiPath) return route.continue()

    const headers = corsHeaders(route)
    if (!apiPath || request.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers })
    }
    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })

    if (apiPath === PASSKEY_POLICY_PATH && request.method() === 'PATCH') {
      calls.policyPatches.push(request)
      if (options.patchError) return json(400, options.patchError)
      rpId = request.postDataJSON().rpId
      return json(200, project(passkeyEnabled, request))
    }

    if (apiPath === PASSKEY_METHOD_PATH && request.method() === 'PATCH') {
      calls.methodPatches.push(request)
      passkeyEnabled = request.postDataJSON()?.enabled === true
      return json(200, project(passkeyEnabled, request))
    }

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') return json(200, ACCOUNT.prefs)
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/health/version') return json(200, { version: '1.8.0' })
    if (apiPath === '/locale')
      return json(200, {
        ip: '203.0.113.10',
        countryCode: 'DE',
        country: 'Germany',
        continentCode: 'EU',
        continent: 'Europe',
        eu: true,
        currency: 'EUR',
      })
    if (apiPath === '/locale/codes')
      return json(200, { total: 0, localeCodes: [] })
    if (apiPath === '/console/variables')
      return json(
        200,
        options.sitesDomain ? { _APP_DOMAIN_SITES: options.sitesDomain } : {},
      )
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, { roles: OWNER_ROLES, scopes: OWNER_SCOPES })
    if (apiPath === '/organizations' || apiPath === '/teams')
      return json(200, { total: 1, teams: [ORGANIZATION] })
    if (
      apiPath === `/organizations/${ORGANIZATION.$id}` ||
      apiPath === `/teams/${ORGANIZATION.$id}`
    )
      return json(200, ORGANIZATION)
    if (apiPath.endsWith('/memberships'))
      return json(200, { total: 0, memberships: [] })
    if (apiPath === '/projects' || apiPath === '/organization/projects')
      return json(200, {
        total: 1,
        projects: [project(passkeyEnabled, request)],
      })
    if (
      apiPath === '/project' ||
      apiPath === `/projects/${PROJECT_ID}` ||
      apiPath === `/projects/${PROJECT_ID}/console-access`
    )
      return json(200, project(passkeyEnabled, request))
    // `fetchProjectAuthSecurity` swallows failures on both of these, so a
    // mis-pathed mock would read back as the default snapshot, not as an error.
    if (apiPath === '/project/policies')
      return json(200, {
        total: 1,
        policies: [
          { $id: PASSKEY_ID, rpId, origins: passkeyOrigins(rpId, platforms) },
        ],
      })
    if (apiPath === '/project/platforms')
      return json(200, { total: platforms.length, platforms })
    const platformId = apiPath.match(
      /^\/project\/platforms(?:\/android)?\/([^/]+)$/,
    )?.[1]
    const index = platforms.findIndex((platform) => platform.$id === platformId)
    if (index !== -1 && request.method() === 'PUT') {
      calls.platformUpdates.push(request)
      platforms[index] = { ...platforms[index], ...request.postDataJSON() }
      return json(200, platforms[index])
    }
    if (index !== -1) return json(200, platforms[index])
    if (apiPath === '/project/mock-phones')
      return json(200, { total: 0, mockNumbers: [] })

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

function policiesNavigation(page: Page) {
  return page.getByRole('navigation', { name: 'Policies navigation' })
}

function card(page: Page) {
  return page.getByTestId('passkey-relying-party-card')
}

function rpIdInput(page: Page) {
  return page.locator('#passkey-rp-id')
}

function updateButton(page: Page) {
  return card(page).getByRole('button', { name: 'Update', exact: true })
}

async function openPasskeyPolicies(page: Page) {
  await page.goto(`/projects/${PROJECT_ID}/auth/policies/passkeys`, {
    waitUntil: 'domcontentloaded',
  })
  await expect(
    page.getByRole('heading', { name: 'Relying party', exact: true }),
  ).toBeVisible({ timeout: 30_000 })
}

async function openAuthSettings(page: Page) {
  await page.goto(`/projects/${PROJECT_ID}/auth/settings`, {
    waitUntil: 'domcontentloaded',
  })
  await expect(
    page.getByRole('heading', { name: 'Auth methods', exact: true }),
  ).toBeVisible({ timeout: 30_000 })
}

test.describe('passkeys (mocked API)', () => {
  test('the policy card reads back the relying party and where passkeys work', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    await openPasskeyPolicies(page)

    await expect(
      policiesNavigation(page).getByRole('link', {
        name: 'Passkeys',
        exact: true,
      }),
    ).toBeVisible()
    await expect(rpIdInput(page)).toHaveValue('example.com')
    const origins = page.getByTestId('passkey-platform-origins')
    await expect(origins).toContainText('https://example.com')
    await expect(origins).toContainText('https://app.example.com')
    await expect(card(page).getByRole('textbox')).toHaveCount(1)
    await expect(updateButton(page)).toBeDisabled()
    await expect(
      card(page).getByRole('link', { name: 'Learn more', exact: true }),
    ).toHaveAttribute('href', /\/docs\/products\/auth\/passkeys$/)
  })

  test('localhost shows once, for any port', async ({ page }) => {
    await mockAppwriteApi(page, { platforms: APP_PLATFORMS })
    await openPasskeyPolicies(page)

    const origins = page.getByTestId('passkey-platform-origins')
    await expect(origins).toContainText('https://app.example.com')
    await expect(origins).toContainText('http://localhost (any port)')
    await expect(origins.getByRole('listitem')).toHaveCount(2)
  })

  test('updating sends only the relying party ID', async ({ page }) => {
    const calls = await mockAppwriteApi(page)
    await openPasskeyPolicies(page)

    await rpIdInput(page).fill('app.example.com')
    // The unsaved domain is previewed from the platforms
    const origins = page.getByTestId('passkey-platform-origins')
    await expect(origins.getByRole('listitem')).toHaveText([
      'https://app.example.com',
    ])
    await updateButton(page).click()

    await expect(
      page.getByText('Updated passkey settings', { exact: true }),
    ).toBeVisible()
    expect(calls.policyPatches).toHaveLength(1)
    expect(new URL(calls.policyPatches[0].url()).pathname).toBe(
      `/v1${PASSKEY_POLICY_PATH}`,
    )
    expect(calls.policyPatches[0].postDataJSON()).toEqual({
      rpId: 'app.example.com',
    })
    await expect(updateButton(page)).toBeDisabled()
  })

  test('an invalid relying party ID is flagged and cannot be sent', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page)
    await openPasskeyPolicies(page)

    await rpIdInput(page).fill('192.168.1.10')
    await expect(card(page)).toContainText(
      'The relying party ID must be a domain, not an IP address.',
    )
    await expect(updateButton(page)).toBeDisabled()

    await rpIdInput(page).fill('https://example.com')
    await expect(card(page)).toContainText(
      'Enter a domain without a scheme or path, like example.com.',
    )
    await expect(updateButton(page)).toBeDisabled()
    expect(calls.policyPatches).toHaveLength(0)
  })

  test('clearing the relying party while the method is on warns first', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { passkeyEnabled: true })
    await openPasskeyPolicies(page)

    const status = page.getByTestId('passkey-method-status')
    await expect(status).toContainText('Enabled')
    await expect(page.getByTestId('passkey-policy-warning')).toHaveCount(0)

    await rpIdInput(page).fill('')
    await expect(page.getByTestId('passkey-policy-warning')).toBeVisible()
    await expect(page.getByTestId('passkey-rp-id-suggestions')).toContainText(
      'example.com',
    )

    await status.click()
    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/auth/settings$`),
    )
  })

  test('a refusal from the backend is shown as written', async ({ page }) => {
    const calls = await mockAppwriteApi(page, {
      patchError: {
        message: RP_ID_LOCKED_ERROR,
        code: 400,
        type: 'general_argument_invalid',
      },
    })
    await openPasskeyPolicies(page)

    await rpIdInput(page).fill('app.example.com')
    await updateButton(page).click()

    await expect(page.getByText(RP_ID_LOCKED_ERROR)).toBeVisible()
    expect(calls.policyPatches).toHaveLength(1)
    expect(calls.policyPatches[0].postDataJSON()).toEqual({
      rpId: 'app.example.com',
    })
    // A refusal leaves the edit on screen to fix rather than resetting it.
    await expect(rpIdInput(page)).toHaveValue('app.example.com')
    await expect(updateButton(page)).toBeEnabled()
  })

  test('turning passkeys on asks for the domain, suggested from web platforms', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page, {
      rpId: '',
      platforms: [webPlatform('app.example.com')],
    })
    await openAuthSettings(page)

    const toggle = page.locator(`#${PASSKEY_ID}`)
    await expect(toggle).toBeEnabled()
    await toggle.click()

    const dialog = page.getByTestId('enable-passkeys-dialog')
    await expect(dialog).toBeVisible()
    await expect(
      dialog.getByRole('radio', { name: 'example.com', exact: true }),
    ).toBeChecked()
    await expect(page.getByTestId('enable-passkeys-origins')).toContainText(
      'https://app.example.com',
    )

    await dialog.getByRole('button', { name: 'Enable', exact: true }).click()

    await expect(dialog).toBeHidden()
    await expect(toggle).toBeChecked()
    expect(calls.policyPatches).toHaveLength(1)
    expect(calls.policyPatches[0].postDataJSON()).toEqual({
      rpId: 'example.com',
    })
    await expect.poll(() => calls.methodPatches.length).toBe(1)
    expect(calls.methodPatches[0].postDataJSON()).toEqual({ enabled: true })
  })

  test('a long Android origin stays inside the enable dialog', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      rpId: '',
      platforms: [
        webPlatform('app.example.com'),
        androidPlatform([FINGERPRINT]),
      ],
    })
    await openAuthSettings(page)
    await page.locator(`#${PASSKEY_ID}`).click()

    const dialog = page.getByTestId('enable-passkeys-dialog')
    const origins = page.getByTestId('enable-passkeys-origins')
    await expect(origins).toContainText('(Android app)')

    const dialogBox = await dialog.boundingBox()
    const originsBox = await origins.boundingBox()
    expect(originsBox!.x + originsBox!.width).toBeLessThanOrEqual(
      dialogBox!.x + dialogBox!.width,
    )
  })

  test('a site on the shared Sites domain is suggested only as itself', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      rpId: '',
      sitesDomain: 'appwrite.network',
      platforms: [webPlatform('myapp.appwrite.network')],
    })
    await openAuthSettings(page)
    await page.locator(`#${PASSKEY_ID}`).click()

    const dialog = page.getByTestId('enable-passkeys-dialog')
    await expect(
      dialog.getByRole('radio', {
        name: 'myapp.appwrite.network',
        exact: true,
      }),
    ).toBeChecked()
    await expect(
      dialog.getByRole('radio', { name: 'appwrite.network', exact: true }),
    ).toHaveCount(0)
  })

  test('suggestions follow the public suffix list', async ({ page }) => {
    await mockAppwriteApi(page, {
      rpId: '',
      platforms: [
        webPlatform('app.example.ne.jp'),
        webPlatform('user.github.io'),
      ],
    })
    await openAuthSettings(page)
    await page.locator(`#${PASSKEY_ID}`).click()

    const dialog = page.getByTestId('enable-passkeys-dialog')
    await expect(dialog.getByRole('radio')).toHaveCount(4)
    for (const domain of [
      'example.ne.jp',
      'app.example.ne.jp',
      'user.github.io',
    ]) {
      await expect(
        dialog.getByRole('radio', { name: domain, exact: true }),
      ).toBeVisible()
    }
    for (const suffix of ['ne.jp', 'github.io']) {
      await expect(
        dialog.getByRole('radio', { name: suffix, exact: true }),
      ).toHaveCount(0)
    }
  })

  test('a localhost platform turns passkeys on before a domain is chosen', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page, {
      rpId: '',
      platforms: [webPlatform('localhost')],
    })
    await openAuthSettings(page)

    const toggle = page.locator(`#${PASSKEY_ID}`)
    await toggle.click()

    await expect(toggle).toBeChecked()
    await expect(page.getByTestId('enable-passkeys-dialog')).toHaveCount(0)
    await expect.poll(() => calls.methodPatches.length).toBe(1)
    expect(calls.policyPatches).toHaveLength(0)
  })

  test('without a matching web platform passkeys cannot be enabled', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page, { rpId: '', platforms: [] })
    await openAuthSettings(page)

    const toggle = page.locator(`#${PASSKEY_ID}`)
    await toggle.click()
    const dialog = page.getByTestId('enable-passkeys-dialog')
    const enable = dialog.getByRole('button', { name: 'Enable', exact: true })
    await expect(enable).toBeDisabled()

    await dialog.getByRole('textbox', { name: 'Domain' }).fill('example.com')
    await expect(page.getByTestId('enable-passkeys-origins')).toContainText(
      'No web platform on this domain yet.',
    )
    await expect(enable).toBeDisabled()

    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
    await expect(toggle).not.toBeChecked()
    expect(calls.policyPatches).toHaveLength(0)
    expect(calls.methodPatches).toHaveLength(0)
  })

  test('a configured policy turns passkeys on without asking', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page)
    await openAuthSettings(page)

    const toggle = page.locator(`#${PASSKEY_ID}`)
    await toggle.click()

    await expect(toggle).toBeChecked()
    await expect(page.getByTestId('enable-passkeys-dialog')).toHaveCount(0)
    await expect.poll(() => calls.methodPatches.length).toBe(1)
  })

  for (const profile of ['cloud', 'self-hosted'] as const) {
    test(`${profile} consoles show passkey settings to every user`, async ({
      page,
    }) => {
      await useProfile(page, profile)
      await mockAppwriteApi(page)

      await openPasskeyPolicies(page)
      await expect(rpIdInput(page)).toHaveValue('example.com')
      await expect(
        policiesNavigation(page).getByRole('link', {
          name: 'Passkeys',
          exact: true,
        }),
      ).toBeVisible()

      await openAuthSettings(page)
      await expect(page.locator(`#${PASSKEY_ID}`)).toBeVisible()
    })
  }

  test('Android apps show where passkeys work by their signing certificate', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      platforms: [...CONFIGURED_PLATFORMS, androidPlatform([FINGERPRINT])],
    })
    await openPasskeyPolicies(page)

    const origins = page.getByTestId('passkey-platform-origins')
    await expect(origins).toContainText(`${ANDROID_ORIGIN} (Android app)`)
    await expect(origins).toContainText('https://example.com')

    // An unsaved domain is previewed the same way the server derives it
    await rpIdInput(page).fill('app.example.com')
    await expect(origins).toContainText(ANDROID_ORIGIN)
    await expect(origins).not.toContainText('https://example.com')
  })

  test('the app drawer saves signing certificate fingerprints', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page, {
      platforms: [...CONFIGURED_PLATFORMS, androidPlatform()],
    })
    await page.goto(`/projects/${PROJECT_ID}/apps`, {
      waitUntil: 'domcontentloaded',
    })
    await page
      .getByRole('button', { name: /Android app/ })
      .first()
      .click({ timeout: 30_000 })

    const fingerprints = page.locator('#platform-fingerprints')
    await expect(fingerprints).toHaveValue('')

    await fingerprints.fill('14:6D:E9')
    await page.getByRole('button', { name: 'Update', exact: true }).click()
    await expect(
      page.getByText('14:6D:E9 is not a SHA-256 fingerprint.', {
        exact: false,
      }),
    ).toBeVisible()
    expect(calls.platformUpdates).toHaveLength(0)

    await fingerprints.fill(`${FINGERPRINT.toLowerCase()}\n${FINGERPRINT}`)
    await page.getByRole('button', { name: 'Update', exact: true }).click()
    await expect.poll(() => calls.platformUpdates.length).toBe(1)
    expect(calls.platformUpdates[0].postDataJSON()).toMatchObject({
      applicationId: 'com.example.app',
      fingerprints: [FINGERPRINT],
    })
  })
})
