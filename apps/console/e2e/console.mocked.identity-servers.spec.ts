import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Identity servers (preview) holds the project's LDAP directory, and LDAP shows up
 * as an auth method next to it. The Appwrite API is mocked at the network layer
 * and the real console pages are asserted.
 */

const NOW = '2026-11-13T09:30:00.000+00:00'

const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'project.ldap.write',
  'teams.read',
  'teams.write',
] satisfies Models.Roles['scopes']

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
  name: 'Halden Engineering',
  billingPlan: 'tier-0',
  billingEmail: ACCOUNT.email,
  status: 'active',
  prefs: {},
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT_ID = 'proj0000000000000000001'

const CONFIGURED = {
  ldapHosts: ['dc01.halden.example', 'dc02.halden.example'],
  ldapPort: 636,
  ldapEncryption: 'ldaps',
  ldapTls: true,
  ldapCaCertificate: '',
  ldapTimeout: 10,
  ldapBaseDn: 'ou=people,dc=halden,dc=example',
  ldapBindDn: 'cn=appwrite,ou=services,dc=halden,dc=example',
  ldapBindPassword: '',
  ldapUsernameAttribute: 'sAMAccountName',
  ldapUserFilter: '(objectClass=person)',
  ldapIdAttribute: 'objectGUID',
  ldapEmailAttribute: 'mail',
  ldapNameAttribute: 'displayName',
  ldapPhoneAttribute: 'mobile',
  ldapRequiredGroupDn: 'cn=staff,ou=groups,dc=halden,dc=example',
} satisfies Partial<Models.Project>

const UNCONFIGURED = {
  ...CONFIGURED,
  ldapHosts: [],
  ldapBaseDn: '',
  ldapBindDn: '',
  ldapUsernameAttribute: 'uid',
  ldapUserFilter: '',
  ldapIdAttribute: 'entryUUID',
  ldapNameAttribute: 'cn',
  ldapPhoneAttribute: '',
  ldapRequiredGroupDn: '',
} satisfies Partial<Models.Project>

/** `listed: false` is a server on a response format before 2.8.0, which leaves ldap out of authMethods. */
function project(
  ldap: Partial<Models.Project>,
  { enabled = false, listed = true } = {},
) {
  return {
    $id: PROJECT_ID,
    $createdAt: NOW,
    $updatedAt: NOW,
    name: 'Deskpass',
    teamId: ORGANIZATION.$id,
    region: 'default',
    status: 'active',
    services: [],
    authMethods: [
      { $id: 'email-password', enabled: true },
      ...(listed ? [{ $id: 'ldap', enabled }] : []),
    ],
    ...ldap,
  }
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
  }
}

/** Every `/v1` call, on any region host, is answered here; the app itself is served live. */
async function mockAppwriteApi(
  page: Page,
  current: ReturnType<typeof project>,
): Promise<{ ldapUpdates: Request[] }> {
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  const calls = { ldapUpdates: [] as Request[] }

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

    if (apiPath === '/project/ldap' && request.method() === 'PATCH') {
      calls.ldapUpdates.push(request)
      return json(200, current)
    }

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') return json(200, ACCOUNT.prefs)
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/health/version') return json(200, { version: '1.8.0' })
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
      return json(200, { total: 1, projects: [current] })
    if (
      apiPath === `/projects/${PROJECT_ID}` ||
      apiPath === `/organization/projects/${PROJECT_ID}` ||
      apiPath === `/projects/${PROJECT_ID}/console-access` ||
      apiPath === '/project'
    )
      return json(200, current)
    if (apiPath === '/project/platforms')
      return json(200, { total: 0, platforms: [] })

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return calls
}

async function useProfile(page: Page, identityServers: boolean) {
  await page.addInitScript((enabled) => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({
        id: 'self-hosted',
        features: { identityServers: enabled },
      }),
    )
  }, identityServers)
}

async function openIdentityServers(page: Page) {
  await page.goto(`/projects/${PROJECT_ID}/settings/identity-servers`, {
    waitUntil: 'domcontentloaded',
  })
  await expect(page.locator('#ldap-base-dn')).toBeVisible({ timeout: 60_000 })
}

test.describe('identity servers (mocked API)', () => {
  test('shows every saved LDAP setting', async ({ page }) => {
    await useProfile(page, true)
    await mockAppwriteApi(page, project(CONFIGURED))
    await openIdentityServers(page)

    await expect(page.getByText('dc01.halden.example')).toBeVisible()
    await expect(page.getByText('dc02.halden.example')).toBeVisible()
    await expect(page.locator('#ldap-port')).toHaveValue('')
    await expect(page.locator('#ldap-port')).toHaveAttribute(
      'placeholder',
      '636',
    )
    await expect(page.locator('#ldap-tls')).toBeChecked()
    await expect(page.locator('#ldap-timeout')).toHaveValue('10')
    await expect(page.locator('#ldap-base-dn')).toHaveValue(
      CONFIGURED.ldapBaseDn,
    )
    await expect(page.locator('#ldap-bind-dn')).toHaveValue(
      CONFIGURED.ldapBindDn,
    )
    await expect(page.locator('#ldap-bind-password')).toHaveValue('')
    await expect(page.locator('#ldap-username-attribute')).toHaveValue(
      'sAMAccountName',
    )
    await expect(page.locator('#ldap-user-filter')).toHaveValue(
      '(objectClass=person)',
    )
    await expect(page.locator('#ldap-id-attribute')).toHaveValue('objectGUID')
    await expect(page.locator('#ldap-email-attribute')).toHaveValue('mail')
    await expect(page.locator('#ldap-name-attribute')).toHaveValue(
      'displayName',
    )
    await expect(page.locator('#ldap-phone-attribute')).toHaveValue('mobile')
    await expect(page.locator('#ldap-required-group-dn')).toHaveValue(
      CONFIGURED.ldapRequiredGroupDn,
    )
    await expect(page.getByRole('button', { name: 'Update' })).toBeDisabled()

    await page.screenshot({
      path: test.info().outputPath('identity-servers.png'),
      fullPage: true,
    })
  })

  test('asks for the bind password again when certificate verification changes', async ({
    page,
  }) => {
    await useProfile(page, true)
    const calls = await mockAppwriteApi(page, project(CONFIGURED))
    await openIdentityServers(page)

    await page.locator('#ldap-tls').click()
    const update = page.getByRole('button', { name: 'Update' })
    await expect(update).toBeDisabled()
    await expect(
      page.getByText('Enter the bind password again to save'),
    ).toBeVisible()

    await page.locator('#ldap-bind-password').fill('service-secret')
    await expect(update).toBeEnabled()
    await update.click()

    await expect.poll(() => calls.ldapUpdates.length).toBe(1)
    expect(calls.ldapUpdates[0].postDataJSON()).toEqual({
      tls: false,
      bindPassword: 'service-secret',
    })
  })

  test('switching to anonymous search clears the bind password', async ({
    page,
  }) => {
    await useProfile(page, true)
    const calls = await mockAppwriteApi(page, project(CONFIGURED))
    await openIdentityServers(page)

    await page.locator('#ldap-bind-dn').fill('')
    await expect(
      page.getByText('Enter the bind password again to save'),
    ).toHaveCount(0)
    await page.getByRole('button', { name: 'Update' }).click()

    await expect.poll(() => calls.ldapUpdates.length).toBe(1)
    expect(calls.ldapUpdates[0].postDataJSON()).toEqual({
      bindDn: '',
      bindPassword: '',
    })
  })

  test('enabling needs a host and a base DN', async ({ page }) => {
    await useProfile(page, true)
    const calls = await mockAppwriteApi(page, project(UNCONFIGURED))
    await openIdentityServers(page)

    await page.locator('#ldap-enabled').click()
    await page.getByRole('button', { name: 'Update' }).click()

    await expect(
      page.getByText(
        'Add at least one host and a base DN to enable LDAP sign-in.',
      ),
    ).toBeVisible()
    expect(calls.ldapUpdates).toHaveLength(0)
  })

  test('auth settings lists LDAP while the preview is on', async ({ page }) => {
    await useProfile(page, true)
    await mockAppwriteApi(page, project(UNCONFIGURED))

    await page.goto(`/projects/${PROJECT_ID}/auth/settings`, {
      waitUntil: 'domcontentloaded',
    })
    const ldap = page.locator('#ldap')
    await expect(ldap).toBeVisible({ timeout: 60_000 })
    // Nothing to sign in against yet.
    await expect(ldap).toBeDisabled()
    await expect(
      page.getByRole('link', { name: 'Identity servers' }),
    ).toBeVisible()

    await page.screenshot({
      path: test.info().outputPath('auth-settings.png'),
      fullPage: true,
    })
  })

  test('auth settings hides LDAP when the server leaves it out', async ({
    page,
  }) => {
    await useProfile(page, true)
    await mockAppwriteApi(page, project(CONFIGURED, { listed: false }))

    await page.goto(`/projects/${PROJECT_ID}/auth/settings`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page.locator('#email-password')).toBeVisible({
      timeout: 60_000,
    })
    await expect(page.locator('#ldap')).toHaveCount(0)
  })

  test('auth settings hides LDAP while the preview is off', async ({
    page,
  }) => {
    await useProfile(page, false)
    await mockAppwriteApi(page, project(CONFIGURED))

    await page.goto(`/projects/${PROJECT_ID}/auth/settings`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page.locator('#email-password')).toBeVisible({
      timeout: 60_000,
    })
    await expect(page.locator('#ldap')).toHaveCount(0)
  })
})
