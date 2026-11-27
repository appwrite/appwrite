import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Runtime logs on a function, a site, a deployment's detail page, and the
 * project's Logs page.
 *
 * `/v1/logs` needs a deploymentId for functions and sites, so each view lists
 * the lines of one deployment: the picker on the resource tabs defaults to the
 * active deployment, and the deployment page reads its own. The stream filter
 * is sent to the API; the search box filters the lines already loaded. The
 * project's Logs page names no resource and lists every one.
 *
 * The API is mocked at the network layer.
 */

const NOW = '2026-11-23T13:55:15.000+00:00'

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
  name: 'Personal projects',
  billingPlan: 'tier-0',
  billingEmail: ACCOUNT.email,
  status: 'active',
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT = {
  $id: 'proj0000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme',
  teamId: ORGANIZATION.$id,
  region: 'tor',
  status: 'active',
} satisfies Partial<Models.Project>

const ACTIVE = 'dep00000000000000000001'
const OLDER = 'dep00000000000000000000'

const FUNCTION = {
  $id: 'func0000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Checkout',
  enabled: true,
  live: true,
  logging: true,
  runtime: 'node-22',
  deploymentId: ACTIVE,
  latestDeploymentId: ACTIVE,
  latestDeploymentStatus: 'ready',
  vars: [],
  events: [],
  schedule: '',
  timeout: 15,
  entrypoint: 'index.js',
  commands: 'npm install',
  execute: [],
} satisfies Partial<Models.Function>

const SITE = {
  $id: 'site0000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Landing',
  enabled: true,
  live: true,
  logging: true,
  framework: 'nextjs',
  deploymentId: ACTIVE,
  latestDeploymentId: ACTIVE,
  latestDeploymentStatus: 'ready',
  vars: [],
} satisfies Partial<Models.Site>

/** The older deployment came from Git, the active one was uploaded by hand. */
const OLDER_COMMIT = 'Fix checkout totals'

function deployment(id: string, resourceType: string, resourceId: string) {
  const git = id === OLDER
  return {
    $id: id,
    $createdAt: NOW,
    $updatedAt: NOW,
    type: git ? 'vcs' : 'manual',
    resourceId,
    resourceType,
    entrypoint: 'index.js',
    sourceSize: 1024,
    buildSize: 2048,
    totalSize: 3072,
    buildId: '',
    activate: true,
    screenshotLight: '',
    screenshotDark: '',
    status: 'ready',
    buildLogs: 'Build finished',
    buildDuration: 12,
    providerRepositoryName: '',
    providerRepositoryOwner: '',
    providerRepositoryUrl: '',
    providerCommitHash: '',
    providerCommitAuthorUrl: '',
    providerCommitAuthor: '',
    providerCommitMessage: git
      ? `${OLDER_COMMIT}\n\nRound totals to cents before charging.`
      : '',
    providerCommitUrl: '',
    providerBranch: '',
    providerBranchUrl: '',
  }
}

const STDOUT = 'Listening on port 3000'
const STDERR = 'TypeError: Cannot read properties of undefined'
const OTHER_DEPLOYMENT = 'Served by the older deployment'

/** Structured lines: pino JSON, a nested error, and logfmt. */
const JSON_INFO = JSON.stringify({
  level: 30,
  time: 1732370115000,
  msg: 'request completed',
  method: 'POST',
  path: '/checkout',
  status: 200,
  durationMs: 42,
})
const JSON_WARN = JSON.stringify({
  level: 'warn',
  msg: 'slow query',
  table: 'orders',
  durationMs: 812,
})
const JSON_ERROR = JSON.stringify({
  level: 'error',
  msg: 'payment failed',
  orderId: 'ord_8f2k',
  err: {
    type: 'CardError',
    message: 'Your card was declined.',
    stack:
      'CardError: Your card was declined.\n    at charge (/usr/local/server/src/payments.js:41:11)',
  },
})
const LOGFMT_DEBUG = 'level=debug msg="cache miss" key=cart:42 ttl=300'

/** One clock for every fixture row, so live-tail rows land after the listed ones. */
const BASE = Date.now()

/** Rows that arrive while Live is on: more than one page, newer than the listed rows. */
const LIVE_ROWS = 150

function liveRows(resourceType: string, resourceId: string) {
  return Array.from({ length: LIVE_ROWS }, (_, i) => ({
    ...log(
      `live${String(i).padStart(3, '0')}`,
      resourceType,
      resourceId,
      ACTIVE,
      'stdout',
      `live line ${String(i).padStart(3, '0')}`,
      0,
    ),
    timestamp: new Date(BASE - 4000 + i * 10).toISOString(),
  }))
}

function log(
  id: string,
  resourceType: string,
  resourceId: string,
  deploymentId: string,
  stream: string,
  message: string,
  seconds: number,
): Models.RuntimeLog {
  return {
    $id: id,
    resourceType,
    resourceId,
    resourceInternalId: '1',
    deploymentId,
    region: 'tor',
    timestamp: new Date(BASE - seconds * 1000).toISOString(),
    stream,
    message,
  }
}

function logsFor(resourceType: string, resourceId: string) {
  return [
    log('log1', resourceType, resourceId, ACTIVE, 'stderr', STDERR, 5),
    log('json1', resourceType, resourceId, ACTIVE, 'stdout', JSON_INFO, 6),
    log('json2', resourceType, resourceId, ACTIVE, 'stdout', LOGFMT_DEBUG, 7),
    log('json3', resourceType, resourceId, ACTIVE, 'stdout', JSON_WARN, 8),
    log('json4', resourceType, resourceId, ACTIVE, 'stderr', JSON_ERROR, 9),
    log('log2', resourceType, resourceId, ACTIVE, 'stdout', STDOUT, 10),
    log(
      'log3',
      resourceType,
      resourceId,
      OLDER,
      'stdout',
      OTHER_DEPLOYMENT,
      20,
    ),
  ]
}

const FUNCTION_LINE = 'function says hello'
const SITE_LINE = 'site rendered /pricing'
const DATABASE_LINE = 'checkpoint complete'

function projectLogs() {
  return [
    log('plog0', 'functions', FUNCTION.$id, ACTIVE, 'stdout', JSON_INFO, 3),
    log('plog1', 'functions', FUNCTION.$id, ACTIVE, 'stdout', FUNCTION_LINE, 5),
    log('plog4', 'sites', SITE.$id, ACTIVE, 'stdout', JSON_WARN, 8),
    log('plog2', 'sites', SITE.$id, ACTIVE, 'stderr', SITE_LINE, 10),
    log('plog3', 'databases', 'compute1', '', 'stdout', DATABASE_LINE, 15),
  ]
}

type ParsedQuery = { method: string; attribute?: string; values?: unknown[] }

function queries(url: URL): ParsedQuery[] {
  return [...url.searchParams]
    .filter(([key]) => key.startsWith('queries'))
    .map(([, value]) => JSON.parse(value) as ParsedQuery)
}

/** Values of the `<method>("<attribute>", [...])` query in a list request. */
function queryValues(
  url: URL,
  method: string,
  attribute: string,
): string[] | undefined {
  const query = queries(url).find(
    (q) => q.method === method && q.attribute === attribute,
  )
  return query ? (query.values ?? []).map(String) : undefined
}

function equalValues(url: URL, attribute: string): string[] | undefined {
  return queryValues(url, 'equal', attribute)
}

/** Whether a row falls inside the request's timestamp bounds, when it names both. */
function inWindow(url: URL, timestamp: string): boolean {
  const from = queryValues(url, 'greaterThanEqual', 'timestamp')?.[0]
  const to = queryValues(url, 'lessThanEqual', 'timestamp')?.[0]
  if (!to) return true
  const time = Date.parse(timestamp)
  return (!from || time >= Date.parse(from)) && time <= Date.parse(to)
}

function corsHeaders(route: Route): Record<string, string> {
  return {
    'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': '*',
    'access-control-allow-methods': '*',
  }
}

/** Turns the runtime logs flag on the way the debug menu does. */
async function enableRuntimeLogs(
  page: Page,
  id: 'cloud' | 'self-hosted' = 'cloud',
) {
  await page.context().addInitScript((id) => {
    const profile = {
      id,
      label: id,
      description: 'E2E override - runtime logs enabled',
      features: { runtimeLogs: true },
    }
    window.localStorage.setItem('debug:consoleProfile', JSON.stringify(profile))
    document.cookie = `debug_console_profile=${encodeURIComponent(
      JSON.stringify({ id: profile.id, features: profile.features }),
    )}; path=/; SameSite=Lax`
  }, id)
}

async function mockAppwriteApi(page: Page) {
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin

  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const apiPath = url.pathname.startsWith('/v1/')
      ? url.pathname.slice('/v1'.length)
      : undefined
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

    if (apiPath === '/logs/usage') {
      // Lines per bucket, counted from the same rows the list returns
      const resourceType = url.searchParams.get('resourceType') ?? ''
      const resourceId = url.searchParams.get('resourceId') ?? ''
      const step =
        { '1m': 60, '15m': 900, '1h': 3600, '1d': 86400 }[
          url.searchParams.get('interval') ?? '1h'
        ]! * 1000
      const deploymentIds = equalValues(url, 'deploymentId')
      const rows = (
        resourceType ? logsFor(resourceType, resourceId) : projectLogs()
      ).filter(
        (row) =>
          (!deploymentIds || deploymentIds.includes(row.deploymentId)) &&
          inWindow(url, row.timestamp),
      )
      const buckets = new Map<number, { total: number; stderr: number }>()
      for (const row of rows) {
        const start = Math.floor(Date.parse(row.timestamp) / step) * step
        const bucket = buckets.get(start) ?? { total: 0, stderr: 0 }
        bucket.total++
        if (row.stream === 'stderr') bucket.stderr++
        buckets.set(start, bucket)
      }
      return json(200, {
        interval: url.searchParams.get('interval'),
        linesTotal: rows.length,
        buckets: [...buckets]
          .sort(([a], [b]) => a - b)
          .map(([start, bucket]) => ({
            date: new Date(start).toISOString(),
            ...bucket,
          })),
      })
    }

    if (apiPath === '/logs') {
      const resourceType = url.searchParams.get('resourceType') ?? ''
      const resourceId = url.searchParams.get('resourceId') ?? ''
      if (!resourceType && !resourceId) {
        // The project's Logs page: every resource, narrowed by queries.
        const types = equalValues(url, 'resourceType')
        const ids = equalValues(url, 'resourceId')
        const streams = equalValues(url, 'stream')
        const logs = projectLogs().filter(
          (row) =>
            (!types || types.includes(row.resourceType)) &&
            (!ids || ids.includes(row.resourceId)) &&
            (!streams || streams.includes(row.stream)),
        )
        return json(200, { total: 0, logs })
      }
      const deploymentIds = equalValues(url, 'deploymentId')
      if (!deploymentIds) {
        return json(400, {
          message: 'Query for equal("deploymentId", ...) is required.',
          code: 400,
          type: 'general_query_invalid',
          version: '1.0',
        })
      }
      const streams = equalValues(url, 'stream')
      const since = queryValues(url, 'greaterThanEqual', 'timestamp')?.[0]
      const ascending = queries(url).some((q) => q.method === 'orderAsc')
      if (ascending && since) {
        // Live tail: newer rows, oldest first, one page at a time.
        const logs = liveRows(resourceType, resourceId)
          .filter((row) => Date.parse(row.timestamp) >= Date.parse(since))
          .slice(0, 100)
        return json(200, { total: 0, logs })
      }
      const logs = logsFor(resourceType, resourceId).filter(
        (row) =>
          deploymentIds.includes(row.deploymentId) &&
          (!streams || streams.includes(row.stream)) &&
          inWindow(url, row.timestamp),
      )
      return json(200, { total: 0, logs })
    }

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') {
      return request.method() === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, {})
    }
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/console/variables') return json(200, {})
    if (apiPath === '/health/version') return json(200, { version: '2.0.0' })
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, {
        roles: ['owner'],
        scopes: [
          'projects.read',
          'projects.write',
          'functions.read',
          'functions.write',
          'sites.read',
          'sites.write',
          'log.read',
        ],
      })
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
      apiPath === `/projects/${PROJECT.$id}` ||
      apiPath === `/projects/${PROJECT.$id}/console-access`
    )
      return json(200, PROJECT)

    for (const [collection, resource] of [
      ['functions', FUNCTION],
      ['sites', SITE],
    ] as const) {
      if (apiPath === `/${collection}`)
        return json(200, { total: 1, [collection]: [resource] })
      const base = `/${collection}/${resource.$id}`
      if (apiPath === base) return json(200, resource)
      if (apiPath === `${base}/deployments`) {
        const deployments = [ACTIVE, OLDER].map((id) =>
          deployment(id, collection, resource.$id),
        )
        return json(200, { total: deployments.length, deployments })
      }
      for (const id of [ACTIVE, OLDER]) {
        if (apiPath === `${base}/deployments/${id}`)
          return json(200, deployment(id, collection, resource.$id))
      }
      if (apiPath === `${base}/executions`)
        return json(200, { total: 0, executions: [] })
    }

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })
}

async function expectRuntimeLogs(page: Page) {
  const logs = page.getByRole('log')

  // The active deployment's lines, never another deployment's
  await expect(logs).toContainText(STDOUT, { timeout: 30_000 })
  await expect(logs).toContainText(STDERR)
  await expect(logs).not.toContainText(OTHER_DEPLOYMENT)

  // The stream filter goes to the API
  await page.getByRole('combobox', { name: 'Stream' }).click()
  await page.getByRole('option', { name: 'stderr' }).click()
  await expect(logs).toContainText(STDERR)
  await expect(logs).not.toContainText(STDOUT)

  await page.getByRole('combobox', { name: 'Stream' }).click()
  await page.getByRole('option', { name: 'All streams' }).click()
  await expect(logs).toContainText(STDOUT)

  // Search narrows the loaded lines
  await page.getByPlaceholder('Search loaded lines...').fill('port')
  await expect(logs).toContainText(STDOUT)
  await expect(logs).not.toContainText(STDERR)
}

test.describe('runtime logs', () => {
  test('a function lists its active deployment runtime logs', async ({
    page,
  }) => {
    await enableRuntimeLogs(page)
    await mockAppwriteApi(page)

    await page.goto(
      `/projects/${PROJECT.$id}/functions/${FUNCTION.$id}/runtime-logs`,
      { waitUntil: 'domcontentloaded' },
    )

    await expectRuntimeLogs(page)

    // Another deployment from the picker
    await page.getByPlaceholder('Search loaded lines...').fill('')
    const picker = page.getByRole('combobox', { name: 'Deployment' })
    // Deployments without a commit message are named by how they were made
    await expect(picker).toContainText('Manual deployment')
    await picker.click()
    await expect(page.getByRole('option')).toHaveCount(2)
    await page.getByPlaceholder('Search deployments').fill('checkout totals')
    await expect(page.getByRole('option')).toHaveCount(1)
    // Only the commit's first line is shown
    await expect(page.getByRole('option')).toHaveText(
      new RegExp(`^${OLDER_COMMIT}${OLDER}`),
    )
    await page.getByRole('option').filter({ hasText: OLDER }).click()
    await expect(page.getByRole('log')).toContainText(OTHER_DEPLOYMENT)
    await expect(page).toHaveURL(new RegExp(`deploymentId=${OLDER}`))
  })

  test('structured lines show level, message and fields', async ({ page }) => {
    await enableRuntimeLogs(page)
    await mockAppwriteApi(page)

    await page.goto(
      `/projects/${PROJECT.$id}/functions/${FUNCTION.$id}/runtime-logs`,
      { waitUntil: 'domcontentloaded' },
    )
    const logs = page.getByRole('log')
    await expect(logs).toContainText('request completed', { timeout: 30_000 })

    // Message first, then fields; no raw JSON and no metadata columns
    const info = logs.getByRole('button', { name: /request completed/ })
    await expect(info).toContainText('INFO')
    await expect(info).toContainText('path=/checkout')
    await expect(info).not.toContainText('"msg"')
    await expect(info).not.toContainText('stdout')
    await expect(
      logs.getByRole('button', { name: /cache miss/ }),
    ).toContainText('key=cart:42')

    // Expanding shows every field, nested ones by path, and the metadata
    const error = logs.getByRole('button', { name: /payment failed/ })
    await error.click()
    await expect(error).toHaveAttribute('aria-expanded', 'true')
    await expect(logs).toContainText('err.stack')
    await expect(logs).toContainText('at charge (')
    await expect(logs).toContainText(ACTIVE)
    // ...and the whole entry as logged, indented
    await expect(logs).toContainText('"msg": "payment failed"')

    // The level filter narrows the loaded lines to the checked levels
    await page.getByRole('button', { name: 'Level', exact: true }).click()
    const level = (name: string) =>
      page.getByRole('menuitemcheckbox', { name, exact: true })
    await level('Info').click()
    await level('Debug').click()
    await expect(level('All levels')).not.toBeChecked()
    // The open menu hides the page from the accessibility tree
    await expect(
      page.getByRole('button', {
        name: 'Level',
        exact: true,
        includeHidden: true,
      }),
    ).toHaveText('Error, Warning')
    await expect(logs).toContainText('slow query')
    await expect(logs).toContainText('payment failed')
    await expect(logs).toContainText(STDERR)
    await expect(logs).not.toContainText('request completed')
    await expect(logs).not.toContainText('cache miss')
    await expect(logs).not.toContainText(STDOUT)

    // Levels need not be adjacent: errors and debug, without warnings
    await level('Warning').click()
    await level('Debug').click()
    await expect(logs).toContainText('payment failed')
    await expect(logs).toContainText('cache miss')
    await expect(logs).not.toContainText('slow query')
    await expect(logs).not.toContainText('request completed')

    // Time, stream and region as columns on request
    await level('All levels').click()
    await expect(level('Warning')).toBeChecked()
    await page.keyboard.press('Escape')
    await page
      .getByRole('button', { name: 'Show time, stream and region' })
      .click()
    await expect(info).toContainText('stdout')
    await expect(info).toContainText('tor')
  })

  test('the volume chart counts lines and zooms into a dragged window', async ({
    page,
  }) => {
    await enableRuntimeLogs(page)
    await mockAppwriteApi(page)

    await page.goto(
      `/projects/${PROJECT.$id}/functions/${FUNCTION.$id}/runtime-logs`,
      { waitUntil: 'domcontentloaded' },
    )

    const chart = page.getByRole('region', { name: 'Log volume' })
    // Six lines from the active deployment in the last hour
    await expect(chart).toContainText('6 lines', { timeout: 30_000 })
    await expect(page.getByRole('log')).toContainText(STDOUT)

    // Drag across the first part of the hour, long before any line
    const surface = chart.locator('.recharts-wrapper')
    const box = (await surface.boundingBox())!
    const y = box.y + box.height / 2
    // Recharts only knows the bar under the pointer after a hover, so settle on it before pressing
    await page.mouse.move(box.x + box.width * 0.15, y, { steps: 4 })
    await expect(chart.locator('.recharts-tooltip-wrapper')).toBeVisible()
    await page.mouse.down()
    await page.mouse.move(box.x + box.width * 0.3, y, { steps: 8 })
    await page.mouse.up()

    await expect(
      page.getByRole('combobox', { name: 'Time range' }),
    ).toContainText('Selected range')
    await expect(chart).toContainText('0 lines')
    await expect(page.getByText('No logs', { exact: true })).toBeVisible()

    await chart.getByRole('button', { name: 'Reset zoom' }).click()
    await expect(
      page.getByRole('combobox', { name: 'Time range' }),
    ).toContainText('Last hour')
    await expect(page.getByRole('log')).toContainText(STDOUT)
    await expect(chart.getByRole('button', { name: 'Reset zoom' })).toHaveCount(
      0,
    )
  })

  test('a site lists its active deployment runtime logs', async ({ page }) => {
    await enableRuntimeLogs(page)
    await mockAppwriteApi(page)

    await page.goto(`/projects/${PROJECT.$id}/sites/${SITE.$id}/runtime-logs`, {
      waitUntil: 'domcontentloaded',
    })

    await expectRuntimeLogs(page)

    // Runtime output is the Logs tab; request logs move to Requests
    await expect(
      page.getByRole('tab', { name: 'Logs', exact: true }),
    ).toHaveAttribute('aria-selected', 'true')
    await expect(page.getByRole('tab', { name: 'Requests' })).toBeVisible()
  })

  test('a deployment page switches from build to runtime logs', async ({
    page,
  }) => {
    await enableRuntimeLogs(page)
    await mockAppwriteApi(page)

    await page.goto(
      `/projects/${PROJECT.$id}/functions/${FUNCTION.$id}/deployments/${OLDER}`,
      { waitUntil: 'domcontentloaded' },
    )

    await page.getByRole('radio', { name: 'Runtime' }).click()
    const logs = page.getByRole('log')
    await expect(logs).toContainText(OTHER_DEPLOYMENT, { timeout: 30_000 })
    await expect(logs).not.toContainText(STDOUT)
  })

  test('live tail reads every new page', async ({ page }) => {
    await enableRuntimeLogs(page)
    await mockAppwriteApi(page)

    await page.goto(
      `/projects/${PROJECT.$id}/functions/${FUNCTION.$id}/runtime-logs`,
      { waitUntil: 'domcontentloaded' },
    )
    const logs = page.getByRole('log')
    await expect(logs).toContainText(STDOUT, { timeout: 30_000 })

    await page.getByRole('button', { name: 'Live' }).click()

    // 150 new rows span two pages; one poll reads both, each row once
    await expect(logs.getByText(/^live line \d+$/)).toHaveCount(LIVE_ROWS)
    await expect(logs).toContainText('live line 000')
    await expect(logs).toContainText('live line 149')
    await expect(logs).toContainText(STDOUT)
  })

  test('the project Logs page lists every resource', async ({ page }) => {
    await enableRuntimeLogs(page)
    await mockAppwriteApi(page)

    await page.goto(`/projects/${PROJECT.$id}/logs`, {
      waitUntil: 'domcontentloaded',
    })
    const logs = page.getByRole('log')
    await expect(logs).toContainText(FUNCTION_LINE, { timeout: 30_000 })
    await expect(logs).toContainText(SITE_LINE)
    await expect(logs).toContainText(DATABASE_LINE)
    await expect(
      page.locator(`a[href$="/projects/${PROJECT.$id}/logs"]`).first(),
    ).toBeVisible()

    // The resource filter goes to the API: a whole kind, or one resource
    const resource = page.getByRole('combobox', { name: 'Resource' })
    await resource.click()
    await page.getByRole('option', { name: 'All sites' }).click()
    await expect(logs).toContainText(SITE_LINE)
    await expect(logs).not.toContainText(FUNCTION_LINE)
    await expect(logs).not.toContainText(DATABASE_LINE)

    await resource.click()
    await expect(page.getByRole('group', { name: 'Functions' })).toBeVisible()
    await page.getByRole('option', { name: FUNCTION.name }).click()
    await expect(resource).toContainText(FUNCTION.name)
    await expect(logs).toContainText(FUNCTION_LINE)
    await expect(logs).not.toContainText(SITE_LINE)

    await resource.click()
    await page.getByRole('option', { name: 'All resources' }).click()
    await expect(logs).toContainText(FUNCTION_LINE)

    // A row links to its resource's own runtime logs, on that deployment
    await logs
      .getByRole('link', { name: `function/${FUNCTION.$id}` })
      .first()
      .click()
    await expect(page).toHaveURL(
      new RegExp(
        `/functions/${FUNCTION.$id}/runtime-logs\\?deploymentId=${ACTIVE}`,
      ),
      { timeout: 30_000 },
    )
  })

  test('the tab stays hidden while the flag is off', async ({ page }) => {
    await mockAppwriteApi(page)

    await page.goto(`/projects/${PROJECT.$id}/functions/${FUNCTION.$id}`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page.getByRole('tab', { name: 'Executions' })).toBeVisible({
      timeout: 30_000,
    })
    await expect(
      page.getByRole('tab', { name: 'Logs', exact: true }),
    ).toHaveCount(0)
    await expect(
      page.locator(`a[href$="/projects/${PROJECT.$id}/logs"]`),
    ).toHaveCount(0)

    // A direct link falls back to the function
    await page.goto(
      `/projects/${PROJECT.$id}/functions/${FUNCTION.$id}/runtime-logs`,
      { waitUntil: 'domcontentloaded' },
    )
    await expect(page).not.toHaveURL(/runtime-logs/, { timeout: 30_000 })

    // And the project Logs page to the overview
    await page.goto(`/projects/${PROJECT.$id}/logs`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page).toHaveURL(/\/overview/, { timeout: 30_000 })
  })

  test('self-hosted keeps runtime logs hidden even with the flag on', async ({
    page,
  }) => {
    await enableRuntimeLogs(page, 'self-hosted')
    await mockAppwriteApi(page)

    await page.goto(`/projects/${PROJECT.$id}/functions/${FUNCTION.$id}`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page.getByRole('tab', { name: 'Executions' })).toBeVisible({
      timeout: 30_000,
    })
    await expect(
      page.getByRole('tab', { name: 'Logs', exact: true }),
    ).toHaveCount(0)

    // Self-hosted servers have no /v1/logs, so a direct link falls back too
    await page.goto(
      `/projects/${PROJECT.$id}/functions/${FUNCTION.$id}/runtime-logs`,
      { waitUntil: 'domcontentloaded' },
    )
    await expect(page).not.toHaveURL(/runtime-logs/, { timeout: 30_000 })
  })
})
