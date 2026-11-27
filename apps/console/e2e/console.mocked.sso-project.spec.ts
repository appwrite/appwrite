import type { Locator, Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Single sign-on for a team of a project, generally available on Cloud: Auth →
 * Teams → a team → Single sign-on. The team's domains, identity providers and
 * Require SSO go through the same Teams API routes as an organization's, on
 * the project's client (`X-Appwrite-Project: <projectId>`, admin mode). Setting
 * them up needs the project's Single sign-on add-on (`sso`), which leads the
 * page; the app's users sign in through the providers in the customer's own
 * app, so the page spells out the two requests that app sends.
 *
 * The API is mocked at the network layer, so the real pages run; what the page
 * asks the server to do is asserted on the requests it sends.
 */

const NOW = '2026-11-24T09:30:00.000+00:00'

const ACCOUNT = {
  $id: 'user000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Demo Developer',
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
  name: 'Acme',
  billingPlan: 'tier-1',
  billingEmail: ACCOUNT.email,
  paymentMethodId: 'pm-doc-1',
  status: 'active',
} satisfies Partial<Models.Organization<Models.Preferences>>

const SERVICES = [
  'account',
  'databases',
  'tablesdb',
  'storage',
  'teams',
  'users',
  'sites',
  'functions',
  'migrations',
  'messaging',
].map(($id) => ({ $id, enabled: true })) as Models.Project['services']

const PROJECT = {
  $id: 'storefront',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Storefront',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
  services: SERVICES,
} satisfies Partial<Models.Project>

/** The project's team that sets single sign-on up */
const TEAM = {
  $id: 'sales',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Sales',
  total: 2,
  prefs: {},
}

const ADDONS = {
  baa: false,
  premiumGeoDB: false,
  premiumGeoDBOrg: false,
  sso: false,
  ssoOrg: false,
}
const FREE = {
  $id: 'tier-0',
  name: 'Free',
  order: 0,
  supportedAddons: ADDONS,
  environments: 0,
}
const PRO = {
  $id: 'tier-1',
  name: 'Pro',
  order: 1,
  supportedAddons: { ...ADDONS, sso: true, ssoOrg: true },
  environments: 3,
}

/** The project's Single sign-on add-on's price, as the API answers it */
const SSO_PRICE = {
  addonKey: 'sso',
  name: 'Single sign-on',
  monthlyPrice: 150,
  proratedAmount: 60,
  remainingDays: 12,
  totalCycleDays: 30,
  currency: 'USD',
  billingCycleEnd: '2026-11-30T00:00:00.000+00:00',
}

function ssoAddon(status: 'active' | 'pending' = 'active') {
  return {
    $id: 'addon-sso',
    $createdAt: NOW,
    $updatedAt: NOW,
    $permissions: [],
    key: 'sso',
    resourceType: 'project',
    resourceId: PROJECT.$id,
    status,
    currentValue: 1,
    nextValue: null,
  }
}

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

/**
 * A domain of the team, as the API answers it: a project's team proves it at
 * `_<projectId>.<domain>`. The Console shows the record it is given.
 */
function domain(name: string, status: Domain['status']): Domain {
  return {
    $id: `domain-${name}`,
    $createdAt: NOW,
    $updatedAt: NOW,
    teamId: TEAM.$id,
    domain: name,
    status,
    recordName: `_${PROJECT.$id}.${name}`,
    recordValue: `team:${TEAM.$id}`,
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
  oidcClientId: string
  oidcClientSecretSet: boolean
  oidcWellKnownURL: string
  oidcAuthorizationURL: string
  oidcTokenURL: string
  oidcUserInfoURL: string
}

/** The routes take short OIDC names, the model prefixes them; no secret back. */
function answered(body: Record<string, unknown>): Record<string, unknown> {
  const names: Record<string, string> = {
    clientId: 'oidcClientId',
    wellKnownURL: 'oidcWellKnownURL',
    authorizationURL: 'oidcAuthorizationURL',
    tokenURL: 'oidcTokenURL',
    userInfoURL: 'oidcUserInfoURL',
  }
  const answer: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(body)) {
    if (key !== 'clientSecret') answer[names[key] ?? key] = value
  }
  return answer
}

function provider(name: string, roles: string[], enabled = true): Provider {
  return {
    $id: `provider-${name.toLowerCase().replace(/\s+/g, '-')}`,
    $createdAt: NOW,
    $updatedAt: NOW,
    teamId: TEAM.$id,
    type: 'oidc',
    name,
    roles,
    enabled,
    oidcClientId: `${name.toLowerCase()}-client`,
    oidcClientSecretSet: true,
    oidcWellKnownURL:
      'https://idp.example.com/.well-known/openid-configuration',
    oidcAuthorizationURL: '',
    oidcTokenURL: '',
    oidcUserInfoURL: '',
  }
}

/** One request to the team's single sign-on routes, as it was sent */
type SSORequest = {
  method: string
  path: string
  origin: string
  project: string | undefined
  mode: string | undefined
}

interface Mock {
  plan: typeof PRO | typeof FREE
  /** The project's Single sign-on add-on, or none */
  addon: ReturnType<typeof ssoAddon> | null
  domains: Domain[]
  /** Domains whose TXT record the "DNS" holds */
  published: Set<string>
  providers: Provider[]
  ssoRequired: boolean
  /** The API refuses setup with `sso_addon_required`, and the add-on ends */
  addonRefuses: boolean
  sent: {
    sso: SSORequest[]
    creates: Array<{ domain: string }>
    verifies: string[]
    deletes: string[]
    providerCreates: Array<Record<string, unknown>>
    providerUpdates: Array<{ providerId: string; body: Record<string, unknown> }>
    providerDeletes: string[]
    requires: boolean[]
    addonCreates: number
    addonRefusals: number
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
      'plan' | 'addon' | 'domains' | 'providers' | 'ssoRequired' | 'addonRefuses'
    >
  > = {},
): Promise<Mock> {
  const mock: Mock = {
    plan: options.plan ?? PRO,
    addon: options.addon === undefined ? ssoAddon() : options.addon,
    domains: options.domains ?? [],
    published: new Set(),
    providers: options.providers ?? [],
    ssoRequired: options.ssoRequired ?? false,
    addonRefuses: options.addonRefuses ?? false,
    sent: {
      sso: [],
      creates: [],
      verifies: [],
      deletes: [],
      providerCreates: [],
      providerUpdates: [],
      providerDeletes: [],
      requires: [],
      addonCreates: 0,
      addonRefusals: 0,
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
    const project = request.headers()['x-appwrite-project']
    const inProject = project === PROJECT.$id
    const team = () => ({ ...TEAM, ssoRequired: mock.ssoRequired })

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
        roles: ['owner'],
        scopes: [
          'projects.read',
          'projects.write',
          'project.read',
          'project.write',
          'teams.read',
          'teams.write',
        ],
      })

    // The team's single sign-on, on the project's client
    const sso = `/teams/${TEAM.$id}/sso`
    if (apiPath.startsWith(`${sso}/`) || apiPath === sso) {
      mock.sent.sso.push({
        method,
        path: apiPath,
        origin: url.origin,
        project,
        mode: request.headers()['x-appwrite-mode'],
      })
      const setup =
        (method === 'POST' && apiPath === `${sso}/domains`) ||
        (method === 'PATCH' && apiPath.endsWith('/status')) ||
        (method === 'POST' && apiPath === `${sso}/providers/oidc`) ||
        method === 'PUT' ||
        (method === 'PATCH' &&
          apiPath === `${sso}/required` &&
          request.postDataJSON()?.required === true)
      if (setup && (mock.addonRefuses || mock.addon?.status !== 'active')) {
        mock.sent.addonRefusals += 1
        mock.addon = null
        return json(403, {
          message:
            "Single sign-on in a project needs the Single sign-on add-on. Enable it in the project's settings.",
          code: 403,
          type: 'sso_addon_required',
          version: '1.0',
        })
      }
      if (apiPath === `${sso}/required` && method === 'PATCH') {
        const { required } = request.postDataJSON()
        mock.sent.requires.push(required)
        mock.ssoRequired = required
        return json(200, team())
      }
      if (apiPath === `${sso}/domains` && method === 'GET')
        return json(200, { total: mock.domains.length, domains: mock.domains })
      if (apiPath === `${sso}/domains` && method === 'POST') {
        const body = request.postDataJSON()
        mock.sent.creates.push(body)
        const created = domain(body.domain, 'unverified')
        mock.domains.push(created)
        return json(201, created)
      }
      const status = apiPath.match(/\/sso\/domains\/([^/]+)\/status$/)
      if (status && method === 'PATCH') {
        mock.sent.verifies.push(status[1])
        const found = mock.domains.find((item) => item.$id === status[1])
        if (!found || !mock.published.has(found.domain)) {
          return json(400, {
            message: `No TXT record was found at ${found?.recordName} yet. Add one with the value ${found?.recordValue}. DNS changes can take a few minutes to propagate.`,
            code: 400,
            type: 'rule_verification_failed',
            version: '1.0',
          })
        }
        found.status = 'verified'
        return json(200, found)
      }
      const oneDomain = apiPath.match(/\/sso\/domains\/([^/]+)$/)
      if (oneDomain && method === 'DELETE') {
        mock.sent.deletes.push(oneDomain[1])
        mock.domains = mock.domains.filter((item) => item.$id !== oneDomain[1])
        return route.fulfill({ status: 204, headers })
      }
      if (apiPath === `${sso}/providers` && method === 'GET')
        return json(200, {
          total: mock.providers.length,
          providers: mock.providers,
        })
      if (apiPath === `${sso}/providers/oidc` && method === 'POST') {
        const body = request.postDataJSON()
        mock.sent.providerCreates.push(body)
        const created = { ...provider(body.name, body.roles), ...answered(body) }
        mock.providers.push(created as Provider)
        return json(201, created)
      }
      const oidc = apiPath.match(/\/sso\/providers\/oidc\/([^/]+)$/)
      if (oidc && method === 'PUT') {
        const body = request.postDataJSON()
        mock.sent.providerUpdates.push({ providerId: oidc[1], body })
        const index = mock.providers.findIndex((item) => item.$id === oidc[1])
        mock.providers[index] = {
          ...mock.providers[index],
          ...answered(body),
        } as Provider
        return json(200, mock.providers[index])
      }
      const oneProvider = apiPath.match(/\/sso\/providers\/([^/]+)$/)
      if (oneProvider && method === 'DELETE') {
        mock.sent.providerDeletes.push(oneProvider[1])
        mock.providers = mock.providers.filter(
          (item) => item.$id !== oneProvider[1],
        )
        return route.fulfill({ status: 204, headers })
      }
    }

    // The project's teams, and the Console's teams (organizations)
    if (apiPath === '/teams' && inProject)
      return json(200, { total: 1, teams: [team()] })
    if (apiPath === `/teams/${TEAM.$id}` && inProject) return json(200, team())
    if (apiPath.endsWith('/memberships'))
      return json(200, { total: 0, memberships: [] })
    if (apiPath === '/organizations' || apiPath === '/teams')
      return json(200, { total: 1, teams: [ORGANIZATION] })
    if (
      apiPath === `/organizations/${ORGANIZATION.$id}` ||
      apiPath === `/teams/${ORGANIZATION.$id}`
    )
      return json(200, { ...ORGANIZATION, billingPlan: mock.plan.$id })
    if (apiPath === `/organizations/${ORGANIZATION.$id}/plan`)
      return json(200, mock.plan)
    if (apiPath === '/console/plans')
      return json(200, { total: 2, plans: [FREE, PRO] })

    if (apiPath === '/projects' || apiPath === '/organization/projects')
      return json(200, { total: 1, projects: [PROJECT] })
    if (apiPath === `/organization/projects/${PROJECT.$id}`)
      return json(200, PROJECT)
    if (apiPath === '/project') return json(200, PROJECT)
    if (apiPath === '/project/environments')
      return json(200, { total: 0, environments: [] })
    if (apiPath === '/project/prefs') return json(200, {})
    if (apiPath === `/projects/${PROJECT.$id}/console-access`)
      return json(200, PROJECT)

    // The project's add-ons
    const addons = `/projects/${PROJECT.$id}/addons`
    if (apiPath === addons && method === 'GET') {
      const listed = mock.addon ? [mock.addon] : []
      return json(200, { total: listed.length, addons: listed })
    }
    if (apiPath === `${addons}/sso/price`) return json(200, SSO_PRICE)
    if (apiPath === `${addons}/sso` && method === 'POST') {
      mock.sent.addonCreates += 1
      mock.addon = ssoAddon()
      return json(201, mock.addon)
    }

    // Lists the settings page around the add-on reads; empty is enough
    if (
      method === 'GET' &&
      /^\/(project\/(keys|platforms|variables|webhooks)|vcs\/installations|proxy\/rules)$/.test(
        apiPath,
      )
    ) {
      const key = apiPath.split('/').pop() as string
      return json(200, { total: 0, [key]: [] })
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

const TEAM_PATH = `/projects/${PROJECT.$id}/auth/teams/${TEAM.$id}`
const SSO_PATH = `${TEAM_PATH}/sso`

/**
 * Single sign-on off, as on a self-hosted Console, which has no API for it. On
 * Cloud, where these specs run, it is on for everyone.
 */
async function withoutSso(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: { sso: false } }),
    )
  })
}

async function openTeamSso(page: Page) {
  await page.goto(SSO_PATH, { waitUntil: 'domcontentloaded' })
  await expect(addonCard(page)).toBeVisible({ timeout: 45_000 })
}

const addonCard = (page: Page) => page.locator('[data-card-id="sso-addon"]')

/** A card on the page, by its heading */
function card(page: Page, heading: string): Locator {
  return page
    .getByRole('heading', { name: heading, exact: true })
    .locator('xpath=ancestor::div[contains(@class, "rounded-xl")][1]')
}

/**
 * Every request of this page to the team's single sign-on went to the project:
 * its ID in `X-Appwrite-Project` (never `console`), in admin mode.
 */
function expectProjectRequests(mock: Mock) {
  expect(mock.sent.sso.length).toBeGreaterThan(0)
  for (const request of mock.sent.sso) {
    expect(request.project, `${request.method} ${request.path}`).toBe(
      PROJECT.$id,
    )
    expect(request.mode, `${request.method} ${request.path}`).toBe('admin')
  }
}

test.describe('single sign-on for a team of a project', () => {
  test('stays hidden where the Console has no single sign-on', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    await withoutSso(page)

    await page.goto(TEAM_PATH, { waitUntil: 'domcontentloaded' })
    await expect(page.getByRole('tab', { name: 'Memberships' })).toBeVisible({
      timeout: 45_000,
    })
    await expect(page.getByRole('tab', { name: 'Single sign-on' })).toHaveCount(
      0,
    )

    // The address alone does not open it
    await page.goto(SSO_PATH, { waitUntil: 'domcontentloaded' })
    await expect(page).not.toHaveURL(/\/sso$/, { timeout: 45_000 })
    await expect(page.getByRole('tab', { name: 'Memberships' })).toBeVisible()
    expect(mock.sent.sso).toHaveLength(0)
  })

  test("is a tab of the team, with this project's ID in every request", async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      domains: [domain('acme.com', 'verified')],
      providers: [provider('Okta', ['admin'])],
    })

    await page.goto(TEAM_PATH, { waitUntil: 'domcontentloaded' })
    const tab = page.getByRole('tab', { name: 'Single sign-on' })
    await expect(tab).toBeVisible({ timeout: 45_000 })
    await tab.click()
    await expect(page).toHaveURL(new RegExp(`${SSO_PATH}$`))

    await expect(addonCard(page)).toContainText('Active')
    await expect(page.getByRole('row', { name: /acme\.com/ })).toContainText(
      'Verified',
    )
    await expect(page.getByRole('row', { name: /Okta/ })).toContainText(
      'admin',
    )
    expectProjectRequests(mock)
  })
})

test.describe("single sign-on and the project's add-on, on a team", () => {
  test('is the upsell without the add-on, and sets single sign-on up once it is enabled', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, { addon: null })
    await openTeamSso(page)

    // The offer, where the feature lands: what it does, and what it costs
    const addon = addonCard(page)
    await expect(
      addon.getByRole('heading', { name: 'Let your customers sign in with their company login' }),
    ).toBeVisible()
    await expect(addon).toContainText('Users join on their first sign-in')
    await expect(addon).toContainText(
      "Members on a team's domains get its roles only after signing in through its identity provider.",
    )
    await expect(addon).toContainText(
      'One add-on covers every team in this project, in each of its environments.',
    )
    await expect(addon).toContainText('$150.00 per month')
    await expect(addon).toContainText('Prorated for the current billing cycle.')
    // Nothing to set up yet
    await expect(page.getByRole('heading', { name: 'Domains' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Add domain' })).toHaveCount(
      0,
    )
    await expect(
      page.getByRole('heading', { name: 'Add sign-in to your app' }),
    ).toHaveCount(0)

    await addon.getByRole('button', { name: 'Enable add-on' }).click()
    const dialog = page.getByRole('dialog', {
      name: 'Enable the Single sign-on add-on',
    })
    await expect(dialog).toContainText('$150.00 / month')
    await dialog.getByRole('button', { name: 'Enable', exact: true }).click()
    await expect.poll(() => mock.sent.addonCreates).toBe(1)
    await expect(dialog).toHaveCount(0)

    // With the add-on on, the setup cards follow it
    await expect(addon).toContainText('Active')
    await expect(page.getByRole('button', { name: 'Add domain' })).toBeEnabled()
    await expect(
      page.getByRole('button', { name: 'Add identity provider' }),
    ).toBeEnabled()
    await expect(
      page.getByRole('heading', { name: 'Add sign-in to your app' }),
    ).toBeVisible()
    // Enabled: the offer gives way to the card that manages the add-on
    await expect(
      page.getByRole('heading', { name: 'Let your customers sign in with their company login' }),
    ).toHaveCount(0)
    expectProjectRequests(mock)
  })

  test('offers the upgrade on a plan that does not sell the add-on', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, { plan: FREE, addon: null })
    await openTeamSso(page)

    const addon = addonCard(page)
    await expect(addon).toContainText('Unavailable')
    await expect(addon).toContainText(
      'This add-on is available on Pro. Upgrade your plan to enable it.',
    )
    await expect(
      addon.getByRole('button', { name: 'Upgrade plan' }),
    ).toBeVisible()
    await expect(
      addon.getByRole('button', { name: 'Enable add-on' }),
    ).toHaveCount(0)
    await expect(
      addon.getByRole('heading', { name: 'Let your customers sign in with their company login' }),
    ).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Domains' })).toHaveCount(0)
    expect(mock.sent.addonCreates).toBe(0)
  })

  test('keeps what the team set up when the add-on ended, to delete and turn off', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      addon: null,
      ssoRequired: true,
      domains: [domain('acme.com', 'verified')],
      providers: [provider('Okta', ['admin'])],
    })
    await openTeamSso(page)

    const addon = addonCard(page)
    await expect(addon).toContainText('Single sign-on is paused')
    await expect(addon).toContainText(
      "Until the add-on is active, nobody signs in through the identity providers of this project's teams and Require SSO has no effect.",
    )
    await expect(
      page.getByRole('button', { name: 'Add domain' }),
    ).toBeDisabled()
    await expect(
      page.getByRole('button', { name: 'Add identity provider' }),
    ).toBeDisabled()
    // What was set up before is a warning to act on, not an offer
    await expect(
      page.getByRole('heading', { name: 'Let your customers sign in with their company login' }),
    ).toHaveCount(0)

    // Require SSO can be turned off
    const required = page.getByRole('switch', {
      name: 'Require single sign-on',
    })
    await expect(required).toBeChecked()
    await required.click()
    await page.getByRole('button', { name: 'Update', exact: true }).click()
    await expect.poll(() => mock.sent.requires).toEqual([false])
    expect(mock.sent.addonRefusals).toBe(0)
    expectProjectRequests(mock)
  })

  test('explains a refusal without the add-on with the way to it, then locks setup', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      addonRefuses: true,
      domains: [domain('acme.com', 'verified')],
    })
    await openTeamSso(page)

    await page.getByRole('button', { name: 'Add domain' }).click()
    const dialog = page.getByRole('dialog', { name: 'Add domain' })
    await dialog.getByLabel('Domain').fill('acme.io')
    await dialog.getByRole('button', { name: 'Add', exact: true }).click()

    const toast = page.locator('[data-sonner-toast]', {
      hasText:
        "Single sign-on in a project needs the Single sign-on add-on. Enable it in the project's settings.",
    })
    await expect(toast).toBeVisible({ timeout: 45_000 })
    expect(mock.sent.addonRefusals).toBe(1)
    // The project's add-ons are read again: setup locks, the card offers it
    await expect(page.getByText('Single sign-on is paused')).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Add domain' }),
    ).toBeDisabled()
    await toast.getByRole('button', { name: 'View add-on' }).click()
    await expect(page).toHaveURL(new RegExp(`${SSO_PATH}$`))
    await expect(
      addonCard(page).getByRole('button', { name: 'Enable add-on' }),
    ).toBeFocused()
    expectProjectRequests(mock)
  })

  test('points from project settings to where teams set it up', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    await page.goto(`/projects/${PROJECT.$id}/settings`, {
      waitUntil: 'domcontentloaded',
    })

    const addon = addonCard(page)
    await expect(addon).toContainText('Active', { timeout: 45_000 })
    await expect(addon).toContainText(
      'Set it up for each team on its Single sign-on tab, under Auth.',
    )
    await addon.getByRole('link', { name: 'Go to teams' }).click()
    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT.$id}/auth/teams$`),
    )
  })

  test('says nothing about teams in project settings without the add-on', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { addon: null })
    await page.goto(`/projects/${PROJECT.$id}/settings`, {
      waitUntil: 'domcontentloaded',
    })

    const addon = addonCard(page)
    await expect(addon).toContainText('$150.00 per month', { timeout: 45_000 })
    await expect(addon.getByRole('link', { name: 'Go to teams' })).toHaveCount(
      0,
    )
  })
})

test.describe("setting up a team's single sign-on", () => {
  test('claims a domain, shows the TXT record the API returned, and verifies it', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    await openTeamSso(page)

    await expect(card(page, 'Domains')).toContainText(
      "Prove the domains this team owns. The team's identity providers sign in only users with an email on a verified domain.",
    )
    await page.getByRole('button', { name: 'Add domain' }).click()
    const add = page.getByRole('dialog', { name: 'Add domain' })
    await add.getByLabel('Domain').fill('devs.acme.io')
    await add.getByRole('button', { name: 'Add', exact: true }).click()
    await expect
      .poll(() => mock.sent.creates)
      .toEqual([{ domain: 'devs.acme.io' }])

    // The record is the one the API answered, `_storefront.devs.acme.io`,
    // named relative to the zone the way DNS providers ask for it
    const created = mock.domains[0]
    expect(created.recordName).toBe(`_${PROJECT.$id}.devs.acme.io`)
    const verify = page.getByRole('dialog', { name: 'Verify domain' })
    await expect(verify).toContainText('devs.acme.io')
    await expect(verify).toContainText(`_${PROJECT.$id}.devs`)
    await expect(verify).toContainText(created.recordValue)
    await expect(verify).not.toContainText('_appwrite')

    await verify.getByRole('button', { name: 'Verify', exact: true }).click()
    await expect(verify).toContainText(
      `No TXT record was found at _${PROJECT.$id}.devs.acme.io yet.`,
    )
    mock.published.add('devs.acme.io')
    await verify.getByRole('button', { name: 'Verify', exact: true }).click()
    await expect(verify).toBeHidden()
    await expect(
      page.getByRole('row', { name: /devs\.acme\.io/ }),
    ).toContainText('Verified')
    expect(mock.sent.verifies).toEqual([
      'domain-devs.acme.io',
      'domain-devs.acme.io',
    ])
    expectProjectRequests(mock)
  })

  test("adds an identity provider with the team's typed roles and the project's redirect URL", async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      domains: [domain('acme.com', 'verified')],
    })
    await openTeamSso(page)

    await page.getByRole('button', { name: 'Add identity provider' }).click()
    const add = page.getByRole('dialog', { name: 'Add identity provider' })
    // The callback of this project, with its ID in it
    await expect(add.getByLabel('Redirect URL')).toHaveValue(
      new RegExp(`/v1/account/sessions/sso/callback/${PROJECT.$id}$`),
    )
    await add.getByLabel('Name').fill('Okta')
    await add.getByLabel('Client ID').fill('okta-client')
    await add.getByLabel('Client secret').fill('okta-secret')
    await add
      .getByLabel('Discovery URL')
      .fill('https://acme.okta.com/.well-known/openid-configuration')

    // Roles are typed, like a membership's, and need at least one
    const submit = add.getByRole('button', { name: 'Add', exact: true })
    await submit.click()
    await expect(add.getByText('Add at least one role.')).toBeVisible()
    expect(mock.sent.providerCreates).toHaveLength(0)

    const roles = add.getByLabel('Roles')
    const addRole = add.getByRole('button', { name: 'Add role' })
    await roles.fill('team lead')
    await expect(
      add.getByText(
        'Use letters, numbers, periods, hyphens and underscores, starting with a letter or number.',
      ),
    ).toBeVisible()
    await expect(addRole).toBeDisabled()
    await roles.fill('a'.repeat(37))
    await expect(
      add.getByText('A role can be at most 36 characters.'),
    ).toBeVisible()
    await roles.fill('admin')
    await roles.press('Enter')
    await expect(add).toBeVisible()
    await expect(roles).toHaveValue('')
    await roles.fill('sales-lead')
    await addRole.click()
    // A role typed and not added yet goes with the save
    await roles.fill('viewer')
    await submit.click()

    await expect(add).toBeHidden()
    await expect
      .poll(() => mock.sent.providerCreates)
      .toEqual([
        {
          name: 'Okta',
          roles: ['admin', 'sales-lead', 'viewer'],
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
    await expect(row).toContainText('admin')
    await expect(row).toContainText('sales-lead')
    await expect(row).toContainText('viewer')

    // The Console's sign-in link is not the app's: its menu copies the ID
    await page.getByRole('button', { name: 'Actions for Okta' }).click()
    await expect(page.getByRole('menuitem', { name: 'Copy ID' })).toBeVisible()
    await expect(
      page.getByRole('menuitem', { name: 'Copy SSO URL' }),
    ).toHaveCount(0)
    await page.keyboard.press('Escape')
    expectProjectRequests(mock)
  })

  test('updates a provider keeping its roles and secret, and deletes it', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      domains: [domain('acme.com', 'verified')],
      providers: [provider('Okta', ['admin', 'sales'])],
    })
    await openTeamSso(page)

    await page.getByRole('button', { name: 'Actions for Okta' }).click()
    await page.getByRole('menuitem', { name: 'Update' }).click()
    const update = page.getByRole('dialog', {
      name: 'Update identity provider',
    })
    await expect(update.getByLabel('Client secret')).toHaveValue('')
    await update.getByLabel('Name').fill('Okta (sales)')
    await update.getByRole('button', { name: 'Remove admin' }).click()
    await update.getByRole('button', { name: 'Update', exact: true }).click()

    await expect(update).toBeHidden()
    await expect.poll(() => mock.sent.providerUpdates).toHaveLength(1)
    const sent = mock.sent.providerUpdates[0]
    expect(sent.providerId).toBe('provider-okta')
    expect(sent.body.name).toBe('Okta (sales)')
    expect(sent.body.roles).toEqual(['sales'])
    expect(sent.body).not.toHaveProperty('clientSecret')

    await page.getByRole('button', { name: 'Actions for Okta (sales)' }).click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    const remove = page.getByRole('dialog', { name: 'Delete identity provider' })
    await expect(remove).toContainText(
      'Users can no longer sign in through it. Users who already joined stay members.',
    )
    await remove.getByRole('button', { name: 'Delete', exact: true }).click()
    await expect
      .poll(() => mock.sent.providerDeletes)
      .toEqual(['provider-okta'])
    await expect(page.getByText('No identity providers yet')).toBeVisible()
    expectProjectRequests(mock)
  })

  test('requires single sign-on for the team after confirming, and stops requiring it', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      domains: [domain('acme.com', 'verified')],
      providers: [provider('Okta', ['admin'])],
    })
    await openTeamSso(page)

    const required = page.getByRole('switch', {
      name: 'Require single sign-on',
    })
    const update = card(page, 'Require SSO').getByRole('button', {
      name: 'Update',
      exact: true,
    })
    // Worded for a team: roles, not access to an organization
    await expect(required).toHaveAccessibleDescription(
      /get the team's roles only after signing in through one of its identity providers\. Team owners keep their roles however they sign in/,
    )
    await expect(
      page.getByText(
        "Disabled - members get the team's roles however they sign in.",
      ),
    ).toBeVisible()

    await required.click()
    await update.click()
    const confirm = page.getByRole('dialog', { name: 'Require single sign-on' })
    await expect(confirm).toContainText(
      "keep their account but lose the team's roles until they sign in through one of its identity providers",
    )
    await expect(confirm).toContainText('Their other teams are not affected.')
    await expect(confirm).not.toContainText('organization')
    await confirm.getByRole('button', { name: 'Require SSO' }).click()
    await expect.poll(() => mock.sent.requires).toEqual([true])
    await expect(required).toBeChecked()
    await expect(
      page.getByText(
        "Enabled - members on the team's verified domains get its roles only through single sign-on.",
      ),
    ).toBeVisible()

    await required.click()
    await update.click()
    await expect(confirm).toBeHidden()
    await expect.poll(() => mock.sent.requires).toEqual([true, false])
    await expect(required).not.toBeChecked()
    expectProjectRequests(mock)
  })

  test('offers SAML as coming soon', async ({ page }) => {
    await mockAppwriteApi(page)
    await openTeamSso(page)

    await expect(page.getByText('SAML is coming soon')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Notify me' })).toBeVisible()
  })

  test("shows each platform's steps with this project's endpoint, ID, domain and provider", async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      domains: [domain('acme.com', 'verified')],
      providers: [provider('Okta', ['admin'])],
    })
    await openTeamSso(page)

    const app = card(page, 'Add sign-in to your app')
    await expect(app).toBeVisible()
    // The endpoint is the one the Console sends this project's requests to
    await expect.poll(() => mock.sent.sso.length).toBeGreaterThan(0)
    const endpoint = `${mock.sent.sso[0].origin}/v1`
    const platforms = app.getByRole('tablist', { name: 'Platform' })
    const steps = app.getByRole('tablist', { name: 'Select step' })
    await expect(platforms.getByRole('tab')).toHaveText([
      'Web',
      'Next.js',
      'SvelteKit',
      'Flutter',
      'React Native',
      'Apple',
      'Android',
      'Python',
      'HTTP',
    ])

    // Web: three steps, the first one open, with this project's values
    await expect(platforms.getByRole('tab', { name: 'Web' })).toHaveAttribute('aria-selected', 'true')
    await expect(app).toContainText('npm install appwrite')
    await expect(steps.getByRole('tab')).toHaveText([
      '1. Find providers',
      '2. Redirect',
      '3. Create session',
    ])
    await expect(app).toContainText(`export const ENDPOINT = '${endpoint}'`)
    await expect(app).toContainText(`export const PROJECT_ID = '${PROJECT.$id}'`)
    await expect(app).toContainText('/account/sessions/sso/providers')

    // A step on the left opens its code, and the code tabs follow
    await app.getByRole('button', { name: /Send the browser to the chosen one/ }).click()
    await expect(steps.getByRole('tab', { name: '2. Redirect' })).toHaveAttribute('aria-selected', 'true')
    await expect(app).toContainText('/account/tokens/sso')
    await expect(app).toContainText("sessionStorage.setItem('sso-state', state)")
    await steps.getByRole('tab', { name: '3. Create session' }).click()
    await expect(app).toContainText('src/pages/sso-callback.ts')
    await expect(app).toContainText('await account.createSession({')

    // A server: the API key trades the token, the session goes in a cookie
    await platforms.getByRole('tab', { name: 'Next.js' }).click()
    await expect(app).toContainText('npm install node-appwrite')
    await steps.getByRole('tab', { name: '3. Create session' }).click()
    await expect(app).toContainText('app/auth/sso/callback/route.ts')
    await expect(app).toContainText('.setKey(process.env.APPWRITE_API_KEY!)')
    await expect(app).toContainText(`a_session_\${PROJECT_ID}`)

    // SvelteKit declares the key it reads
    await platforms.getByRole('tab', { name: 'SvelteKit' }).click()
    await steps.getByRole('tab', { name: '4. API key' }).click()
    await expect(app).toContainText('src/env.ts')
    await expect(app).toContainText('defineEnvVars')

    // A mobile app returns to the project's callback scheme
    await platforms.getByRole('tab', { name: 'Flutter' }).click()
    await expect(app).toContainText(
      'flutter pub add appwrite && flutter pub add flutter_web_auth_2 http',
    )
    await expect(steps.getByRole('tab')).toHaveCount(5)
    await steps.getByRole('tab', { name: '4. Callback scheme' }).click()
    await expect(app).toContainText(`appwrite-callback-${PROJECT.$id}`)

    // HTTP shows a real request and answer, with this team's domain and provider
    await platforms.getByRole('tab', { name: 'HTTP' }).click()
    await expect(app).not.toContainText('Installation')
    await expect(app).toContainText('GET /v1/account/sessions/sso/providers?email=jane%40acme.com')
    await expect(app).toContainText(`X-Appwrite-Project: ${PROJECT.$id}`)
    await expect(app).toContainText('"name": "Okta"')
    await steps.getByRole('tab', { name: '2. Redirect' }).click()
    await expect(app).toContainText(`GET ${endpoint}/account/tokens/sso`)
    await expect(app).toContainText('&providerId=provider-okta')
  })

  test('copies a prompt for an AI tool with the setup of the platform shown', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      domains: [domain('acme.com', 'verified')],
      providers: [provider('Okta', ['admin']), provider('Old login', ['admin'], false)],
    })
    await openTeamSso(page)
    await expect.poll(() => mock.sent.sso.length).toBeGreaterThan(0)
    const endpoint = `${mock.sent.sso[0].origin}/v1`
    // A real clipboard is not available headless
    await page.evaluate(() => {
      ;(window as unknown as { __copied: string[] }).__copied = []
      navigator.clipboard.writeText = async (text: string) => {
        ;(window as unknown as { __copied: string[] }).__copied.push(text)
      }
    })
    const copied = () =>
      page.evaluate(() => (window as unknown as { __copied: string[] }).__copied)

    const app = card(page, 'Add sign-in to your app')
    await app.getByRole('button', { name: 'Copy prompt' }).click()
    await expect(app.getByRole('button', { name: 'Copied' })).toBeVisible()
    await expect.poll(async () => (await copied()).length).toBe(1)
    const web = (await copied())[0]
    expect(web).toContain('Add single sign-on (SSO) to this web app')
    expect(web).toContain(`- Appwrite endpoint: ${endpoint}`)
    expect(web).toContain(`- Project ID: ${PROJECT.$id}`)
    expect(web).toContain(`- Team: ${TEAM.$id}.`)
    expect(web).toContain('- Email domains that use SSO: acme.com')
    // Only the providers people can sign in through
    expect(web).toContain('- Identity providers: Okta (provider-okta).')
    expect(web).not.toContain('Old login')
    expect(web).toContain('do not invent SDK methods such as listSSOProviders or createSSOToken')
    expect(web).toContain('npm install appwrite')
    expect(web).toContain('(src/pages/sso-callback.ts):')

    // The prompt follows the platform
    await app
      .getByRole('tablist', { name: 'Platform' })
      .getByRole('tab', { name: 'Python' })
      .click()
    await app.getByRole('button', { name: /Copy prompt|Copied/ }).click()
    await expect.poll(async () => (await copied()).length).toBe(2)
    const python = (await copied())[1]
    expect(python).toContain('Python web app (Flask')
    expect(python).toContain('pip install appwrite flask requests')
    expect(python).toContain('only the sessions.write scope')
  })
})
