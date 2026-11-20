import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Organization single sign-on, behind the `sso` experiment, on the Teams API.
 *
 * Settings → Single sign-on lists the organization's SSO domains
 * (`teams.listSSODomains`), claims one (`teams.createSSODomain`), checks its TXT
 * record (`teams.updateSSODomainStatus`) and deletes it; its identity providers
 * are listed, added, updated and deleted the same way, each with the role people
 * join with. The organization can require single sign-on
 * (`teams.updateSSORequired`); a member it refuses is sent to sign in with SSO.
 * The sign-in page looks up the providers for a work email
 * (`account.listSSOProviders`) and starts at the chosen one. The API is mocked at
 * the network layer, so the real pages run; what the page asks the server to do
 * is asserted on the requests it sends.
 */

const NOW = '2026-11-18T09:30:00.000+00:00'

const ACCOUNT = {
  $id: 'user000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Demo Owner',
  registration: NOW,
  status: true,
  labels: [],
  passwordUpdate: NOW,
  email: 'owner@example.com',
  phone: '',
  emailVerification: true,
  phoneVerification: false,
  mfa: false,
  prefs: {},
  targets: [],
  accessedAt: NOW,
} satisfies Partial<Models.User<Models.Preferences>>

const ORGANIZATION = {
  $id: 'acme',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme',
  total: 1,
  billingPlan: 'tier-1',
  billingEmail: ACCOUNT.email,
  status: 'active',
} satisfies Partial<Models.Organization<Models.Preferences>>

const SETTINGS = `/organizations/${ORGANIZATION.$id}/settings`
const SSO = `${SETTINGS}/sso`

type Domain = {
  $id: string
  $createdAt: string
  $updatedAt: string
  teamId: string
  domain: string
  status: 'unverified' | 'verified'
  recordName: string
  recordValue: string
}

function domain(name: string, status: Domain['status']): Domain {
  return {
    $id: `domain-${name}`,
    $createdAt: NOW,
    $updatedAt: NOW,
    teamId: ORGANIZATION.$id,
    domain: name,
    status,
    recordName: `_appwrite.${name}`,
    recordValue: `team:${ORGANIZATION.$id}`,
  }
}

type Provider = {
  $id: string
  $createdAt: string
  $updatedAt: string
  teamId: string
  type: 'oidc'
  name: string
  roles: string[]
  enabled: boolean
  clientId: string
  clientSecretSet: boolean
  wellKnownURL: string
  authorizationURL: string
  tokenURL: string
  userInfoURL: string
}

function provider(name: string, roles: string[], enabled = true): Provider {
  return {
    $id: `provider-${name.toLowerCase().replace(/\s+/g, '-')}`,
    $createdAt: NOW,
    $updatedAt: NOW,
    teamId: ORGANIZATION.$id,
    type: 'oidc',
    name,
    roles,
    enabled,
    clientId: `${name.toLowerCase()}-client`,
    clientSecretSet: true,
    wellKnownURL: 'https://idp.example.com/.well-known/openid-configuration',
    authorizationURL: '',
    tokenURL: '',
    userInfoURL: '',
  }
}

type Member = {
  $id: string
  $createdAt: string
  userId: string
  userName: string
  userEmail: string
  teamId: string
  roles: string[]
  joined: string
  confirm: boolean
  mfa: boolean
}

function member(
  name: string,
  email: string,
  roles: string[],
  confirm = true,
): Member {
  const id = email.replace(/[^a-z0-9]/gi, '')
  return {
    $id: `membership-${id}`,
    $createdAt: NOW,
    userId: `user-${id}`,
    userName: name,
    userEmail: email,
    teamId: ORGANIZATION.$id,
    roles,
    joined: confirm ? NOW : '',
    confirm,
    mfa: false,
  }
}

interface Mock {
  /** The organization's SSO domains */
  domains: Domain[]
  /** Domains whose TXT record the "DNS" holds */
  published: Set<string>
  /** The organization's identity providers */
  providers: Provider[]
  /** The organization role of the signed-in account */
  role: 'owner' | 'developer'
  /** The organization's members */
  members: Member[]
  /** Whether the organization requires single sign-on */
  ssoRequired: boolean
  /** The organization refuses the signed-in member until they sign in through SSO */
  refuseWithoutSSO: boolean
  sent: {
    creates: Array<{ domain: string }>
    verifies: string[]
    deletes: string[]
    providerCreates: Array<Record<string, unknown>>
    providerUpdates: Array<{
      providerId: string
      body: Record<string, unknown>
    }>
    providerDeletes: string[]
    /** Require SSO switches sent */
    requires: boolean[]
    /** Emails the sign-in page looked providers up for */
    lookups: string[]
    /** Provider IDs the browser was sent to sign in through */
    signIns: string[]
  }
}

function corsHeaders(route: Route): Record<string, string> {
  return {
    'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': '*',
    'access-control-allow-methods': '*',
  }
}

async function mockAppwriteApi(
  page: Page,
  options: Partial<
    Pick<
      Mock,
      | 'role'
      | 'domains'
      | 'providers'
      | 'members'
      | 'ssoRequired'
      | 'refuseWithoutSSO'
    >
  > = {},
): Promise<Mock> {
  const mock: Mock = {
    domains: options.domains ?? [domain('acme.com', 'verified')],
    published: new Set(),
    providers: options.providers ?? [],
    role: options.role ?? 'owner',
    members: options.members ?? [],
    ssoRequired: options.ssoRequired ?? false,
    refuseWithoutSSO: options.refuseWithoutSSO ?? false,
    sent: {
      creates: [],
      verifies: [],
      deletes: [],
      providerCreates: [],
      providerUpdates: [],
      providerDeletes: [],
      requires: [],
      lookups: [],
      signIns: [],
    },
  }
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin

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
    const method = request.method()
    const organization = () => ({
      ...ORGANIZATION,
      ssoRequired: mock.ssoRequired,
    })
    // An organization that requires SSO refuses its own routes and its projects.
    if (
      mock.refuseWithoutSSO &&
      (apiPath.startsWith(`/teams/${ORGANIZATION.$id}`) ||
        apiPath.startsWith(`/organizations/${ORGANIZATION.$id}`) ||
        apiPath === '/organization/projects')
    ) {
      return json(403, {
        message:
          'This organization requires single sign-on. Sign in with SSO to continue.',
        code: 403,
        type: 'sso_required',
        version: '1.0',
      })
    }

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') {
      return method === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, {})
    }
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/health/version') return json(200, { version: '2.0.0' })
    if (apiPath === '/console/variables') return json(200, {})
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, {
        roles: [mock.role],
        scopes: ['teams.read', 'teams.write', 'projects.read'],
      })
    if (apiPath === '/organizations' || apiPath === '/teams')
      return json(200, { total: 1, teams: [organization()] })
    if (
      apiPath === `/organizations/${ORGANIZATION.$id}` ||
      apiPath === `/teams/${ORGANIZATION.$id}`
    )
      return json(200, organization())
    if (apiPath.endsWith('/memberships'))
      return json(200, {
        total: mock.members.length,
        memberships: mock.members,
      })
    if (apiPath === '/projects' || apiPath === '/organization/projects')
      return json(200, { total: 0, projects: [] })

    const sso = `/teams/${ORGANIZATION.$id}/sso`
    if (apiPath === `${sso}/required` && method === 'PATCH') {
      const { required } = request.postDataJSON()
      mock.sent.requires.push(required)
      mock.ssoRequired = required
      return json(200, organization())
    }
    if (apiPath === `${sso}/domains` && method === 'GET') {
      return json(200, { total: mock.domains.length, domains: mock.domains })
    }
    if (apiPath === `${sso}/domains` && method === 'POST') {
      const body = request.postDataJSON()
      mock.sent.creates.push(body)
      const created = domain(body.domain, 'unverified')
      mock.domains.push(created)
      return json(201, created)
    }
    const status = apiPath.match(
      /^\/teams\/[^/]+\/sso\/domains\/([^/]+)\/status$/,
    )
    if (status && method === 'PATCH') {
      mock.sent.verifies.push(status[1])
      const found = mock.domains.find((item) => item.$id === status[1])
      if (!found || !mock.published.has(found.domain)) {
        // The server's detail names its resolver; the dialog shows its own copy
        return json(400, {
          message:
            'DNS TXT query to 172.16.238.100 failed. (region: Frankfurt)',
          code: 400,
          type: 'rule_verification_failed',
          version: '1.0',
        })
      }
      found.status = 'verified'
      return json(200, found)
    }
    const oneDomain = apiPath.match(/^\/teams\/[^/]+\/sso\/domains\/([^/]+)$/)
    if (oneDomain && method === 'DELETE') {
      mock.sent.deletes.push(oneDomain[1])
      mock.domains = mock.domains.filter((item) => item.$id !== oneDomain[1])
      return route.fulfill({ status: 204, headers })
    }
    if (apiPath === `${sso}/providers` && method === 'GET') {
      return json(200, {
        total: mock.providers.length,
        providers: mock.providers,
      })
    }
    if (apiPath === `${sso}/providers/oidc` && method === 'POST') {
      const body = request.postDataJSON()
      mock.sent.providerCreates.push(body)
      const created = { ...provider(body.name, body.roles), ...body }
      delete created.clientSecret
      mock.providers.push(created)
      return json(201, created)
    }
    const oidc = apiPath.match(
      /^\/teams\/[^/]+\/sso\/providers\/oidc\/([^/]+)$/,
    )
    if (oidc && method === 'PUT') {
      const body = request.postDataJSON()
      mock.sent.providerUpdates.push({ providerId: oidc[1], body })
      const index = mock.providers.findIndex((item) => item.$id === oidc[1])
      const rest = { ...body }
      delete rest.clientSecret
      mock.providers[index] = { ...mock.providers[index], ...rest }
      return json(200, mock.providers[index])
    }
    const oneProvider = apiPath.match(
      /^\/teams\/[^/]+\/sso\/providers\/([^/]+)$/,
    )
    if (oneProvider && method === 'DELETE') {
      mock.sent.providerDeletes.push(oneProvider[1])
      mock.providers = mock.providers.filter(
        (item) => item.$id !== oneProvider[1],
      )
      return route.fulfill({ status: 204, headers })
    }
    if (apiPath === '/account/sessions/sso/providers') {
      const email = url.searchParams.get('email') ?? ''
      mock.sent.lookups.push(email)
      const verified = mock.domains.some(
        (item) =>
          item.status === 'verified' && email.endsWith(`@${item.domain}`),
      )
      const offered = verified
        ? mock.providers
            .filter((item) => item.enabled)
            .map(({ $id, name }) => ({ $id, name }))
        : []
      return json(200, { total: offered.length, providers: offered })
    }
    if (apiPath === '/account/sessions/sso') {
      mock.sent.signIns.push(url.searchParams.get('providerId') ?? '')
      return route.fulfill({
        status: 200,
        headers: { ...headers, 'content-type': 'text/html' },
        body: '<p>Company login</p>',
      })
    }

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return mock
}

/** Turn the experiment on, as the quality team's Previews toggle does. */
async function enableSso(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: { sso: true } }),
    )
  })
}

async function openSso(page: Page) {
  await page.goto(SSO, { waitUntil: 'domcontentloaded' })
  await expect(page.getByRole('heading', { name: 'Domains' })).toBeVisible({
    timeout: 45_000,
  })
}

const settingsNav = (page: Page) =>
  page.getByRole('link', { name: 'Single sign-on' })

test.describe('organization single sign-on', () => {
  test('stays hidden while the experiment is off', async ({ page }) => {
    await mockAppwriteApi(page)

    await page.goto(SSO, { waitUntil: 'domcontentloaded' })
    await expect(page).not.toHaveURL(/\/settings\/sso/, { timeout: 45_000 })
    await expect(settingsNav(page)).toHaveCount(0)
  })

  test('is for owners only', async ({ page }) => {
    const mock = await mockAppwriteApi(page, { role: 'developer' })
    await enableSso(page)

    await page.goto(SSO, { waitUntil: 'domcontentloaded' })
    await expect(page).not.toHaveURL(/\/settings\/sso/, { timeout: 45_000 })
    await expect(settingsNav(page)).toHaveCount(0)
    expect(mock.sent.creates).toHaveLength(0)
  })

  test("lists the organization's domains and whether each is verified", async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      domains: [
        domain('acme.com', 'verified'),
        domain('acme.dev', 'unverified'),
      ],
    })
    await enableSso(page)
    await openSso(page)

    const verified = page.getByRole('row', { name: /acme\.com/ })
    await expect(verified).toContainText('Verified')
    await expect(verified.getByRole('button', { name: 'Verify' })).toHaveCount(
      0,
    )

    const pending = page.getByRole('row', { name: /acme\.dev/ })
    await expect(pending).toContainText('Unverified')
    await expect(pending.getByRole('button', { name: 'Verify' })).toBeVisible()

    // Domains carry no roles any more: providers do
    await expect(page.getByText('Roles for new members')).toHaveCount(0)
  })

  test('claims a domain, shows its record, and verifies once the record is out', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    await enableSso(page)
    await openSso(page)

    await page.getByRole('button', { name: 'Add domain' }).click()
    const add = page.getByRole('dialog', { name: 'Add email domain' })
    await add.getByLabel('Domain').fill('devs.acme.io')
    await add.getByRole('button', { name: 'Add', exact: true }).click()

    await expect
      .poll(() => mock.sent.creates)
      .toEqual([{ domain: 'devs.acme.io' }])

    // The record is named the way DNS providers expect it: relative to the root domain
    const verify = page.getByRole('dialog', { name: 'Verify domain' })
    await expect(verify).toContainText('devs.acme.io')
    await expect(verify).toContainText('_appwrite.devs')
    await expect(verify).not.toContainText('_appwrite.devs.acme.io')
    await expect(verify).toContainText(`team:${ORGANIZATION.$id}`)

    // Not out yet: a plain message, not the resolver's details
    await verify.getByRole('button', { name: 'Verify', exact: true }).click()
    await expect(verify).toContainText('The TXT record was not found yet.')
    await expect(verify).not.toContainText('172.16.238.100')

    mock.published.add('devs.acme.io')
    await verify.getByRole('button', { name: 'Verify', exact: true }).click()
    await expect(verify).toBeHidden()
    await expect(
      page.getByRole('row', { name: /devs\.acme\.io/ }),
    ).toContainText('Verified')
    await expect
      .poll(() => mock.sent.verifies)
      .toEqual(['domain-devs.acme.io', 'domain-devs.acme.io'])
  })

  test('deletes a domain', async ({ page }) => {
    const mock = await mockAppwriteApi(page)
    await enableSso(page)
    await openSso(page)

    await page.getByRole('button', { name: 'Actions for acme.com' }).click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await page
      .getByRole('dialog', { name: 'Delete email domain' })
      .getByRole('button', { name: 'Delete', exact: true })
      .click()

    await expect.poll(() => mock.sent.deletes).toEqual(['domain-acme.com'])
    await expect(page.getByRole('row', { name: /acme\.com/ })).toHaveCount(0)
    await expect(page.getByText('No email domains yet')).toBeVisible()
  })

  test('requires single sign-on for the organization after confirming, and stops requiring it', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      providers: [provider('Okta', ['developer'])],
    })
    await enableSso(page)
    await openSso(page)

    const required = page.getByRole('switch', { name: 'Require SSO' })
    await expect(required).toBeEnabled()
    await expect(required).not.toBeChecked()

    // Turning it on asks first, and says owners keep their sign-in
    await required.click()
    const confirm = page.getByRole('dialog', { name: 'Require single sign-on' })
    await expect(confirm).toContainText('Owners keep their usual sign-in')
    await expect(confirm).toContainText(
      'Their other organizations are not affected',
    )
    await confirm.getByRole('button', { name: 'Cancel' }).click()
    expect(mock.sent.requires).toEqual([])
    await expect(required).not.toBeChecked()

    await required.click()
    await confirm.getByRole('button', { name: 'Require SSO' }).click()
    await expect.poll(() => mock.sent.requires).toEqual([true])
    await expect(required).toBeChecked()

    // Turning it off needs no confirmation
    await required.click()
    await expect.poll(() => mock.sent.requires).toEqual([true, false])
    await expect(required).not.toBeChecked()
  })

  test('cannot require single sign-on without a verified domain and an enabled provider', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      domains: [domain('acme.com', 'unverified')],
      providers: [provider('Okta', ['developer'])],
    })
    await enableSso(page)
    await openSso(page)

    const required = page.getByRole('switch', { name: 'Require SSO' })
    await expect(required).toBeDisabled()
    await expect(
      page.getByText('Verify a domain to require single sign-on.'),
    ).toBeVisible()

    mock.domains = [domain('acme.com', 'verified')]
    mock.providers = [provider('Okta', ['developer'], false)]
    await page.reload()
    await expect(required).toBeDisabled()
    await expect(
      page.getByText('Enable an identity provider to require single sign-on.'),
    ).toBeVisible()
    expect(mock.sent.requires).toEqual([])
  })

  test('sends a member the organization refuses to sign in with SSO, and back', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      role: 'developer',
      ssoRequired: true,
      refuseWithoutSSO: true,
      providers: [provider('Okta', ['developer'])],
    })
    await enableSso(page)

    await page.goto(`/organizations/${ORGANIZATION.$id}`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page).toHaveURL(/\/sso\?/, { timeout: 45_000 })
    const url = new URL(page.url())
    expect(url.searchParams.get('required')).toBe('true')
    expect(url.searchParams.get('redirect')).toBe(
      `/organizations/${ORGANIZATION.$id}`,
    )
    await expect(
      page.getByText('This organization requires single sign-on.'),
    ).toBeVisible()
  })

  test('connects an identity provider with the roles people join with', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    await enableSso(page)
    await openSso(page)
    await expect(page.getByText('No identity providers yet')).toBeVisible()

    await page.getByRole('button', { name: 'Add provider' }).click()
    const add = page.getByRole('dialog', { name: 'Add identity provider' })
    // The redirect URL to register at the provider: CE's OAuth2 callback for `sso`
    await expect(add.getByLabel('Redirect URL')).toHaveValue(
      /\/account\/sessions\/oauth2\/callback\/sso\/console$/,
    )
    await add.getByLabel('Name').fill('Okta')
    await add.getByRole('button', { name: 'Add role' }).click()
    await page.getByRole('menuitem', { name: /Billing/ }).click()
    await add.getByLabel('Client ID').fill('okta-client')
    await add.getByLabel('Client secret').fill('okta-secret')
    await add
      .getByLabel('Discovery URL')
      .fill('https://acme.okta.com/.well-known/openid-configuration')
    await add.getByRole('button', { name: 'Add', exact: true }).click()

    await expect(add).toBeHidden()
    await expect
      .poll(() => mock.sent.providerCreates)
      .toEqual([
        {
          name: 'Okta',
          roles: ['developer', 'billing'],
          clientId: 'okta-client',
          clientSecret: 'okta-secret',
          wellKnownURL:
            'https://acme.okta.com/.well-known/openid-configuration',
          authorizationURL: '',
          tokenURL: '',
          userInfoURL: '',
          enabled: true,
        },
      ])
    const row = page.getByRole('row', { name: /Okta/ })
    await expect(row).toContainText('Developer')
    await expect(row).toContainText('Billing')
    await expect(row).toContainText('okta-client')
  })

  test('needs a discovery URL or all three endpoints', async ({ page }) => {
    const mock = await mockAppwriteApi(page)
    await enableSso(page)
    await openSso(page)

    await page.getByRole('button', { name: 'Add provider' }).click()
    const add = page.getByRole('dialog', { name: 'Add identity provider' })
    await add.getByLabel('Name').fill('Keycloak')
    await add.getByLabel('Client ID').fill('kc-client')
    await add.getByLabel('Client secret').fill('kc-secret')
    await add
      .getByRole('button', { name: 'Set the endpoints manually' })
      .click()
    await add
      .getByLabel('Authorization URL')
      .fill('https://kc.example.com/auth')
    await add.getByLabel('Token URL').fill('https://kc.example.com/token')
    const submit = add.getByRole('button', { name: 'Add', exact: true })
    await expect(submit).toBeDisabled()

    await add
      .getByLabel('User info URL')
      .fill('https://kc.example.com/userinfo')
    await expect(submit).toBeEnabled()
    expect(mock.sent.providerCreates).toHaveLength(0)
  })

  test('updates a provider without resending its secret, disables and deletes it', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      providers: [provider('Okta', ['developer'])],
    })
    await enableSso(page)
    await openSso(page)

    await page.getByRole('button', { name: 'Actions for Okta' }).click()
    await page.getByRole('menuitem', { name: 'Update' }).click()
    const update = page.getByRole('dialog', {
      name: 'Update identity provider',
    })
    await expect(update.getByLabel('Client secret')).toHaveValue('')
    await update.getByLabel('Name').fill('Okta (employees)')
    await update.getByRole('button', { name: 'Update', exact: true }).click()

    await expect(update).toBeHidden()
    await expect.poll(() => mock.sent.providerUpdates).toHaveLength(1)
    const sentUpdate = mock.sent.providerUpdates[0]
    expect(sentUpdate.providerId).toBe('provider-okta')
    expect(sentUpdate.body.name).toBe('Okta (employees)')
    // An empty secret field keeps the stored secret
    expect(sentUpdate.body).not.toHaveProperty('clientSecret')

    await page.getByRole('switch', { name: 'Enable Okta (employees)' }).click()
    await expect
      .poll(() => mock.sent.providerUpdates.at(-1)?.body.enabled)
      .toBe(false)

    await page
      .getByRole('button', { name: 'Actions for Okta (employees)' })
      .click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await page
      .getByRole('dialog', { name: 'Delete identity provider' })
      .getByRole('button', { name: 'Delete', exact: true })
      .click()
    await expect
      .poll(() => mock.sent.providerDeletes)
      .toEqual(['provider-okta'])
    await expect(page.getByText('No identity providers yet')).toBeVisible()
  })

  test('signs in through the only provider of a work email', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      providers: [provider('Okta', ['developer'])],
    })
    await page.goto('/sso', { waitUntil: 'domcontentloaded' })

    // A domain without single sign-on is refused on the page
    await page.getByLabel('Work email').fill('erin@gmail.com')
    await page.getByRole('button', { name: 'Continue', exact: true }).click()
    await expect(
      page.getByText('Single sign-on is not set up for gmail.com.'),
    ).toBeVisible({ timeout: 45_000 })
    expect(mock.sent.signIns).toHaveLength(0)

    // One provider: straight to the company login
    await page.getByLabel('Work email').fill('erin@acme.com')
    await page.getByRole('button', { name: 'Continue', exact: true }).click()
    await expect(page.getByText('Company login')).toBeVisible()
    expect(mock.sent.signIns).toEqual(['provider-okta'])
    expect(mock.sent.lookups).toEqual(['erin@gmail.com', 'erin@acme.com'])
  })

  test('lets people choose when several providers sign in their domain', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      providers: [
        provider('Okta', ['developer']),
        provider('For billing', ['billing']),
        provider('Old IdP', ['developer'], false),
      ],
    })
    await page.goto('/sso', { waitUntil: 'domcontentloaded' })

    await page.getByLabel('Work email').fill('erin@acme.com')
    await page.getByRole('button', { name: 'Continue', exact: true }).click()
    await expect(page.getByText('Choose how to sign in')).toBeVisible({
      timeout: 45_000,
    })
    // Disabled providers are not offered
    await expect(page.getByRole('button', { name: 'Old IdP' })).toHaveCount(0)

    await page.getByRole('button', { name: 'For billing' }).click()
    await expect(page.getByText('Company login')).toBeVisible()
    expect(mock.sent.signIns).toEqual(['provider-for-billing'])
  })

  test('shows why a single sign-on failed', async ({ page }) => {
    await mockAppwriteApi(page)
    const error = JSON.stringify({
      message:
        "Single sign-on couldn't complete sign-in (email_domain_not_verified). Please try again.",
      type: 'user_oauth2_provider_failure',
      code: 424,
    })
    await page.goto(`/sso?error=${encodeURIComponent(error)}`, {
      waitUntil: 'domcontentloaded',
    })
    // In plain words, without the internal code or a retry that cannot help
    await expect(
      page.getByText("isn't on a domain your organization verified"),
    ).toBeVisible({ timeout: 45_000 })
    await expect(page.getByText(/email_domain_not_verified/)).toHaveCount(0)

    // Any other reason is shown as the server gave it
    const other = JSON.stringify({
      message: 'Single sign-on could not finish.',
    })
    await page.goto(`/sso?error=${encodeURIComponent(other)}`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(
      page.getByText('Single sign-on could not finish.'),
    ).toBeVisible()
  })

  test('flags External members by the verified domains', async ({ page }) => {
    await mockAppwriteApi(page, {
      domains: [
        domain('acme.com', 'verified'),
        domain('acme.dev', 'unverified'),
      ],
      members: [
        member('Demo Owner', 'owner@acme.com', ['owner']),
        member('Sara Lee', 'Sara@ACME.com', ['developer']),
        member('Alex Contractor', 'alex@gmail.com', ['developer']),
        member('Kim Park', 'kim@dev.acme.com', ['analyst']),
        member('', 'new@acme.dev', ['developer'], false),
      ],
    })
    await enableSso(page)
    await page.goto(`${SETTINGS}/members`, { waitUntil: 'domcontentloaded' })

    const row = (name: string) => page.getByRole('row', { name })
    await expect(row('Alex Contractor')).toContainText('External', {
      timeout: 45_000,
    })
    // Members on a verified domain get no label: they are most of the list
    await expect(row('Demo Owner')).not.toContainText('External')
    await expect(row('Sara Lee')).not.toContainText('External')
    await expect(page.getByText('Internal', { exact: true })).toHaveCount(0)
    // A subdomain is its own domain, verified on its own
    await expect(row('Kim Park')).toContainText('External')
    // Unverified domains do not count
    await expect(row('new@acme.dev')).toContainText('External')
  })

  test('flags no one without a verified domain', async ({ page }) => {
    await mockAppwriteApi(page, {
      domains: [domain('acme.com', 'unverified')],
      members: [
        member('Demo Owner', 'owner@acme.com', ['owner']),
        member('Alex Contractor', 'alex@gmail.com', ['developer']),
      ],
    })
    await enableSso(page)
    await page.goto(`${SETTINGS}/members`, { waitUntil: 'domcontentloaded' })

    await expect(
      page.getByRole('row', { name: 'Alex Contractor' }),
    ).toBeVisible({ timeout: 45_000 })
    await expect(page.getByText('External', { exact: true })).toHaveCount(0)
    await expect(page.getByText('Internal', { exact: true })).toHaveCount(0)
  })
})
