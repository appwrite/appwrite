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
  ldapVerification: true,
  ldapCertificate: '',
  ldapTimeout: 10,
  ldapBaseDn: 'ou=people,dc=halden,dc=example',
  ldapBindDn: 'cn=appwrite,ou=services,dc=halden,dc=example',
  ldapBindPassword: '',
  ldapUserFilter: '(sAMAccountName={username})',
  ldapRequiredGroupDn: 'cn=staff,ou=groups,dc=halden,dc=example',
  ldapIdAttribute: 'objectGUID',
  ldapEmailAttribute: 'mail',
  ldapNameAttribute: 'displayName',
  ldapPhoneAttribute: 'mobile',
} satisfies Partial<Models.Project>

/** What the API reports for a project that never saved LDAP settings. */
const UNCONFIGURED = {
  ...CONFIGURED,
  ldapHosts: [],
  ldapBaseDn: '',
  ldapBindDn: '',
  ldapUserFilter: '(uid={username})',
  ldapRequiredGroupDn: '',
  ldapIdAttribute: 'entryUUID',
  ldapNameAttribute: 'cn',
  ldapPhoneAttribute: '',
} satisfies Partial<Models.Project>

const CERTIFICATE = `-----BEGIN CERTIFICATE-----
MIIBszCCAVmgAwIBAgIUHalden
-----END CERTIFICATE-----`

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
    ldapEnabled: enabled,
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

/** A paste event as the browser fires it; a real clipboard is not available headless. */
async function paste(page: Page, selector: string, text: string) {
  await page.locator(selector).evaluate((element, value) => {
    const clipboardData = new DataTransfer()
    clipboardData.setData('text/plain', value)
    element.dispatchEvent(
      new ClipboardEvent('paste', {
        clipboardData,
        bubbles: true,
        cancelable: true,
      }),
    )
  }, text)
}

/** Full-page screenshot once the console's loading overlay is gone. */
async function screenshot(page: Page, name: string) {
  await expect(page.locator('[data-fullscreen-loader]')).toBeHidden()
  await page.screenshot({
    path: test.info().outputPath(name),
    fullPage: true,
  })
}

async function chooseEncryption(
  page: Page,
  name: 'None' | 'LDAPS' | 'StartTLS',
) {
  await page.locator('#ldap-encryption').click()
  await page.getByRole('option', { name, exact: true }).click()
  await expect(page.getByRole('listbox')).toBeHidden()
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
    await expect(page.locator('#ldap-encryption')).toHaveText('LDAPS')
    await expect(page.locator('#ldap-verification')).toBeChecked()
    await expect(page.locator('#ldap-certificate')).toHaveValue('')
    await expect(page.locator('#ldap-timeout')).toHaveValue('10')
    await expect(page.locator('#ldap-base-dn')).toHaveValue(
      CONFIGURED.ldapBaseDn,
    )
    await expect(page.locator('#ldap-bind-dn')).toHaveValue(
      CONFIGURED.ldapBindDn,
    )
    await expect(page.locator('#ldap-bind-password')).toHaveValue('')
    await expect(page.locator('#ldap-username-attribute')).toHaveCount(0)
    await expect(page.locator('#ldap-user-filter')).toHaveValue(
      '(sAMAccountName={username})',
    )
    await expect(page.locator('#ldap-required-group-dn')).toHaveValue(
      CONFIGURED.ldapRequiredGroupDn,
    )
    await expect(page.locator('#ldap-id-attribute')).toHaveValue('objectGUID')
    await expect(page.locator('#ldap-email-attribute')).toHaveValue('mail')
    await expect(page.locator('#ldap-name-attribute')).toHaveValue(
      'displayName',
    )
    await expect(page.locator('#ldap-phone-attribute')).toHaveValue('mobile')
    await expect(
      page.getByText(
        'Using Active Directory? Set the user filter to (sAMAccountName={username}) and the ID attribute to objectGUID.',
      ),
    ).toBeVisible()
    await expect(page.getByRole('button', { name: 'Update' })).toBeDisabled()

    await screenshot(page, 'identity-servers.png')
  })

  test('asks for the bind password again when certificate verification changes', async ({
    page,
  }) => {
    await useProfile(page, true)
    const calls = await mockAppwriteApi(page, project(CONFIGURED))
    await openIdentityServers(page)

    await page.locator('#ldap-verification').click()
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
      verification: false,
      bindPassword: 'service-secret',
    })
  })

  test('sends the renamed certificate and user filter fields', async ({
    page,
  }) => {
    await useProfile(page, true)
    const calls = await mockAppwriteApi(page, project(CONFIGURED))
    await openIdentityServers(page)

    await page.locator('#ldap-verification').click()
    await page.locator('#ldap-certificate').fill(CERTIFICATE)
    await page.locator('#ldap-user-filter').fill('(mail={username})')
    await page.locator('#ldap-bind-password').fill('service-secret')
    await page.getByRole('button', { name: 'Update' }).click()

    await expect.poll(() => calls.ldapUpdates.length).toBe(1)
    const body = calls.ldapUpdates[0].postDataJSON()
    expect(body).toEqual({
      verification: false,
      certificate: CERTIFICATE,
      bindPassword: 'service-secret',
      userFilter: '(mail={username})',
    })
    expect(body).not.toHaveProperty('usernameAttribute')
    expect(body).not.toHaveProperty('tls')
    expect(body).not.toHaveProperty('caCertificate')
  })

  test('hides the certificate settings without encryption and keeps them', async ({
    page,
  }) => {
    await useProfile(page, true)
    await mockAppwriteApi(page, project(CONFIGURED))
    await openIdentityServers(page)

    await page.locator('#ldap-certificate').fill(CERTIFICATE)
    await chooseEncryption(page, 'None')
    await expect(page.locator('#ldap-verification')).toHaveCount(0)
    await expect(page.locator('#ldap-certificate')).toHaveCount(0)
    await expect(page.locator('#ldap-port')).toHaveAttribute(
      'placeholder',
      '389',
    )
    await screenshot(page, 'identity-servers-none.png')

    await chooseEncryption(page, 'StartTLS')
    await expect(page.locator('#ldap-verification')).toBeChecked()
    await expect(page.locator('#ldap-certificate')).toHaveValue(CERTIFICATE)
  })

  test('a pasted LDAP URL fills host, encryption, port and base DN', async ({
    page,
  }) => {
    await useProfile(page, true)
    await mockAppwriteApi(
      page,
      project({ ...UNCONFIGURED, ldapEncryption: 'none', ldapPort: 389 }),
    )
    await openIdentityServers(page)
    await expect(page.locator('#ldap-encryption')).toHaveText('None')

    await paste(
      page,
      '#ldap-hosts',
      'ldaps://dc1.corp.example:636/dc=corp,dc=example',
    )

    await expect(
      page.getByRole('button', { name: 'Remove dc1.corp.example' }),
    ).toBeVisible()
    await expect(page.getByRole('button', { name: /^Remove / })).toHaveCount(1)
    await expect(page.locator('#ldap-hosts')).toHaveValue('')
    await expect(page.locator('#ldap-encryption')).toHaveText('LDAPS')
    await expect(page.locator('#ldap-port')).toHaveValue('')
    await expect(page.locator('#ldap-port')).toHaveAttribute(
      'placeholder',
      '636',
    )
    await expect(page.locator('#ldap-base-dn')).toHaveValue(
      'dc=corp,dc=example',
    )
  })

  test('a pasted LDAP URL offers its base DN instead of replacing one', async ({
    page,
  }) => {
    await useProfile(page, true)
    await mockAppwriteApi(page, project(CONFIGURED))
    await openIdentityServers(page)

    await paste(
      page,
      '#ldap-hosts',
      'ldap://dc03.halden.example:1389/dc=halden,dc=example',
    )

    await expect(
      page.getByRole('button', { name: 'Remove dc03.halden.example' }),
    ).toBeVisible()
    await expect(page.locator('#ldap-encryption')).toHaveText('None')
    await expect(page.locator('#ldap-port')).toHaveValue('1389')
    await expect(page.locator('#ldap-base-dn')).toHaveValue(
      CONFIGURED.ldapBaseDn,
    )
    await expect(
      page.getByText('Use dc=halden,dc=example as the base DN?'),
    ).toBeVisible()

    await page.getByRole('button', { name: 'Use', exact: true }).click()
    await expect(page.locator('#ldap-base-dn')).toHaveValue(
      'dc=halden,dc=example',
    )
    await expect(
      page.getByText('Use dc=halden,dc=example as the base DN?'),
    ).toHaveCount(0)
  })

  test('a user filter without {username} blocks saving', async ({ page }) => {
    await useProfile(page, true)
    const calls = await mockAppwriteApi(page, project(UNCONFIGURED))
    await openIdentityServers(page)

    const filter = page.locator('#ldap-user-filter')
    const update = page.getByRole('button', { name: 'Update' })
    await expect(filter).toHaveValue('(uid={username})')
    await expect(filter).toHaveAttribute('placeholder', '(uid={username})')

    await filter.fill('(objectClass=person)')
    await expect(
      page.getByText('The user filter must contain {username}.'),
    ).toBeVisible()
    await expect(filter).toHaveAttribute('aria-invalid', 'true')
    await expect(update).toBeDisabled()

    // Empty means the default filter, which is already saved.
    await filter.fill('')
    await expect(
      page.getByText('The user filter must contain {username}.'),
    ).toHaveCount(0)
    await expect(update).toBeDisabled()

    await filter.fill('(sAMAccountName={username})')
    await expect(update).toBeEnabled()
    await update.click()

    await expect.poll(() => calls.ldapUpdates.length).toBe(1)
    expect(calls.ldapUpdates[0].postDataJSON()).toEqual({
      userFilter: '(sAMAccountName={username})',
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

    await screenshot(page, 'auth-settings.png')
  })

  test('reads LDAP from ldapEnabled when authMethods leave it out', async ({
    page,
  }) => {
    await useProfile(page, true)
    await mockAppwriteApi(
      page,
      project(CONFIGURED, { enabled: true, listed: false }),
    )

    await page.goto(`/projects/${PROJECT_ID}/auth/settings`, {
      waitUntil: 'domcontentloaded',
    })
    const ldap = page.locator('#ldap')
    await expect(ldap).toBeVisible({ timeout: 60_000 })
    await expect(ldap).toBeChecked()

    await openIdentityServers(page)
    await expect(page.locator('#ldap-enabled')).toBeChecked()
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
