import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Analytics property page: versions, event props, users and the events total,
 * plus the user identification setting.
 *
 * The Appwrite API is mocked at the network layer and the real console pages are
 * asserted.
 */

const NOW = new Date().toISOString()

const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'analytics.read',
  'analytics.write',
  'billing.read',
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
  name: 'Acme Docs',
  billingPlan: 'tier-0',
  billingEmail: ACCOUNT.email,
  status: 'active',
  prefs: {},
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT_ID = 'proj0000000000000000001'
const PROPERTY_ID = 'prop0000000000000000001'

const PROJECT = {
  $id: PROJECT_ID,
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme Docs',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
  services: [] as Models.Project['services'],
  authMethods: [] as Models.Project['authMethods'],
} satisfies Partial<Models.Project>

function property(identifyUsers: boolean) {
  return {
    $id: PROPERTY_ID,
    $createdAt: NOW,
    $updatedAt: NOW,
    name: 'Acme site',
    domain: 'acme.example',
    enabled: true,
    allowedOrigins: ['*'],
    identifyUsers,
    accessedAt: NOW,
    firstAccessedAt: NOW,
  } satisfies Partial<Models.AnalyticsProperty>
}

function row(
  value: string | null,
  visitors: number,
  extra: Partial<Models.AnalyticsMetric> = {},
): Models.AnalyticsMetric {
  return {
    value,
    date: null,
    visitors,
    sessions: visitors,
    events: visitors * 3,
    ...extra,
  }
}

type MockOptions = {
  identifyUsers?: boolean
  /** User IDs present in the data (sent by a server with an API key). */
  hasUsers?: boolean
}

type Mock = { metrics: URL[]; patches: Request[] }

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

/** The SDK sends arrays as `name[0]=…&name[1]=…`. */
function listParam(url: URL, name: string): string[] {
  return [...url.searchParams.entries()]
    .filter(([key]) => key.startsWith(`${name}[`))
    .map(([, value]) => value)
}

function metricsFor(url: URL, options: MockOptions): Models.AnalyticsMetric[] {
  const dimension = listParam(url, 'dimensions')[0]
  const users = options.hasUsers ? 37 : 0
  if (url.searchParams.get('interval')) {
    return [
      { ...row(null, 120, { pageviews: 900, users }), date: NOW, events: 4321 },
    ]
  }
  if (!dimension) {
    return [
      row(null, 120, {
        events: 4321,
        pageviews: 900,
        users,
        visits: 150,
        bounceRate: 0.4,
        visitDuration: 60,
        viewsPerVisit: 2,
        engagementTime: 30,
      }),
    ]
  }
  if (dimension === 'props') {
    const propKey = url.searchParams.get('propKey')
    if (propKey === 'plan') return [row('pro', 40), row('team', 12)]
    if (propKey === 'source') return [row('ads', 9)]
    return [row('plan', 52), row('source', 9)]
  }
  if (dimension === 'userId') {
    return options.hasUsers ? [row('user-a', 5), row('user-b', 2)] : []
  }
  if (dimension === 'browserVersion')
    return [row('141.0', 70), row('140.0', 20)]
  return []
}

/** Every `/v1` call, on any region host, is answered here; the app itself is served live. */
async function mockAppwriteApi(
  page: Page,
  options: MockOptions = {},
): Promise<Mock> {
  let identifyUsers = options.identifyUsers ?? false
  const mock: Mock = { metrics: [], patches: [] }
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

    if (apiPath === `/analytics/properties/${PROPERTY_ID}/metrics`) {
      mock.metrics.push(url)
      const metrics = metricsFor(url, options)
      return json(200, { total: metrics.length, metrics })
    }
    if (apiPath === `/analytics/properties/${PROPERTY_ID}`) {
      if (request.method() === 'PATCH') {
        mock.patches.push(request)
        identifyUsers = request.postDataJSON().identifyUsers ?? identifyUsers
      }
      return json(200, property(identifyUsers))
    }
    if (apiPath === '/analytics/properties')
      return json(200, { total: 1, properties: [property(identifyUsers)] })

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') {
      return request.method() === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, ACCOUNT.prefs)
    }
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
      return json(200, { total: 1, projects: [PROJECT] })
    if (
      apiPath === '/project' ||
      apiPath === `/projects/${PROJECT_ID}` ||
      apiPath === `/projects/${PROJECT_ID}/console-access`
    )
      return json(200, PROJECT)

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return mock
}

/** Cloud profile with analytics on (it is off until launch). */
async function useProfile(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: { analytics: true } }),
    )
  })
}

async function openProperty(page: Page, path = '') {
  await page.goto(`/projects/${PROJECT_ID}/analytics/${PROPERTY_ID}${path}`, {
    waitUntil: 'domcontentloaded',
  })
}

/** Card headings with an info hint read as "<title> More info". */
function cardHeading(page: Page, title: string) {
  return page.getByRole('heading', {
    name: new RegExp(`^${title}( More info)?$`),
  })
}

function card(page: Page, title: string) {
  return page
    .locator('div.rounded-lg')
    .filter({ has: cardHeading(page, title) })
}

async function attachScreenshot(page: Page, name: string) {
  await test.info().attach(name, {
    body: await page.screenshot({ animations: 'disabled', fullPage: true }),
    contentType: 'image/png',
  })
}

test.describe('analytics property (mocked API)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await useProfile(page)
  })

  test('shows events, users, versions and event props', async ({ page }) => {
    const mock = await mockAppwriteApi(page, {
      identifyUsers: true,
      hasUsers: true,
    })
    await openProperty(page)

    await expect(page.getByText('4.3K').first()).toBeVisible({
      timeout: 30_000,
    })
    await expect(page.getByText('Users', { exact: true }).first()).toBeVisible()

    const users = card(page, 'Users')
    await expect(users.getByText('user-a')).toBeVisible()
    await expect(users.getByRole('link')).toHaveCount(0)

    const props = card(page, 'Event properties')
    await expect(props.getByRole('tab', { name: 'plan' })).toBeVisible()
    await expect(props.getByText('pro', { exact: true })).toBeVisible()
    await props.getByRole('tab', { name: 'source' }).click()
    await expect(props.getByText('ads', { exact: true })).toBeVisible()
    expect(
      mock.metrics.some((url) => url.searchParams.get('propKey') === 'source'),
    ).toBe(true)

    const technology = card(page, 'Technology')
    await technology.getByRole('button', { name: /Versions/ }).click()
    await page.getByRole('menuitem', { name: 'Browser versions' }).click()
    await expect(technology.getByText('141.0')).toBeVisible()
    await attachScreenshot(page, 'property-overview')

    // A prop value filters the series on props.<key>, never the flat aggregate.
    await props.getByRole('tab', { name: 'plan' }).click()
    const before = mock.metrics.length
    await props.getByText('pro', { exact: true }).click()
    await expect(page.getByText('Filtered by')).toBeVisible()
    const carries = (url: URL) =>
      listParam(url, 'queries').some(
        (query) => query.includes('props.plan') && query.includes('pro'),
      )
    await expect
      .poll(() =>
        mock.metrics
          .slice(before)
          .some((url) => url.searchParams.get('interval') && carries(url)),
      )
      .toBe(true)
    expect(
      mock.metrics
        .slice(before)
        .some(
          (url) =>
            !url.searchParams.get('interval') &&
            listParam(url, 'dimensions').length === 0 &&
            carries(url),
        ),
    ).toBe(false)
    await attachScreenshot(page, 'property-prop-filter')
  })

  test('hides users when the property records none', async ({ page }) => {
    await mockAppwriteApi(page)
    await openProperty(page)

    await expect(page.getByText('4.3K').first()).toBeVisible({
      timeout: 30_000,
    })
    await expect(card(page, 'Event properties')).toBeVisible()
    await expect(cardHeading(page, 'Users')).toHaveCount(0)
  })

  test('shows users sent by a server with the setting off', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { hasUsers: true })
    await openProperty(page)

    await expect(card(page, 'Users').getByText('user-a')).toBeVisible({
      timeout: 30_000,
    })
  })

  test('turns user identification on', async ({ page }) => {
    const mock = await mockAppwriteApi(page)
    await openProperty(page, '/settings')

    const toggle = page.locator('#property-identify-users')
    await expect(toggle).not.toBeChecked({ timeout: 30_000 })
    await toggle.click()
    await expect(page.getByText('User identification enabled')).toBeVisible()
    await expect(toggle).toBeChecked()
    expect(mock.patches.map((patch) => patch.postDataJSON())).toEqual([
      { identifyUsers: true },
    ])
    await attachScreenshot(page, 'settings-identify-users')
  })
})
