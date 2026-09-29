import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
// Type-only: a value import would pull `@/lib/appwrite/sdk` and its `import.meta.env`
// reads into Playwright's loader, which has no Vite transform and crashes on them.
import type { PasskeyPolicy } from '@/lib/passkey-policy'
import { expect, test } from './fixtures'

/**
 * Passkeys: the relying party policy and the auth method it gates.
 *
 * The Appwrite API is mocked at the network layer and the real console pages are
 * asserted: what the policy card reads back, what its Update button sends, and that
 * the Passkey auth method only becomes switchable once the policy is configured.
 */

const NOW = '2026-09-09T09:30:00.000+00:00'

/** Mirrors `PasskeyPolicyId` and `PasskeyAuthMethodId`. */
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
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT_ID = 'proj0000000000000000001'

const CONFIGURED: PasskeyPolicy = {
  rpId: 'example.com',
  origins: ['https://example.com', 'https://app.example.com'],
}

const UNCONFIGURED: PasskeyPolicy = { rpId: '', origins: [] }

function project() {
  return {
    $id: PROJECT_ID,
    $createdAt: NOW,
    $updatedAt: NOW,
    name: 'Acme Docs',
    teamId: ORGANIZATION.$id,
    region: 'default',
    status: 'active',
    // The SDK types service and method ids as enums, and `passkey` is not in the
    // pinned build yet, so the literals need the cast.
    services: ALL_SERVICES.map(($id) => ({
      $id,
      enabled: true,
    })) as Models.Project['services'],
    authMethods: [
      ...AUTH_METHODS.map(($id) => ({ $id, enabled: true })),
      { $id: PASSKEY_ID, enabled: false },
    ] as Models.Project['authMethods'],
  } satisfies Partial<Models.Project>
}

type MockOptions = {
  policy?: PasskeyPolicy
  /** When set, the policy PATCH is refused with this JSON body. */
  patchError?: { message: string; code: number; type: string }
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
  let policy = options.policy ?? CONFIGURED
  const projectDocument = project()
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
      policy = { ...policy, ...request.postDataJSON() }
      return json(200, projectDocument)
    }

    if (apiPath === PASSKEY_METHOD_PATH && request.method() === 'PATCH') {
      calls.methodPatches.push(request)
      const method = projectDocument.authMethods.find(
        (m) => (m.$id as string) === PASSKEY_ID,
      )
      if (method) method.enabled = request.postDataJSON()?.enabled
      return json(200, projectDocument)
    }

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') return json(200, {})
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
      return json(200, { total: 1, projects: [projectDocument] })
    if (
      apiPath === '/project' ||
      apiPath === `/projects/${PROJECT_ID}` ||
      apiPath === `/projects/${PROJECT_ID}/console-access`
    )
      return json(200, projectDocument)
    // `fetchProjectAuthSecurity` swallows failures on both of these, so a
    // mis-pathed mock would read back as the default snapshot, not as an error.
    if (apiPath === '/project/policies')
      return json(200, {
        total: 1,
        policies: [{ $id: PASSKEY_ID, ...policy }],
      })
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

function card(page: Page) {
  return page.getByTestId('passkey-relying-party-card')
}

function rpIdInput(page: Page) {
  return page.locator('#passkey-rp-id')
}

function originInput(page: Page, index: number) {
  return card(page).getByRole('textbox', { name: `Origin ${index}` })
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
  test('the policy card reads back the relying party and origins', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { policy: CONFIGURED })
    await openPasskeyPolicies(page)

    await expect(rpIdInput(page)).toHaveValue('example.com')
    await expect(originInput(page, 1)).toHaveValue('https://example.com')
    await expect(originInput(page, 2)).toHaveValue('https://app.example.com')
    await expect(updateButton(page)).toBeDisabled()
    await expect(
      card(page).getByRole('link', { name: 'Learn more', exact: true }),
    ).toHaveAttribute('href', /\/docs\/products\/auth\/passkeys$/)
  })

  test('updating sends only the edited fields', async ({ page }) => {
    const calls = await mockAppwriteApi(page, { policy: CONFIGURED })
    await openPasskeyPolicies(page)

    await card(page)
      .getByRole('button', { name: 'Remove origin 2', exact: true })
      .click()
    await card(page)
      .getByRole('button', { name: 'Add origin', exact: true })
      .click()
    await originInput(page, 2).fill('https://admin.example.com/')

    await updateButton(page).click()

    await expect(page.getByText('Updated passkey settings.')).toBeVisible()
    expect(calls.policyPatches).toHaveLength(1)
    expect(new URL(calls.policyPatches[0].url()).pathname).toBe(
      `/v1${PASSKEY_POLICY_PATH}`,
    )
    expect(calls.policyPatches[0].postDataJSON()).toEqual({
      origins: ['https://example.com', 'https://admin.example.com'],
    })
    await expect(updateButton(page)).toBeDisabled()
  })

  test('invalid values are flagged and cannot be sent', async ({ page }) => {
    const calls = await mockAppwriteApi(page, { policy: CONFIGURED })
    await openPasskeyPolicies(page)

    await originInput(page, 1).fill('http://example.com')
    await expect(card(page)).toContainText(
      'Origins must use https://, or http:// on localhost.',
    )
    await expect(updateButton(page)).toBeDisabled()

    await originInput(page, 1).fill('https://other.com')
    await expect(card(page)).toContainText(
      'Origins must be on the relying party ID or one of its subdomains.',
    )
    await expect(updateButton(page)).toBeDisabled()
    expect(calls.policyPatches).toHaveLength(0)
  })

  test('a refusal from the backend is shown as written', async ({ page }) => {
    const calls = await mockAppwriteApi(page, {
      policy: CONFIGURED,
      patchError: {
        message: RP_ID_LOCKED_ERROR,
        code: 400,
        type: 'general_argument_invalid',
      },
    })
    await openPasskeyPolicies(page)

    await rpIdInput(page).fill('app.example.com')
    await originInput(page, 1).fill('https://app.example.com')
    await card(page)
      .getByRole('button', { name: 'Remove origin 2', exact: true })
      .click()
    await updateButton(page).click()

    await expect(page.getByText(RP_ID_LOCKED_ERROR)).toBeVisible()
    expect(calls.policyPatches).toHaveLength(1)
    expect(calls.policyPatches[0].postDataJSON()).toEqual({
      rpId: 'app.example.com',
      origins: ['https://app.example.com'],
    })
  })

  test('the passkey method stays off until the policy is configured', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page, { policy: UNCONFIGURED })
    await openAuthSettings(page)

    const toggle = page.locator(`#${PASSKEY_ID}`)
    await expect(toggle).toBeDisabled()
    await expect(toggle).not.toBeChecked()
    const setupLink = page.getByRole('link', {
      name: 'passkey policies',
      exact: true,
    })
    await expect(setupLink).toBeVisible()

    await setupLink.click()
    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/auth/policies/passkeys$`),
    )

    await rpIdInput(page).fill('example.com')
    await originInput(page, 1).fill('https://example.com')
    await updateButton(page).click()
    await expect(page.getByText('Updated passkey settings.')).toBeVisible()

    await openAuthSettings(page)
    await expect(toggle).toBeEnabled()
    await expect(setupLink).toHaveCount(0)
    await toggle.click()

    await expect(toggle).toBeChecked()
    await expect.poll(() => calls.methodPatches.length).toBe(1)
    expect(calls.methodPatches[0].postDataJSON()).toEqual({ enabled: true })
  })
})
