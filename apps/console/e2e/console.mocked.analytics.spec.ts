import type { Page, Request, Route } from '@playwright/test'
import { AnalyticsPropertyType, type Models } from '@appwrite.io/console'
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
  'monitors.write',
  'sites.read',
  'sites.write',
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
const SITE_ID = 'site0000000000000000001'

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

function property(identifyUsers: boolean, type?: AnalyticsPropertyType) {
  return {
    $id: PROPERTY_ID,
    $createdAt: NOW,
    $updatedAt: NOW,
    name: 'Acme site',
    domain: 'acme.example',
    enabled: true,
    allowedOrigins: ['*'],
    identifyUsers,
    ...(type ? { type } : {}),
    accessedAt: NOW,
    firstAccessedAt: NOW,
  } satisfies Partial<Models.AnalyticsProperty>
}

/** A site whose first analytics toggle links the property. */
function site(analyticsEnabled: boolean) {
  return {
    $id: SITE_ID,
    $createdAt: NOW,
    $updatedAt: NOW,
    name: 'Acme site',
    enabled: true,
    live: true,
    logging: true,
    framework: 'react',
    deploymentId: '',
    scopes: [],
    vars: [],
    analyticsEnabled,
    analyticsPropertyId: analyticsEnabled ? PROPERTY_ID : '',
  } satisfies Partial<Models.Site>
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
  type?: AnalyticsPropertyType
  /** Prop keys an agent sends with its model_called and tool_called events. */
  agentProps?: boolean
  /** Install and push events from the mobile tracking SDKs. */
  mobileEvents?: boolean
  /** The project is at its plan's analytics property limit. */
  propertyLimitReached?: boolean
  /** The plan's `analyticsProperties`; the mocked project holds one property. */
  planPropertyLimit?: number
  /** Holds the plan-limit count (the limit-1 property list) until it resolves. */
  countGate?: Promise<void>
}

const MOBILE_EVENT_COUNTS: Record<string, number> = {
  first_open: 1200,
  app_updated: 300,
  push_received: 5000,
  push_opened: 640,
}

type Annotation = {
  $id: string
  $createdAt: string
  $updatedAt: string
  propertyId: string
  date: string
  content: string
  userId: string
}

type Mock = {
  metrics: URL[]
  patches: Request[]
  annotations: Annotation[]
  siteAnalytics: Request[]
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

/** The SDK sends arrays as `name[0]=…&name[1]=…`. */
function listParam(url: URL, name: string): string[] {
  return [...url.searchParams.entries()]
    .filter(([key]) => key.startsWith(`${name}[`))
    .map(([, value]) => value)
}

/** One identified user's last active bucket, hour-aligned like the API's. */
const USER_LAST_SEEN = new Date(
  Math.floor((Date.now() - 2 * 3_600_000) / 3_600_000) * 3_600_000,
).toISOString()

/** `user-a`'s own activity, answered whenever a request filters on it. */
function userMetricsFor(url: URL, dimension: string | undefined) {
  if (url.searchParams.get('interval')) {
    return [
      {
        ...row(null, 1, { sessions: 2, pageviews: 5, events: 9 }),
        date: new Date(Date.now() - 26 * 3_600_000).toISOString(),
      },
      {
        ...row(null, 1, { sessions: 1, pageviews: 3, events: 4 }),
        date: USER_LAST_SEEN,
      },
      { ...row(null, 0, { sessions: 0, pageviews: 0, events: 0 }), date: NOW },
    ]
  }
  // Every row is one visitor, so only events tell them apart.
  if (dimension === 'page')
    return [row('/pricing', 1, { events: 7 }), row('/docs', 1, { events: 12 })]
  if (dimension === 'eventName')
    return [row('pageview', 1, { events: 8 }), row('signup', 1, { events: 2 })]
  return []
}

function metricsFor(url: URL, options: MockOptions): Models.AnalyticsMetric[] {
  const dimension = listParam(url, 'dimensions')[0]
  const users = options.hasUsers ? 37 : 0
  const queries = listParam(url, 'queries').map((query) => JSON.parse(query))
  if (queries.some((query) => query.attribute === 'userId'))
    return userMetricsFor(url, dimension)
  const eventName = queries.find(
    (query) => query.attribute === 'eventName',
  )?.values[0]
  if (eventName in MOBILE_EVENT_COUNTS) {
    if (!options.mobileEvents) return []
    if (url.searchParams.get('interval'))
      return [
        { ...row(null, 1), date: NOW, events: MOBILE_EVENT_COUNTS[eventName] },
      ]
    // Ranked by visitors, as the API does; opens disagree with that order.
    return [
      row('msg-launch', 9, { events: 90 }),
      row('msg-sale', 5, { events: 500 }),
    ]
  }
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
    // Ranked by visitors, as the API does; events disagree with that order.
    if (propKey === 'model')
      return options.agentProps
        ? [
            row('gpt-5', 2, { events: 40 }),
            row('claude-sonnet-5-5', 1, { events: 900 }),
          ]
        : []
    if (propKey === 'tool') return []
    if (options.agentProps) return [row('model', 3), row('plan', 2)]
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
  let siteAnalyticsEnabled = false
  const mock: Mock = {
    metrics: [],
    patches: [],
    annotations: [],
    siteAnalytics: [],
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

    if (apiPath === `/analytics/properties/${PROPERTY_ID}/metrics`) {
      mock.metrics.push(url)
      const metrics = metricsFor(url, options)
      return json(200, { total: metrics.length, metrics })
    }
    const annotationsPath = `/analytics/properties/${PROPERTY_ID}/annotations`
    if (apiPath === annotationsPath) {
      if (request.method() === 'POST') {
        const { annotationId, ...fields } = request.postDataJSON()
        const annotation = {
          $id: annotationId,
          $createdAt: NOW,
          $updatedAt: NOW,
          propertyId: PROPERTY_ID,
          userId: ACCOUNT.$id,
          ...fields,
        }
        mock.annotations.push(annotation)
        return json(201, annotation)
      }
      return json(200, {
        total: mock.annotations.length,
        annotations: mock.annotations,
      })
    }
    if (apiPath.startsWith(`${annotationsPath}/`)) {
      const id = apiPath.slice(annotationsPath.length + 1)
      const index = mock.annotations.findIndex((note) => note.$id === id)
      if (index === -1) {
        return json(404, {
          message: 'Analytics annotation not found',
          code: 404,
          type: 'analytics_annotation_not_found',
        })
      }
      if (request.method() === 'DELETE') {
        mock.annotations.splice(index, 1)
        return route.fulfill({ status: 204, headers })
      }
      mock.annotations[index] = {
        ...mock.annotations[index],
        ...request.postDataJSON(),
      }
      return json(200, mock.annotations[index])
    }
    if (apiPath === `/analytics/properties/${PROPERTY_ID}`) {
      if (request.method() === 'PATCH') {
        mock.patches.push(request)
        identifyUsers = request.postDataJSON().identifyUsers ?? identifyUsers
      }
      return json(200, property(identifyUsers, options.type))
    }
    if (apiPath === '/analytics/properties') {
      const isCount = listParam(url, 'queries').some((query) => {
        const { method, values } = JSON.parse(query)
        return method === 'limit' && values[0] === 1
      })
      // The route loader awaits the full list, so only the count waits.
      if (isCount) await options.countGate
      return json(200, {
        total: 1,
        properties: [property(identifyUsers, options.type)],
      })
    }

    if (apiPath === `/sites/${SITE_ID}/analytics`) {
      mock.siteAnalytics.push(request)
      if (options.propertyLimitReached) {
        return json(402, {
          message:
            'You have reached the limit for analytics properties on this plan. Upgrade to increase the limit.',
          code: 402,
          type: 'analytics_property_limit_exceeded',
        })
      }
      siteAnalyticsEnabled = request.postDataJSON().enabled
      return json(200, site(siteAnalyticsEnabled))
    }
    if (apiPath === `/sites/${SITE_ID}`)
      return json(200, site(siteAnalyticsEnabled))
    // The rest of what the site settings loader waits for.
    if (apiPath.endsWith('/variables'))
      return json(200, { total: 0, variables: [] })
    if (apiPath === '/sites/frameworks')
      return json(200, { total: 0, frameworks: [] })
    if (apiPath === '/vcs/installations')
      return json(200, { total: 0, installations: [] })

    if (apiPath === `/organizations/${ORGANIZATION.$id}/plan`) {
      return json(200, {
        $id: ORGANIZATION.billingPlan,
        name: 'Free',
        analyticsProperties: options.planPropertyLimit ?? 0,
      })
    }

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

/** Overview metric tabs; the interval toggle is pressable too, without an info hint. */
function overviewMetrics(page: Page) {
  return page
    .locator('[role="button"][aria-pressed]')
    .filter({ has: page.getByRole('button', { name: 'More info' }) })
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
    const usersMetric = page
      .locator('[role="button"][aria-pressed]')
      .filter({ has: page.getByText('Users', { exact: true }) })
    await expect(usersMetric).toContainText('37')

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
    // Per-bucket users don't sum to a range total, so none is shown.
    await expect(usersMetric).not.toContainText('37')
    await expect(usersMetric).toContainText('-')
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

  test('shows the signed-in share of visitors', async ({ page }) => {
    await mockAppwriteApi(page, { identifyUsers: true, hasUsers: true })
    await openProperty(page)

    const usersMetric = overviewMetrics(page).filter({
      has: page.getByText('Users', { exact: true }),
    })
    await expect(usersMetric).toContainText('37', { timeout: 30_000 })
    // 37 users over 120 visitors in the flat aggregate.
    await usersMetric.hover()
    await expect(page.getByRole('tooltip')).toContainText(
      '30.8% of visitors signed in',
    )
    await usersMetric.click()
    await expect(
      page.getByText('· 30.8% of visitors signed in'),
    ).toBeVisible()
  })

  test('opens a user from the Users card', async ({ page }) => {
    const mock = await mockAppwriteApi(page, {
      identifyUsers: true,
      hasUsers: true,
    })
    await openProperty(page)

    await card(page, 'Users')
      .getByText('user-a')
      .click({ timeout: 30_000 })
    await expect(page).toHaveURL(
      new RegExp(`/analytics/${PROPERTY_ID}/users/user-a$`),
    )

    const stats = page.locator('main')
    await expect(stats.getByText('Visits', { exact: true })).toBeVisible()
    for (const [label, value] of [
      ['Visits', '3'],
      ['Pageviews', '8'],
      ['Events', '13'],
    ]) {
      await expect(
        stats
          .locator('div')
          .filter({ has: page.getByText(label, { exact: true }) })
          .filter({ hasText: new RegExp(`^${label}${value}$`) }),
      ).toHaveCount(1)
    }
    await expect(stats.getByText('2 hours ago')).toBeVisible()

    const pages = card(page, 'Pages')
    await expect(pages.getByText(/^\/(docs|pricing)$/)).toHaveText([
      '/docs',
      '/pricing',
    ])
    const events = card(page, 'Custom events')
    await expect(events.getByText('signup')).toBeVisible()
    await expect(events.getByText('pageview')).toHaveCount(0)

    // userId is events-only, so the flat aggregate is never asked for it.
    const forUser = mock.metrics.filter((url) =>
      listParam(url, 'queries').some((query) => query.includes('userId')),
    )
    expect(forUser.length).toBeGreaterThan(0)
    expect(
      forUser.some(
        (url) =>
          !url.searchParams.get('interval') &&
          listParam(url, 'dimensions').length === 0,
      ),
    ).toBe(false)
    await attachScreenshot(page, 'property-user')

    // A value opens the property filtered by the user and that value.
    await pages.getByText('/docs').click()
    await expect(page).toHaveURL(new RegExp(`/analytics/${PROPERTY_ID}\\?`))
    await expect(page.getByText('Filtered by')).toBeVisible()
    await expect(page.locator('main')).toContainText('user-a')
    await expect(page.locator('main')).toContainText('/docs')
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

  test('uses app terms and leaves bounce rate off a mobile property', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { type: AnalyticsPropertyType.Mobile })
    await openProperty(page)

    const metrics = overviewMetrics(page)
    await expect(metrics.first()).toContainText('Active users', {
      timeout: 30_000,
    })
    await expect(metrics).toHaveText([
      /^Active users/,
      /^Sessions/,
      /^Screen views/,
      /^Events/,
      /^Screens\/session/,
      /^Session length/,
      /^Engagement time/,
    ])
    await expect(
      page.locator('main').getByText('Mobile', { exact: true }).first(),
    ).toBeVisible()
    await expect(
      card(page, 'Locations').getByText('Where your users are'),
    ).toBeVisible()
    await attachScreenshot(page, 'property-mobile-app-terms')
  })

  test('drops browser tabs from a mobile Technology card', async ({ page }) => {
    await mockAppwriteApi(page, { type: AnalyticsPropertyType.Mobile })
    await openProperty(page)

    const technology = card(page, 'Technology')
    await expect(technology.getByText('What your users are on')).toBeVisible({
      timeout: 30_000,
    })
    await expect(technology.getByRole('tab')).toHaveText([
      'Operating systems',
      'Devices',
      'Screen sizes',
      'OS versions',
    ])
    await expect(
      technology.getByRole('button', { name: /Versions/ }),
    ).toHaveCount(0)
  })

  test('counts installs and push opens on a mobile property', async ({
    page,
  }) => {
    await mockAppwriteApi(page, {
      type: AnalyticsPropertyType.Mobile,
      mobileEvents: true,
    })
    await openProperty(page)

    const installs = card(page, 'Installs and push')
    await expect(installs).toContainText('1.2K', { timeout: 30_000 })
    for (const label of ['Installs', 'Updates', 'Push received', 'Push opened'])
      await expect(installs.getByText(label, { exact: true })).toBeVisible()
    await expect(installs.getByText(/^msg-(launch|sale)$/)).toHaveText([
      'msg-sale',
      'msg-launch',
    ])
    await installs.scrollIntoViewIfNeeded()
    await attachScreenshot(page, 'property-mobile-installs-push')
  })

  test('asks for the tracking SDK before the first install', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { type: AnalyticsPropertyType.Mobile })
    await openProperty(page)

    const installs = card(page, 'Installs and push')
    await expect(installs.getByText('No installs tracked yet')).toBeVisible({
      timeout: 30_000,
    })
    await expect(
      installs.getByRole('button', { name: 'Add the tracking SDK' }),
    ).toBeVisible()
  })

  test('leads with events on an agent property', async ({ page }) => {
    await mockAppwriteApi(page, { type: AnalyticsPropertyType.Agent })
    await openProperty(page)

    const metrics = overviewMetrics(page)
    await expect(metrics.first()).toContainText('Events', { timeout: 30_000 })
    await expect(metrics.first()).toHaveAttribute('aria-pressed', 'true')
    await expect(metrics).toHaveText([/^Events/, /^Visits/, /^Visit duration/])
    await attachScreenshot(page, 'property-agent-metrics')
  })

  test('ranks agent models by calls', async ({ page }) => {
    await mockAppwriteApi(page, {
      type: AnalyticsPropertyType.Agent,
      agentProps: true,
    })
    await openProperty(page)

    const models = card(page, 'Models and tools')
    await expect(models).toBeVisible({ timeout: 30_000 })
    // Only keys that arrived get a tab, and a single tab shows no tab bar.
    await expect(models.getByRole('tab')).toHaveCount(0)
    await expect(models.getByText(/^(gpt-5|claude-sonnet-5-5)$/)).toHaveText([
      'claude-sonnet-5-5',
      'gpt-5',
    ])
    await expect(models).toContainText('900')
    await attachScreenshot(page, 'property-agent-models')
  })

  test('hides models and tools without agent props', async ({ page }) => {
    await mockAppwriteApi(page, { type: AnalyticsPropertyType.Agent })
    await openProperty(page)

    await expect(card(page, 'Event properties')).toBeVisible({
      timeout: 30_000,
    })
    await expect(cardHeading(page, 'Models and tools')).toHaveCount(0)
  })

  test('adds, edits and deletes a chart note', async ({ page }) => {
    const mock = await mockAppwriteApi(page)
    await openProperty(page)

    await page.getByRole('button', { name: 'Add note' }).click({
      timeout: 30_000,
    })
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Note text').fill('Launched pricing page')
    await dialog.getByRole('button', { name: 'Add', exact: true }).click()
    await expect(page.getByText('Note added')).toBeVisible()
    expect(mock.annotations.map((note) => note.content)).toEqual([
      'Launched pricing page',
    ])

    await page.getByRole('button', { name: 'Show notes' }).click()
    const popover = page.locator('[data-slot="popover-content"]')
    await expect(popover).toContainText('Launched pricing page')
    await expect(popover).toContainText('You')
    await attachScreenshot(page, 'property-chart-note')

    await popover.getByRole('button', { name: 'Edit note' }).click()
    await dialog.getByLabel('Note text').fill('Launched the pricing page')
    await dialog.getByRole('button', { name: 'Update' }).click()
    await expect(page.getByText('Note updated')).toBeVisible()
    expect(mock.annotations[0].content).toBe('Launched the pricing page')

    await page.getByRole('button', { name: 'Show notes' }).click()
    await popover.getByRole('button', { name: 'Delete note' }).click()
    await page.getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(page.getByText('Note deleted')).toBeVisible()
    expect(mock.annotations).toEqual([])
    await expect(page.getByRole('button', { name: 'Show notes' })).toHaveCount(
      0,
    )
  })

  test('edits and deletes from a note popover without adding a note', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    await openProperty(page)

    await page.getByRole('button', { name: 'Add note' }).click({
      timeout: 30_000,
    })
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Note text').fill('Launched pricing page')
    await dialog.getByRole('button', { name: 'Add', exact: true }).click()
    await expect(page.getByText('Note added')).toBeVisible()
    await expect(dialog).toHaveCount(0)

    const popover = page.locator('[data-slot="popover-content"]')
    await page.getByRole('button', { name: 'Show notes' }).click()
    await popover.getByRole('button', { name: 'Edit note' }).click()
    await expect(dialog).toHaveCount(1)
    await expect(dialog).toContainText('Edit note')
    await dialog.getByRole('button', { name: 'Cancel' }).click()
    await expect(dialog).toHaveCount(0)

    await page.getByRole('button', { name: 'Show notes' }).click()
    await popover.getByRole('button', { name: 'Delete note' }).click()
    await expect(dialog).toHaveCount(1)
    await expect(dialog).toContainText('Delete note')
    await dialog.getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(page.getByText('Note deleted')).toBeVisible()
    expect(mock.annotations).toEqual([])
    await expect(dialog).toHaveCount(0)
  })

  test('wraps a long unbroken note', async ({ page }) => {
    await mockAppwriteApi(page)
    await openProperty(page)

    await page.getByRole('button', { name: 'Add note' }).click({
      timeout: 30_000,
    })
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Note text').fill('a'.repeat(600))
    await expect(dialog.getByText('512/512')).toBeVisible()
    const viewport = page.viewportSize()!
    const box = await dialog.boundingBox()
    expect(box!.x + box!.width).toBeLessThanOrEqual(viewport.width)
    await expect(
      dialog.getByRole('button', { name: 'Add', exact: true }),
    ).toBeInViewport({ ratio: 1 })
    await dialog.getByRole('button', { name: 'Add', exact: true }).click()
    await expect(page.getByText('Note added')).toBeVisible()

    await page.getByRole('button', { name: 'Show notes' }).click()
    const popover = page.locator('[data-slot="popover-content"]')
    await expect(popover.getByText('a'.repeat(512))).toBeVisible()
    const popoverBox = await popover.boundingBox()
    const textBox = await popover.getByText('a'.repeat(512)).boundingBox()
    expect(textBox!.x + textBox!.width).toBeLessThanOrEqual(
      popoverBox!.x + popoverBox!.width,
    )
  })

  test('zooms on a drag without adding a note', async ({ page }) => {
    const mock = await mockAppwriteApi(page)
    await openProperty(page)

    const chart = page.getByLabel('Drag on the chart to select a date range')
    const box = await chart.boundingBox({ timeout: 30_000 })
    if (!box) throw new Error('Chart has no box')
    const seriesStarts = () =>
      mock.metrics
        .filter((url) => url.searchParams.get('interval'))
        .map((url) => url.searchParams.get('startAt') ?? '')
    await expect.poll(() => seriesStarts().length).toBeGreaterThan(0)
    const initialStart = seriesStarts().sort().at(-1)!
    const y = box.y + box.height / 2
    await page.mouse.move(box.x + box.width * 0.3, y)
    // The brush anchors on the hovered bucket, so wait for the hover to land.
    await expect(chart.locator('.recharts-tooltip-wrapper')).toBeVisible()
    await page.mouse.down()
    await page.mouse.move(box.x + box.width * 0.6, y, { steps: 5 })
    await page.mouse.up()

    // The zoomed range starts after the initial one, so the brush committed.
    await expect
      .poll(() => seriesStarts().some((start) => start > initialStart))
      .toBe(true)
    await expect(page.getByRole('dialog')).toHaveCount(0)
    await chart.click({ position: { x: box.width * 0.5, y: box.height / 2 } })
    await expect(page.getByRole('dialog')).toContainText('Add note')
  })

  test('blocks Create property at the plan limit', async ({ page }) => {
    await mockAppwriteApi(page, { planPropertyLimit: 1 })
    await page.goto(`/projects/${PROJECT_ID}/analytics`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(
      page.getByText("You've reached the limit of 1 analytics property"),
    ).toBeVisible({ timeout: 30_000 })
    // A disabled header create renders as an aria-disabled span, not a link.
    await expect(
      page.locator('[aria-disabled]').filter({ hasText: 'Create property' }),
    ).toBeVisible()
    await expect(
      page.getByRole('link', { name: 'Create property' }),
    ).toHaveCount(0)
    await attachScreenshot(page, 'analytics-property-limit')
  })

  test('leaves Create property on below the plan limit', async ({ page }) => {
    await mockAppwriteApi(page, { planPropertyLimit: 5 })
    await page.goto(`/projects/${PROJECT_ID}/analytics`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(page.getByText('Acme site').first()).toBeVisible({
      timeout: 30_000,
    })
    await expect(
      page.getByRole('link', { name: 'Create property' }),
    ).toBeVisible()
    await expect(page.getByText("You've reached the limit of")).toHaveCount(0)
  })

  test('blocks the create form at the plan limit', async ({ page }) => {
    await mockAppwriteApi(page, { planPropertyLimit: 1 })
    await page.goto(`/projects/${PROJECT_ID}/analytics/add`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(page.getByTestId('analytics-property-plan-limit')).toHaveText(
      'Your current plan includes up to 1 analytics property per project. Upgrade your plan to create another.',
      { timeout: 30_000 },
    )
    await expect(
      page.getByRole('button', { name: 'Create property' }),
    ).toBeDisabled()
  })

  test('holds the create form until the plan limit is known', async ({
    page,
  }) => {
    let releaseCount = () => {}
    const countGate = new Promise<void>((resolve) => {
      releaseCount = resolve
    })
    await mockAppwriteApi(page, { planPropertyLimit: 5, countGate })
    await page.goto(`/projects/${PROJECT_ID}/analytics/add`, {
      waitUntil: 'domcontentloaded',
    })

    const create = page.getByRole('button', { name: 'Create property' })
    await expect(create).toBeDisabled({ timeout: 30_000 })
    releaseCount()
    await expect(create).toBeEnabled()
    await expect(page.getByTestId('analytics-property-plan-limit')).toHaveCount(
      0,
    )
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

  test('turns analytics on for a site', async ({ page }) => {
    const mock = await mockAppwriteApi(page)
    await page.goto(`/projects/${PROJECT_ID}/sites/${SITE_ID}/settings`, {
      waitUntil: 'domcontentloaded',
    })

    const toggle = page.locator('#site-analytics')
    await expect(toggle).not.toBeChecked({ timeout: 30_000 })
    await expect(
      page.getByRole('link', { name: 'View analytics' }),
    ).toHaveCount(0)
    await toggle.click()
    await page
      .locator('div.rounded-xl')
      .filter({ has: toggle })
      .getByRole('button', { name: 'Update', exact: true })
      .click()
    await expect(page.getByText('Analytics enabled')).toBeVisible()
    expect(mock.siteAnalytics.map((patch) => patch.postDataJSON())).toEqual([
      { enabled: true },
    ])
    await expect(
      page.getByRole('link', { name: 'View analytics' }),
    ).toHaveAttribute(
      'href',
      `/projects/${PROJECT_ID}/analytics/${PROPERTY_ID}`,
    )
    await attachScreenshot(page, 'site-settings-analytics')
  })

  test('turns the site switch back off when the update fails', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, { propertyLimitReached: true })
    await page.goto(`/projects/${PROJECT_ID}/sites/${SITE_ID}/settings`, {
      waitUntil: 'domcontentloaded',
    })

    const toggle = page.locator('#site-analytics')
    const siteCard = page.locator('div.rounded-xl').filter({ has: toggle })
    const update = siteCard.getByRole('button', { name: 'Update', exact: true })
    await expect(toggle).not.toBeChecked({ timeout: 30_000 })
    await expect(siteCard).toContainText('billed as analytics usage')
    await toggle.click()
    await expect(siteCard).toContainText('script-src https://sdk.appwrite.io')
    await update.click()

    await expect(
      page.getByText('You have reached the limit for analytics properties'),
    ).toBeVisible()
    await expect(toggle).not.toBeChecked()
    await expect(update).toBeDisabled()
    await expect(siteCard).toContainText('Disabled - no tracker is added.')
    expect(mock.siteAnalytics).toHaveLength(1)
    await attachScreenshot(page, 'site-settings-analytics-limit')
  })
})
