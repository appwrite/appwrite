import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Passkeys: the relying party policy and the auth method it gates.
 *
 * The Appwrite API is mocked at the network layer and the real console pages are
 * asserted: what the policy card reads back, what its Update button sends, and how
 * turning the Passkey auth method on asks for a domain when the policy needs one.
 *
 * On Cloud, passkeys are rolled out per user: only a console user whose prefs
 * carry `flags-passkeys` sees them. Self-hosted consoles always show them.
 */

const NOW = '2026-09-09T09:30:00.000+00:00'

/** Cloud's rollout flag, written by its `task-manage-flags`. */
const PASSKEYS_FLAG = 'flags-passkeys'

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
  prefs: { [PASSKEYS_FLAG]: true },
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

type Platform = ReturnType<typeof webPlatform>

/** Mirrors the server: web platforms on the relying party ID, plus localhost. */
function passkeyOrigins(rpId: string, platforms: Platform[]): string[] {
  const hostnames = platforms.map((platform) => platform.hostname)
  const origins = hostnames
    .filter(
      (hostname) =>
        rpId !== '' &&
        rpId !== 'localhost' &&
        (hostname === rpId || hostname.endsWith(`.${rpId}`)),
    )
    .map((hostname) => `https://${hostname}`)
  return hostnames.includes('localhost')
    ? [...origins, 'http://localhost', 'https://localhost']
    : origins
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
  /** The console user's prefs; carries the passkeys flag by default. */
  accountPrefs?: Models.Preferences
  /** When set, the policy PATCH is refused with this JSON body. */
  patchError?: { message: string; code: number; type: string }
  /** The project's platforms; `example.com` and `app.example.com` by default. */
  platforms?: Platform[]
}

type Calls = {
  policyPatches: Request[]
  methodPatches: Request[]
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
  const platforms = options.platforms ?? CONFIGURED_PLATFORMS
  let passkeyEnabled = options.passkeyEnabled ?? false
  const account = { ...ACCOUNT, prefs: options.accountPrefs ?? ACCOUNT.prefs }
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  const calls: Calls = { policyPatches: [], methodPatches: [] }

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

    if (apiPath === '/account') return json(200, account)
    if (apiPath === '/account/prefs') return json(200, account.prefs)
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
    if (apiPath === '/console/variables') return json(200, {})
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

  test('users without the passkeys flag see no passkey settings', async ({
    page,
  }) => {
    await useProfile(page, 'cloud')
    await mockAppwriteApi(page, { accountPrefs: {} })

    await openAuthSettings(page)
    await expect(page.locator('#jwt')).toBeVisible()
    await expect(page.locator(`#${PASSKEY_ID}`)).toHaveCount(0)
    await expect(
      page.getByRole('link', { name: 'Passkey policies', exact: true }),
    ).toHaveCount(0)

    await page.goto(`/projects/${PROJECT_ID}/auth/policies/passkeys`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/auth/policies/sessions$`),
      { timeout: 30_000 },
    )
    const navigation = policiesNavigation(page)
    await expect(
      navigation.getByRole('link', { name: 'Passwords', exact: true }),
    ).toBeVisible()
    await expect(
      navigation.getByRole('link', { name: 'Passkeys', exact: true }),
    ).toHaveCount(0)
    await expect(card(page)).toHaveCount(0)
  })

  test('self-hosted consoles show passkey settings without a flag', async ({
    page,
  }) => {
    await useProfile(page, 'self-hosted')
    await mockAppwriteApi(page, { accountPrefs: {} })

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
})
