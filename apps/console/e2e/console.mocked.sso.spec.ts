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

/** Another organization of the signed-in account, which never refuses it. */
const PERSONAL = {
  ...ORGANIZATION,
  $id: 'personal',
  name: 'Personal Projects',
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
  /** TXT values the "DNS" holds that are not the expected one, by domain */
  wrongValues: Map<string, string>
  /** The organization's billing plan, when the page asks for it */
  plan: Record<string, unknown> | null
  /** The domain and provider lists answer 500 */
  listsFail: boolean
  /** Whether a session exists; a guest's `account.get` answers 401 */
  signedIn: boolean
  /** The account also belongs to `PERSONAL` */
  personal: boolean
  /** The account's prefs, such as the organization it last opened */
  prefs: Record<string, unknown>
  /** The account's sessions */
  sessions: Array<Record<string, unknown>>
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
    /** The full addresses of those sign-ins, with their success and failure URLs */
    signInURLs: URL[]
    /** Account prefs saved */
    prefs: Array<Record<string, unknown>>
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
      | 'plan'
      | 'listsFail'
      | 'signedIn'
      | 'personal'
      | 'prefs'
      | 'sessions'
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
    wrongValues: new Map(),
    plan: options.plan ?? null,
    listsFail: options.listsFail ?? false,
    signedIn: options.signedIn ?? true,
    personal: options.personal ?? false,
    prefs: options.prefs ?? {},
    sessions: options.sessions ?? [],
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
      signInURLs: [],
      prefs: [],
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
    const projectsOrganization =
      request.headers()['x-appwrite-organization'] ?? ORGANIZATION.$id
    if (
      mock.refuseWithoutSSO &&
      (apiPath.startsWith(`/teams/${ORGANIZATION.$id}`) ||
        apiPath.startsWith(`/organizations/${ORGANIZATION.$id}`) ||
        (apiPath === '/organization/projects' &&
          projectsOrganization === ORGANIZATION.$id))
    ) {
      return json(403, {
        message:
          'This organization requires single sign-on. Sign in with SSO to continue.',
        code: 403,
        type: 'sso_required',
        version: '1.0',
      })
    }

    if (apiPath === '/account/sessions/email' && method === 'POST') {
      mock.signedIn = true
      return json(201, {
        $id: 'session-password',
        userId: ACCOUNT.$id,
        provider: 'email',
        current: true,
      })
    }
    if (apiPath.startsWith('/account') && !mock.signedIn) {
      if (apiPath === '/account/sessions/sso/providers') {
        // Answered below: looking providers up needs no session
      } else if (apiPath !== '/account/sessions/sso') {
        return json(401, {
          message: 'User (role: guests) missing scopes (["account"])',
          code: 401,
          type: 'general_unauthorized_scope',
          version: '1.0',
        })
      }
    }
    if (apiPath === '/account')
      return json(200, { ...ACCOUNT, prefs: mock.prefs })
    if (apiPath === '/account/prefs') {
      if (method === 'PATCH') {
        mock.prefs = request.postDataJSON().prefs
        mock.sent.prefs.push(mock.prefs)
      }
      return method === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: mock.prefs })
        : json(200, mock.prefs)
    }
    if (apiPath === '/account/sessions')
      return json(200, { total: mock.sessions.length, sessions: mock.sessions })
    if (apiPath === '/health/version') return json(200, { version: '2.0.0' })
    if (apiPath === '/console/variables') return json(200, {})
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles` ||
      apiPath === `/organizations/${PERSONAL.$id}/roles`
    )
      return json(200, {
        roles: [mock.role],
        scopes: ['teams.read', 'teams.write', 'projects.read'],
      })
    if (apiPath === '/organizations' || apiPath === '/teams') {
      // `fetchOrganizationById` lists with `Query.equal('$id', [id])`
      const ids = url.searchParams
        .getAll('queries[]')
        .map((query) => JSON.parse(query))
        .find((query) => query.method === 'equal' && query.attribute === '$id')
        ?.values as string[] | undefined
      const teams = [organization(), ...(mock.personal ? [PERSONAL] : [])]
      const listed = ids
        ? teams.filter((team) => ids.includes(team.$id))
        : teams
      return json(200, { total: listed.length, teams: listed })
    }
    if (
      apiPath === `/organizations/${ORGANIZATION.$id}` ||
      apiPath === `/teams/${ORGANIZATION.$id}`
    )
      return json(200, organization())
    if (
      mock.personal &&
      (apiPath === `/organizations/${PERSONAL.$id}` ||
        apiPath === `/teams/${PERSONAL.$id}`)
    )
      return json(200, PERSONAL)
    if (apiPath.endsWith('/memberships'))
      return json(200, {
        total: mock.members.length,
        memberships: mock.members,
      })
    if (apiPath === '/projects' || apiPath === '/organization/projects')
      return json(200, { total: 0, projects: [] })

    if (apiPath === `/organizations/${ORGANIZATION.$id}/plan` && mock.plan) {
      return json(200, mock.plan)
    }

    const sso = `/teams/${ORGANIZATION.$id}/sso`
    if (
      mock.listsFail &&
      method === 'GET' &&
      (apiPath === `${sso}/domains` || apiPath === `${sso}/providers`)
    ) {
      return json(500, {
        message: 'Server Error',
        code: 500,
        type: 'general_server_error',
        version: '1.0',
      })
    }
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
      const wrong = found && mock.wrongValues.get(found.domain)
      if (found && wrong !== undefined) {
        // An organization's domain gets the server's own, owner-facing words
        return json(400, {
          message: `The TXT record at _appwrite.${found.domain} holds "${wrong}", but it must be exactly "team:${ORGANIZATION.$id}".`,
          code: 400,
          type: 'rule_verification_failed',
          version: '1.0',
        })
      }
      if (!found || !mock.published.has(found.domain)) {
        return json(400, {
          message: `No TXT record was found at _appwrite.${found?.domain} yet. Add one with the value team:${ORGANIZATION.$id}. DNS changes can take a few minutes to propagate.`,
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
      mock.sent.signInURLs.push(url)
      return route.fulfill({
        status: 200,
        headers: { ...headers, 'content-type': 'text/html' },
        body: '<p>Identity provider login page</p>',
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
    const add = page.getByRole('dialog', { name: 'Add domain' })
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

    // Not out yet: the server says so, and what to add
    await verify.getByRole('button', { name: 'Verify', exact: true }).click()
    await expect(verify).toContainText(
      'No TXT record was found at _appwrite.devs.acme.io yet.',
    )

    // A record with the wrong value is named as such, with the value found
    mock.wrongValues.set('devs.acme.io', ORGANIZATION.$id)
    await verify.getByRole('button', { name: 'Verify', exact: true }).click()
    await expect(verify).toContainText(
      `holds "${ORGANIZATION.$id}", but it must be exactly "team:${ORGANIZATION.$id}".`,
    )
    await expect(verify).not.toContainText('No TXT record was found')

    mock.wrongValues.delete('devs.acme.io')
    mock.published.add('devs.acme.io')
    await verify.getByRole('button', { name: 'Verify', exact: true }).click()
    await expect(verify).toBeHidden()
    await expect(
      page.getByRole('row', { name: /devs\.acme\.io/ }),
    ).toContainText('Verified')
    await expect
      .poll(() => mock.sent.verifies)
      .toEqual([
        'domain-devs.acme.io',
        'domain-devs.acme.io',
        'domain-devs.acme.io',
      ])
  })

  test('deletes a domain', async ({ page }) => {
    const mock = await mockAppwriteApi(page)
    await enableSso(page)
    await openSso(page)

    await page.getByRole('button', { name: 'Actions for acme.com' }).click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await page
      .getByRole('dialog', { name: 'Delete domain' })
      .getByRole('button', { name: 'Delete', exact: true })
      .click()

    await expect.poll(() => mock.sent.deletes).toEqual(['domain-acme.com'])
    await expect(page.getByRole('row', { name: /acme\.com/ })).toHaveCount(0)
    await expect(page.getByText('No domains yet')).toBeVisible()
  })

  test('requires single sign-on for the organization after confirming, and stops requiring it', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      providers: [provider('Okta', ['developer'])],
    })
    await enableSso(page)
    await openSso(page)

    const required = page.getByRole('switch', {
      name: 'Require single sign-on',
    })
    const update = page.getByRole('button', { name: 'Update', exact: true })
    await expect(required).toBeEnabled()
    await expect(required).not.toBeChecked()
    await expect(update).toBeDisabled()

    // The switch is a draft: nothing is sent until Update, which asks first
    // and says owners keep their sign-in
    await required.click()
    expect(mock.sent.requires).toEqual([])
    await update.click()
    const confirm = page.getByRole('dialog', { name: 'Require single sign-on' })
    await expect(confirm).toContainText('Owners keep their usual sign-in')
    await expect(confirm).toContainText(
      'Their other organizations are not affected',
    )
    await confirm.getByRole('button', { name: 'Cancel' }).click()
    expect(mock.sent.requires).toEqual([])

    await update.click()
    await confirm.getByRole('button', { name: 'Require SSO' }).click()
    await expect.poll(() => mock.sent.requires).toEqual([true])
    await expect(required).toBeChecked()
    await expect(update).toBeDisabled()
    // Owners are never locked out, and the card says so
    await expect(required).toHaveAccessibleDescription(
      /Owners can always sign in any way\..*Owners can always sign in with any method, so a broken identity provider never locks your organization out\./,
    )

    // Turning it off needs no confirmation
    await required.click()
    await update.click()
    await expect(confirm).toBeHidden()
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

    // The precondition is the switch's state text
    const required = page.getByRole('switch', {
      name: 'Require single sign-on',
    })
    await expect(required).toBeDisabled()
    await expect(
      page.getByText('Verify a domain to require single sign-on.'),
    ).toBeVisible()
    await expect(required).toHaveAccessibleDescription(
      /Verify a domain to require single sign-on\./,
    )

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

    await page.getByRole('button', { name: 'Add identity provider' }).click()
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

    await page.getByRole('button', { name: 'Add identity provider' }).click()
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
    await add.getByLabel('Token URL').fill('http://kc.example.com/token')
    // Add stays enabled and says what is missing once it is pressed
    const submit = add.getByRole('button', { name: 'Add', exact: true })
    await expect(submit).toBeEnabled()
    await submit.click()
    await expect(
      add.getByText(
        'Enter the discovery URL, or set all three endpoints manually.',
      ),
    ).toBeVisible()
    await expect(add.getByLabel('Discovery URL')).toHaveAccessibleDescription(
      'Enter the discovery URL, or set all three endpoints manually.',
    )
    // The client secret travels to the endpoints, so they are https:// only
    await expect(add.getByLabel('Token URL')).toHaveAccessibleDescription(
      'Use an https:// URL.',
    )
    expect(mock.sent.providerCreates).toHaveLength(0)

    await add.getByLabel('Token URL').fill('https://kc.example.com/token')
    await add
      .getByLabel('User info URL')
      .fill('https://kc.example.com/userinfo')
    await expect(
      add.getByText(
        'Enter the discovery URL, or set all three endpoints manually.',
      ),
    ).toHaveCount(0)
    await submit.click()
    await expect.poll(() => mock.sent.providerCreates).toHaveLength(1)
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
      signedIn: false,
      providers: [provider('Okta', ['developer'])],
    })
    await page.goto('/sso', { waitUntil: 'domcontentloaded' })

    // A domain without single sign-on is refused on the page
    await page.getByLabel('Work email').fill('erin@gmail.com')
    await page.getByRole('button', { name: 'Continue', exact: true }).click()
    await expect(
      page.getByText("Single sign-on isn't set up for gmail.com."),
    ).toBeVisible({ timeout: 45_000 })
    // Another way in, keeping the typed email
    await expect(
      page.getByRole('link', { name: 'Sign in with email and password' }),
    ).toBeVisible()
    await expect(page.getByLabel('Work email')).toHaveValue('erin@gmail.com')
    expect(mock.sent.signIns).toHaveLength(0)

    // One provider: straight to the company login
    await page.getByLabel('Work email').fill('erin@acme.com')
    await page.getByRole('button', { name: 'Continue', exact: true }).click()
    await expect(page.getByText('Identity provider login page')).toBeVisible()
    expect(mock.sent.signIns).toEqual(['provider-okta'])
    expect(mock.sent.lookups).toEqual(['erin@gmail.com', 'erin@acme.com'])
  })

  test('lets people choose when several providers sign in their domain', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      signedIn: false,
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
    await expect(page.getByText('Identity provider login page')).toBeVisible()
    expect(mock.sent.signIns).toEqual(['provider-for-billing'])
  })

  test('shows why a single sign-on failed', async ({ page }) => {
    await mockAppwriteApi(page, { signedIn: false })
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

    // Any other text in the link is not shown (see the failure messages test)
    const other = JSON.stringify({
      message: 'Single sign-on could not finish.',
    })
    await page.goto(`/sso?error=${encodeURIComponent(other)}`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(
      page.getByText(
        'Single sign-on could not finish. Start again, or contact your administrator if it keeps happening.',
      ),
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

/** What the page wrote to the clipboard, without asking the browser for permission. */
async function captureClipboard(page: Page) {
  await page.addInitScript(() => {
    const copied: string[] = []
    ;(window as unknown as { __copied: string[] }).__copied = copied
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: {
        writeText: async (text: string) => {
          copied.push(text)
        },
        readText: async () => copied.at(-1) ?? '',
      },
    })
  })
  return () =>
    page.evaluate(() => (window as unknown as { __copied: string[] }).__copied)
}

const focused = (page: Page) =>
  page.evaluate(() => {
    const element = document.activeElement
    return element
      ? (element.getAttribute('aria-label') ?? element.textContent ?? '').trim()
      : null
  })

test.describe('organization single sign-on settings', () => {
  test("offers DNS records only for a domain that isn't verified yet", async ({
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

    await page.getByRole('button', { name: 'Actions for acme.com' }).click()
    await expect(page.getByRole('menuitem', { name: 'Delete' })).toBeVisible()
    await expect(
      page.getByRole('menuitem', { name: 'DNS records' }),
    ).toHaveCount(0)
    await page.keyboard.press('Escape')

    await page.getByRole('button', { name: 'Actions for acme.dev' }).click()
    await expect(
      page.getByRole('menuitem', { name: 'DNS records' }),
    ).toBeVisible()
    await page.keyboard.press('Escape')
    // The row's own Verify button says which domain it verifies
    await expect(
      page.getByRole('button', { name: 'Verify acme.dev' }),
    ).toBeVisible()
  })

  test("copies a provider's SSO URL", async ({ page }) => {
    const copied = await captureClipboard(page)
    await mockAppwriteApi(page, {
      providers: [provider('Okta', ['developer'])],
    })
    await enableSso(page)
    await openSso(page)

    await page.getByRole('button', { name: 'Actions for Okta' }).click()
    await page.getByRole('menuitem', { name: 'Copy SSO URL' }).click()
    await expect
      .poll(copied)
      .toEqual([new URL('/sso?provider=provider-okta', page.url()).href])
  })

  test('shows Require SSO as paused while no provider is enabled', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      ssoRequired: true,
      providers: [provider('Okta', ['developer'], false)],
    })
    await enableSso(page)
    await openSso(page)

    const required = page.getByRole('switch', {
      name: 'Require single sign-on',
    })
    await expect(required).toBeChecked()
    await expect(page.getByText('Paused', { exact: true })).toBeVisible()
    await expect(required).toHaveAccessibleDescription(
      /with no enabled identity provider, members sign in any way they like/,
    )
  })

  test('warns that deleting the last domain or provider pauses Require SSO', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      ssoRequired: true,
      providers: [provider('Okta', ['developer'])],
    })
    await enableSso(page)
    await openSso(page)

    await page.getByRole('button', { name: 'Actions for acme.com' }).click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    const deleteDomain = page.getByRole('dialog', {
      name: 'Delete domain',
    })
    await expect(deleteDomain).toContainText(
      'Are you sure you want to delete "acme.com"? People with an email on it can no longer sign in through your identity providers.',
    )
    await expect(deleteDomain).toContainText(
      'this is your only verified domain: deleting it pauses Require SSO',
    )
    await deleteDomain.getByRole('button', { name: 'Cancel' }).click()

    await page.getByRole('button', { name: 'Actions for Okta' }).click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    const deleteProvider = page.getByRole('dialog', {
      name: 'Delete identity provider',
    })
    await expect(deleteProvider).toContainText(
      'Are you sure you want to delete "Okta"?',
    )
    await expect(deleteProvider).toContainText(
      'this is your only enabled identity provider: deleting it pauses Require SSO',
    )
  })

  test('names what is missing and trims a pasted client secret', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    await enableSso(page)
    await openSso(page)

    await page.getByRole('button', { name: 'Add identity provider' }).click()
    const add = page.getByRole('dialog', { name: 'Add identity provider' })
    await add.getByRole('button', { name: 'Add', exact: true }).click()
    await expect(add.getByLabel('Name')).toHaveAccessibleDescription('Required')
    await expect(add.getByLabel('Client ID')).toHaveAccessibleDescription(
      'Required',
    )
    await expect(add.getByLabel('Client secret')).toHaveAccessibleDescription(
      'Required',
    )
    await expect(add.getByRole('group', { name: 'Roles' })).toBeVisible()
    expect(mock.sent.providerCreates).toHaveLength(0)

    await add.getByLabel('Name').fill('Okta')
    await add.getByLabel('Client ID').fill('okta-client')
    await add.getByLabel('Client secret').fill('  okta-secret\n')
    await add
      .getByLabel('Discovery URL')
      .fill('https://acme.okta.com/.well-known/openid-configuration')
    await add.getByRole('button', { name: 'Add', exact: true }).click()
    await expect
      .poll(() => mock.sent.providerCreates.map((sent) => sent.clientSecret))
      .toEqual(['okta-secret'])
  })

  test('adds a provider once when Add is double-clicked', async ({ page }) => {
    const mock = await mockAppwriteApi(page)
    await enableSso(page)
    await openSso(page)

    await page.getByRole('button', { name: 'Add identity provider' }).click()
    const add = page.getByRole('dialog', { name: 'Add identity provider' })
    await add.getByLabel('Name').fill('Okta')
    await add.getByLabel('Client ID').fill('okta-client')
    await add.getByLabel('Client secret').fill('okta-secret')
    await add
      .getByLabel('Discovery URL')
      .fill('https://acme.okta.com/.well-known/openid-configuration')
    await add.getByRole('button', { name: 'Add', exact: true }).dblclick()
    await expect(add).toBeHidden()
    await expect(page.getByRole('row', { name: /Okta/ })).toHaveCount(1)
    expect(mock.sent.providerCreates).toHaveLength(1)

    // Enter twice in a field does the same
    await page.getByRole('button', { name: 'Add domain' }).click()
    const addDomain = page.getByRole('dialog', { name: 'Add domain' })
    await addDomain.getByLabel('Domain').fill('acme.dev')
    await addDomain.getByLabel('Domain').press('Enter')
    await addDomain
      .getByLabel('Domain')
      .press('Enter')
      .catch(() => {})
    await expect.poll(() => mock.sent.creates).toEqual([{ domain: 'acme.dev' }])
  })

  test('says when the lists failed to load, and tries again', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      listsFail: true,
      providers: [provider('Okta', ['developer'])],
    })
    await enableSso(page)
    await openSso(page)

    await expect(page.getByText('Failed to load domains')).toBeVisible()
    await expect(
      page.getByText('Failed to load identity providers'),
    ).toBeVisible()
    // Not mistaken for an organization with nothing set up
    await expect(page.getByText('No domains yet')).toHaveCount(0)
    await expect(page.getByText('No identity providers yet')).toHaveCount(0)
    await expect(
      page.getByText('Verify a domain to require single sign-on.'),
    ).toHaveCount(0)

    mock.listsFail = false
    const retry = page.getByRole('button', { name: 'Try again' })
    await retry.first().click()
    await expect(retry).toHaveCount(1)
    await retry.click()
    await expect(retry).toHaveCount(0)
    await expect(page.getByRole('row', { name: /acme\.com/ })).toBeVisible()
    await expect(page.getByRole('row', { name: /Okta/ })).toBeVisible()
  })

  test('moves focus into confirmations and back to what opened them', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      domains: [domain('acme.com', 'verified'), domain('acme.dev', 'verified')],
      providers: [provider('Okta', ['developer'])],
    })
    await enableSso(page)
    await openSso(page)

    // From the keyboard: the row menu, then Delete
    await page.getByRole('button', { name: 'Actions for acme.com' }).focus()
    await page.keyboard.press('Enter')
    await page.getByRole('menuitem', { name: 'Delete' }).focus()
    await page.keyboard.press('Enter')
    const deleteDomain = page.getByRole('dialog', {
      name: 'Delete domain',
    })
    await expect(deleteDomain).toBeVisible()
    await expect.poll(() => focused(page)).toBe('Cancel')
    await page.keyboard.press('Escape')
    await expect.poll(() => focused(page)).toBe('Actions for acme.com')

    // After a delete, focus moves to the next row
    await page.keyboard.press('Enter')
    await page.getByRole('menuitem', { name: 'Delete' }).focus()
    await page.keyboard.press('Enter')
    await deleteDomain
      .getByRole('button', { name: 'Delete', exact: true })
      .click()
    await expect.poll(() => mock.sent.deletes).toEqual(['domain-acme.com'])
    await expect.poll(() => focused(page)).toBe('Actions for acme.dev')

    // Add provider returns focus to its button
    await page.getByRole('button', { name: 'Add identity provider' }).click()
    await page.keyboard.press('Escape')
    await expect.poll(() => focused(page)).toBe('Add identity provider')

    // A provider's switch keeps focus after a toggle, so Space works twice
    const enable = page.getByRole('switch', { name: 'Enable Okta' })
    await enable.focus()
    await page.keyboard.press('Space')
    await expect
      .poll(() => mock.sent.providerUpdates.map((sent) => sent.body.enabled))
      .toEqual([false])
    await expect(enable).toBeFocused()
    await expect(enable).not.toBeChecked()
    await page.keyboard.press('Space')
    await expect
      .poll(() => mock.sent.providerUpdates.map((sent) => sent.body.enabled))
      .toEqual([false, true])
    await expect(enable).toBeFocused()

    // Require SSO's confirmation starts on Cancel and returns to Update
    await page.getByRole('switch', { name: 'Require single sign-on' }).click()
    await page.getByRole('button', { name: 'Update', exact: true }).click()
    await expect(
      page.getByRole('dialog', { name: 'Require single sign-on' }),
    ).toBeVisible()
    await expect.poll(() => focused(page)).toBe('Cancel')
    await page.keyboard.press('Escape')
    await expect.poll(() => focused(page)).toBe('Update')
  })

  test('says when the plan adds nobody through single sign-on', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      plan: {
        $id: 'tier-0',
        name: 'Free',
        addons: { seats: { supported: false } },
      },
    })
    await enableSso(page)
    await openSso(page)

    await expect(
      page.getByText(
        "This plan can't add members, so people who sign in with single sign-on don't join this organization.",
      ),
    ).toBeVisible()
    await expect(
      page.getByRole('link', { name: 'Upgrade your plan' }),
    ).toHaveAttribute('href', /\/upgrade\?orgId=acme/)
  })
})

test.describe('single sign-on sign-in', () => {
  test('offers Sign in with SSO on the sign-in page without the preview', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { signedIn: false })
    await page.goto('/sign-in', { waitUntil: 'domcontentloaded' })

    const sso = page.getByRole('link', { name: 'Sign in with SSO' })
    await expect(sso).toBeVisible({ timeout: 45_000 })
    await sso.click()
    await expect(page).toHaveURL(/\/sso/)
    await expect(page.getByLabel('Work email')).toBeVisible()
  })

  test('starts the sign-in from a shared SSO URL without typing', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      signedIn: false,
      providers: [provider('Okta', ['developer'])],
    })
    await page.goto('/sso?provider=provider-okta', {
      waitUntil: 'domcontentloaded',
    })

    await expect(page.getByText('Identity provider login page')).toBeVisible({
      timeout: 45_000,
    })
    expect(mock.sent.signIns).toEqual(['provider-okta'])
    expect(mock.sent.lookups).toHaveLength(0)
    // Without a page to return to, the API picks the provider's organization
    const started = mock.sent.signInURLs[0]
    expect(started.searchParams.has('success')).toBe(false)
    expect(new URL(started.searchParams.get('failure') ?? '').pathname).toBe(
      '/sso',
    )
  })

  test('returns only to pages inside the Console, also after a failed try', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      signedIn: false,
      providers: [provider('Okta', ['developer'])],
    })
    for (const redirect of [
      '@evil.example',
      'https://evil.example',
      '//evil.example',
      '/\\evil.example',
      'javascript:alert(1)',
    ]) {
      await page.goto(
        `/sso?provider=provider-okta&redirect=${encodeURIComponent(redirect)}`,
        { waitUntil: 'domcontentloaded' },
      )
      await expect(page.getByText('Identity provider login page')).toBeVisible({
        timeout: 45_000,
      })
      const started = mock.sent.signInURLs.at(-1)!
      expect(started.searchParams.has('success'), redirect).toBe(false)
      expect(started.searchParams.get('failure'), redirect).not.toContain(
        'evil',
      )
    }

    const back = `/organizations/${ORGANIZATION.$id}/settings`
    await page.goto(
      `/sso?provider=provider-okta&redirect=${encodeURIComponent(back)}`,
      { waitUntil: 'domcontentloaded' },
    )
    await expect(page.getByText('Identity provider login page')).toBeVisible()
    const started = mock.sent.signInURLs.at(-1)!
    const success = new URL(started.searchParams.get('success') ?? '')
    expect(success.pathname).toBe(back)
    const failure = new URL(started.searchParams.get('failure') ?? '')
    expect(failure.pathname).toBe('/sso')
    expect(failure.searchParams.get('redirect')).toBe(back)
  })

  test('checks the work email before asking the server', async ({ page }) => {
    const mock = await mockAppwriteApi(page, { signedIn: false })
    await page.goto('/sso', { waitUntil: 'domcontentloaded' })

    const field = page.getByLabel('Work email')
    for (const value of ['erin@acme', 'erin']) {
      await field.fill(value)
      await page.getByRole('button', { name: 'Continue', exact: true }).click()
      await expect(page.getByRole('alert')).toHaveText(
        'Please enter a valid email address',
        { timeout: 45_000 },
      )
      await expect(field).toHaveAttribute(
        'aria-describedby',
        'sso-email-message',
      )
    }
    await expect(field).toHaveAttribute('maxlength', '254')
    expect(mock.sent.lookups).toHaveLength(0)
  })

  test('chooses failure messages by type and never shows text from the URL', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { signedIn: false })
    const open = (error: Record<string, unknown>) =>
      page.goto(`/sso?error=${encodeURIComponent(JSON.stringify(error))}`, {
        waitUntil: 'domcontentloaded',
      })
    const alert = () => page.getByRole('alert')

    await open({
      type: 'user_oauth2_provider_failure',
      message:
        "Single sign-on couldn't complete sign-in (invalid_client). Please try again.",
      code: 424,
    })
    await expect(alert()).toHaveText(
      "Single sign-on for your organization isn't set up correctly. Contact your administrator.",
      { timeout: 45_000 },
    )

    await open({
      type: 'user_oauth2_provider_failure',
      message:
        "Single sign-on couldn't complete sign-in (email_missing). Please try again.",
    })
    await expect(alert()).toHaveText(
      "Your identity provider didn't share your email address. Ask your administrator to release the email to Appwrite.",
    )

    await open({ type: 'user_oauth2_state_invalid', message: 'Anything' })
    await expect(alert()).toHaveText(
      'This sign-in was started in another browser or took too long. Start again.',
    )

    // Cloud's own refusals are shown as they are
    await open({
      type: 'user_oauth2_provider_error',
      message:
        'Single sign-on for your organization is not working right now. Contact your administrator.',
    })
    await expect(alert()).toHaveText(
      'Single sign-on for your organization is not working right now. Contact your administrator.',
    )

    // Anything else, in particular words someone put in the link
    await open({
      type: 'user_oauth2_provider_error',
      message: 'Your account is locked. Call +1 555 0100 to unlock.',
    })
    await expect(alert()).toHaveText(
      'Single sign-on could not finish. Start again, or contact your administrator if it keeps happening.',
    )
    await expect(page.getByText(/555 0100/)).toHaveCount(0)
  })
})

test.describe('organizations that require single sign-on', () => {
  test('opens another organization for a member whose last one refuses them', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      role: 'developer',
      ssoRequired: true,
      refuseWithoutSSO: true,
      personal: true,
      prefs: { organization: ORGANIZATION.$id },
    })
    await enableSso(page)

    await page.goto('/', { waitUntil: 'domcontentloaded' })
    await expect(page).toHaveURL(new RegExp(`/organizations/${PERSONAL.$id}`), {
      timeout: 45_000,
    })
    await expect(page).not.toHaveURL(/\/sso/)
    // The preference keeps naming the organization they left, for after SSO
    for (const prefs of mock.sent.prefs) {
      expect(prefs.organization).toBe(ORGANIZATION.$id)
    }

    // Opening the refusing organization itself still asks for SSO, with a
    // way back to the Console instead of to the sign-in page
    await page.goto(`/organizations/${ORGANIZATION.$id}`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page).toHaveURL(/\/sso\?/, { timeout: 45_000 })
    await expect(
      page.getByRole('link', { name: 'Back to sign in' }),
    ).toHaveCount(0)
    await expect(
      page.getByText('Signed in as owner@example.com.'),
    ).toBeVisible()
    await expect(page.getByRole('link', { name: 'Sign out' })).toBeVisible()
    await page.getByRole('link', { name: 'Back to the Console' }).click()
    await expect(page).toHaveURL(new RegExp(`/organizations/${PERSONAL.$id}`), {
      timeout: 45_000,
    })
  })

  test('sends a member who signs in with a password from a link to that organization to SSO', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      signedIn: false,
      role: 'developer',
      ssoRequired: true,
      refuseWithoutSSO: true,
    })
    await enableSso(page)
    const target = `/organizations/${ORGANIZATION.$id}/settings/members`

    await page.goto(target, { waitUntil: 'domcontentloaded' })
    await expect(page).toHaveURL(/\/sign-in/, { timeout: 45_000 })
    await page.getByLabel('Email').fill(ACCOUNT.email)
    const password = page.locator('input[type=password]')
    if (!(await password.isVisible())) {
      await page.getByRole('button', { name: 'Continue', exact: true }).click()
    }
    await password.fill('a-password')
    await password.press('Enter')

    await expect(page).toHaveURL(/\/sso\?/, { timeout: 45_000 })
    const url = new URL(page.url())
    expect(url.searchParams.get('required')).toBe('true')
    expect(url.searchParams.get('redirect')).toBe(target)
  })

  test('gives a member whose only organization refuses them their account and sign out', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      role: 'developer',
      ssoRequired: true,
      refuseWithoutSSO: true,
    })
    await enableSso(page)

    await page.goto('/', { waitUntil: 'domcontentloaded' })
    await expect(page).toHaveURL(/\/sso\?/, { timeout: 45_000 })
    expect(new URL(page.url()).searchParams.get('redirect')).toBe(
      `/organizations/${ORGANIZATION.$id}`,
    )
    await expect(page.getByRole('link', { name: 'Sign out' })).toBeVisible()
    await expect(
      page.getByRole('link', { name: 'Back to the Console' }),
    ).toHaveCount(0)
    await page.getByRole('link', { name: 'Go to your account' }).click()
    await expect(page).toHaveURL(/\/account/, { timeout: 45_000 })
  })

  test('returns a member to the organization they picked in the switcher', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      role: 'developer',
      ssoRequired: true,
      refuseWithoutSSO: true,
      personal: true,
      prefs: { organization: PERSONAL.$id },
    })
    await enableSso(page)

    await page.goto(`/organizations/${PERSONAL.$id}`, {
      waitUntil: 'domcontentloaded',
    })
    await page
      .locator('[data-analytics="organization-switcher"]')
      .first()
      .click({ timeout: 45_000 })
    await page
      .locator('[data-slot="popover-content"]')
      .getByText(ORGANIZATION.name, { exact: true })
      .first()
      .click()

    await expect(page).toHaveURL(/\/sso\?/, { timeout: 45_000 })
    expect(new URL(page.url()).searchParams.get('redirect')).toBe(
      `/organizations/${ORGANIZATION.$id}`,
    )
    // A refused switch is not remembered
    expect(
      mock.sent.prefs.some((prefs) => prefs.organization === ORGANIZATION.$id),
    ).toBe(false)
  })

  test('tells the owner a removed member on a verified domain can rejoin through SSO', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      providers: [provider('Okta', ['developer'])],
      members: [
        member('Demo Owner', 'owner@acme.com', ['owner']),
        member('Sara Lee', 'sara@acme.com', ['developer']),
        member('Alex Contractor', 'alex@gmail.com', ['developer']),
      ],
    })
    await enableSso(page)
    await page.goto(`${SETTINGS}/members`, { waitUntil: 'domcontentloaded' })

    const rejoin =
      'They can join again by signing in with single sign-on. To keep them out, remove them at your identity provider too.'
    const removeDialog = async (name: string) => {
      const row = page.getByRole('row', { name })
      await row.getByRole('button').last().click({ timeout: 45_000 })
      await page
        .getByRole('menuitem', { name: /remove/i })
        .first()
        .click()
      return page.getByRole('dialog')
    }

    const sara = await removeDialog('Sara Lee')
    await expect(sara).toContainText(rejoin)
    await page.keyboard.press('Escape')
    await expect(sara).toBeHidden()

    const alex = await removeDialog('Alex Contractor')
    await expect(alex).toContainText('They will lose access')
    await expect(alex).not.toContainText(rejoin)
  })

  test('names single sign-on sessions on the account page', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      sessions: [
        {
          $id: 'session-sso',
          $createdAt: NOW,
          $updatedAt: NOW,
          userId: ACCOUNT.$id,
          expire: NOW,
          provider: 'sso',
          providerUid: 'owner@example.com',
          providerAccessToken: '',
          providerAccessTokenExpiry: '',
          providerRefreshToken: '',
          ip: '127.0.0.1',
          osCode: 'MAC',
          osName: 'Mac',
          osVersion: '15',
          clientType: 'browser',
          clientCode: 'CH',
          clientName: 'Chrome',
          clientVersion: '142',
          clientEngine: 'Blink',
          clientEngineVersion: '142',
          deviceName: 'desktop',
          deviceBrand: '',
          deviceModel: '',
          countryCode: 'de',
          countryName: 'Germany',
          current: true,
          factors: ['email'],
          secret: '',
          mfaUpdatedAt: '',
        },
      ],
    })
    await page.goto('/account/sessions', { waitUntil: 'domcontentloaded' })
    await expect(page.getByText('Single sign-on', { exact: true })).toBeVisible(
      { timeout: 45_000 },
    )
  })
})
