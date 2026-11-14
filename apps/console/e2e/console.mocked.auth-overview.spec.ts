import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Auth opens on its Overview tab, and the users list lives on its own Users
 * tab at `/auth/users`.
 *
 * The Appwrite API is mocked at the network layer and the real console pages are
 * asserted.
 */

const NOW = '2026-10-28T09:30:00.000+00:00'

const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'users.read',
  'users.write',
  'teams.read',
  'teams.write',
  'billing.read',
] satisfies Models.Roles['scopes']
const SERVICES = ['account', 'project', 'teams', 'users'] as const

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
  // Keeps the firewall announcement from covering the overview.
  prefs: { 'console.dismissedBanners': 'firewall-moved-2026-11' },
  targets: [],
  accessedAt: NOW,
} satisfies Partial<Models.User<Models.Preferences>>

const ORGANIZATION = {
  $id: 'org00000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme Docs',
  billingPlan: 'tier-1',
  billingEmail: ACCOUNT.email,
  status: 'active',
  prefs: {},
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT_ID = 'proj0000000000000000001'

const PROJECT = {
  $id: PROJECT_ID,
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme Docs',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
  // The SDK types service ids as enums, so the literals need the cast.
  services: SERVICES.map(($id) => ({
    $id,
    enabled: true,
  })) as Models.Project['services'],
  authMethods: [
    { $id: 'email-password', enabled: true },
    { $id: 'magic-url', enabled: true },
    { $id: 'anonymous', enabled: false },
  ] as Models.Project['authMethods'],
} satisfies Partial<Models.Project>

/** Newest first, as the users list orders them. */
const USERS = [
  ['Ada Lovelace', 'ada@example.com', true, true],
  ['Grace Hopper', 'grace@example.com', false, true],
  ['Alan Turing', 'alan@example.com', true, false],
  ['Katherine Johnson', 'katherine@example.com', true, true],
  ['Edsger Dijkstra', 'edsger@example.com', true, true],
  ['Barbara Liskov', 'barbara@example.com', true, true],
].map(([name, email, emailVerification, status], index) => ({
  $id: `user${String(index + 2).padStart(18, '0')}`,
  $createdAt: new Date(Date.parse(NOW) - index * 86_400_000).toISOString(),
  $updatedAt: NOW,
  name,
  email,
  phone: index === 0 ? '+14155550100' : '',
  emailVerification,
  phoneVerification: index === 0,
  status,
  labels: index === 0 ? ['admin', 'beta', 'staff'] : [],
  registration: NOW,
  passwordUpdate: NOW,
  mfa: index === 0,
  prefs: {},
  targets: [],
  accessedAt: index === 0 ? NOW : '',
}))

const TEAMS = ['Admins', 'Editors', 'Readers'].map((name, index) => ({
  $id: `team${String(index + 1).padStart(18, '0')}`,
  $createdAt: NOW,
  $updatedAt: NOW,
  name,
  total: 0,
  prefs: {},
}))

const POLICIES = [
  { $id: 'session-duration', duration: 31_536_000 },
  { $id: 'session-limit', total: 10 },
  { $id: 'user-limit', total: 0 },
  { $id: 'password-history', total: 5 },
  { $id: 'password-dictionary', enabled: true },
  { $id: 'session-alert', enabled: false },
]

/** Only the fields the overview reads off the plan. */
const FREE_PLAN = {
  $id: 'tier-0',
  name: 'Free',
  order: 0,
  price: 0,
  selfService: true,
  users: 75_000,
} satisfies Partial<Models.BillingPlan>

const PROVIDERS = [
  { $id: 'github', enabled: true },
  { $id: 'google', enabled: true },
  { $id: 'apple', enabled: false },
]

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

/** Hourly gauge points over the last day, so the usage card draws a chart. */
function gaugePoints(start: number, step: number) {
  const hour = 3_600_000
  const now = Math.floor(Date.now() / hour) * hour
  return Array.from({ length: 24 }, (_, index) => ({
    time: new Date(now - (23 - index) * hour).toISOString(),
    value: start + index * step,
  }))
}

/** Every `/v1` call, on any region host, is answered here; the app itself is served live. */
type MockOptions = {
  users?: typeof USERS
  /** Puts the organization on the Free plan. */
  free?: boolean
}

async function mockAppwriteApi(
  page: Page,
  { users = USERS, free = false }: MockOptions = {},
) {
  const organization = free
    ? { ...ORGANIZATION, billingPlan: FREE_PLAN.$id }
    : ORGANIZATION
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
    // Project and console calls share paths such as `/teams`.
    const forProject = request.headers()['x-appwrite-project'] === PROJECT_ID

    if (forProject && apiPath === '/users')
      return json(200, { total: users.length, users })
    if (forProject && apiPath === '/teams')
      return json(200, { total: TEAMS.length, teams: TEAMS })
    if (apiPath === '/project/policies')
      return json(200, { total: POLICIES.length, policies: POLICIES })
    if (apiPath === '/project/oauth2')
      return json(200, { total: PROVIDERS.length, providers: PROVIDERS })
    if (apiPath === '/usage/gauges')
      return json(200, {
        interval: '1h',
        metrics: [
          { metric: 'users.mau', points: gaugePoints(40, 1) },
          { metric: 'users', points: gaugePoints(100, 2) },
        ],
      })
    if (apiPath === '/usage/events')
      return json(200, { interval: '1h', metrics: [] })

    if (apiPath === '/account') return json(200, ACCOUNT)
    // GET returns the prefs, PATCH the updated user.
    if (apiPath === '/account/prefs') {
      return request.method() === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, ACCOUNT.prefs)
    }
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
    if (free && apiPath === `/organizations/${ORGANIZATION.$id}/plan`)
      return json(200, FREE_PLAN)
    if (apiPath === '/organizations' || apiPath === '/teams')
      return json(200, { total: 1, teams: [organization] })
    if (
      apiPath === `/organizations/${ORGANIZATION.$id}` ||
      apiPath === `/teams/${ORGANIZATION.$id}`
    )
      return json(200, organization)
    if (apiPath.endsWith('/memberships'))
      return json(200, { total: 0, memberships: [] })
    if (apiPath === '/projects' || apiPath === '/organization/projects')
      return json(200, { total: 1, projects: [PROJECT] })
    if (
      apiPath === '/project' ||
      apiPath === `/projects/${PROJECT_ID}` ||
      apiPath === `/projects/${PROJECT_ID}/console-access`
    )
      return json(200, PROJECT)
    if (apiPath === '/project/mock-phones')
      return json(200, { total: 0, mockNumbers: [] })

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })
}

/** Pins the console profile, so the spec never relies on the endpoint's. */
async function useProfile(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: {} }),
    )
  })
}

async function attachScreenshot(page: Page, name: string) {
  await test.info().attach(name, {
    body: await page.screenshot({ animations: 'disabled', fullPage: true }),
    contentType: 'image/png',
  })
}

function card(page: Page, title: string) {
  return page
    .getByRole('heading', { name: title, exact: true })
    .locator('xpath=ancestor::div[contains(@class, "rounded-xl")][1]')
}

test.describe('auth overview (mocked API)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await useProfile(page)
  })

  test('opens Auth on the overview', async ({ page }) => {
    await mockAppwriteApi(page)
    await page.goto(`/projects/${PROJECT_ID}/auth`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(
      page.getByRole('heading', { name: 'Recent users', exact: true }),
    ).toBeVisible({ timeout: 30_000 })

    // Social providers ride along on the Auth methods tile.
    await expect(page.getByText('2 social providers')).toBeVisible()

    const methods = card(page, 'Auth methods')
    await expect(methods.getByText('2 of 8 enabled')).toBeVisible()
    await expect(methods.getByText('GitHub', { exact: true })).toBeVisible()
    await expect(methods.getByText('Google', { exact: true })).toBeVisible()
    await expect(methods.getByText('Apple', { exact: true })).toHaveCount(0)

    const recent = card(page, 'Recent users')
    await expect(recent.getByText('6 users')).toBeVisible()
    // Five newest, so the oldest user stays on the Users tab.
    await expect(recent.getByRole('row')).toHaveCount(6)
    await expect(recent.getByText('Ada Lovelace')).toBeVisible()
    await expect(recent.getByText('Barbara Liskov')).toHaveCount(0)
    const ada = recent.getByRole('row', { name: /Ada Lovelace/ })
    await expect(ada.getByText('+14155550100')).toBeVisible()
    await expect(ada.getByText('admin')).toBeVisible()
    // Two labels show, the third folds into a count.
    await expect(ada.getByText('+1', { exact: true })).toBeVisible()
    await expect(
      recent.getByRole('row', { name: /Alan Turing/ }).getByText('Blocked'),
    ).toBeVisible()

    const policies = card(page, 'Policies')
    await expect(policies.getByText('365 days')).toBeVisible()
    await expect(policies.getByText('Upgrade for more')).toHaveCount(0)
    await expect(policies.getByText('Unlimited')).toBeVisible()

    await expect(
      card(page, 'Activity').getByText('Monthly active users'),
    ).toBeVisible()
    await attachScreenshot(page, 'auth-overview')
  })

  test('lists every user on the Users tab', async ({ page }) => {
    await mockAppwriteApi(page)
    await page.goto(`/projects/${PROJECT_ID}/auth`, {
      waitUntil: 'domcontentloaded',
    })
    await card(page, 'Recent users')
      .getByRole('link', { name: 'View all' })
      .click()

    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/auth/users$`),
    )
    await expect(page.getByPlaceholder('Search users...')).toBeVisible()
    await expect(page.getByText('Barbara Liskov')).toBeVisible()
    await attachScreenshot(page, 'auth-users')
  })

  test('offers to create the first user', async ({ page }) => {
    await mockAppwriteApi(page, { users: [], free: true })
    await page.goto(`/projects/${PROJECT_ID}/auth`, {
      waitUntil: 'domcontentloaded',
    })

    const recent = card(page, 'Recent users')
    await expect(
      recent.getByRole('heading', { name: 'Create your first user' }),
    ).toBeVisible({ timeout: 30_000 })
    await expect(recent.getByRole('link', { name: 'View all' })).toHaveCount(0)
    await expect(
      card(page, 'Policies').getByText(
        'The Free plan includes 75,000 monthly active users.',
      ),
    ).toBeVisible()
    await expect(
      card(page, 'Policies').getByRole('link', { name: 'Upgrade for more' }),
    ).toBeVisible()
    await attachScreenshot(page, 'auth-overview-empty')

    await recent.getByRole('button', { name: 'Create user' }).click()
    // The Create user drawer opens over the overview.
    await expect(page.getByPlaceholder('user@example.com')).toBeVisible()
  })
})
