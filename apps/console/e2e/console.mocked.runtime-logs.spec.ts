import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Runtime logs on a function, a site, and a deployment's detail page.
 *
 * `/v1/logs` needs a deploymentId for functions and sites, so each view lists
 * the lines of one deployment: the picker on the resource tabs defaults to the
 * active deployment, and the deployment page reads its own. The stream filter
 * is sent to the API; the search box filters the lines already loaded.
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

function deployment(id: string, resourceType: string, resourceId: string) {
  return {
    $id: id,
    $createdAt: NOW,
    $updatedAt: NOW,
    type: 'manual',
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
    providerCommitMessage: '',
    providerCommitUrl: '',
    providerBranch: '',
    providerBranchUrl: '',
  }
}

const STDOUT = 'Listening on port 3000'
const STDERR = 'TypeError: Cannot read properties of undefined'
const OTHER_DEPLOYMENT = 'Served by the older deployment'

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
    timestamp: new Date(Date.now() - seconds * 1000).toISOString(),
    stream,
    message,
  }
}

function logsFor(resourceType: string, resourceId: string) {
  return [
    log('log1', resourceType, resourceId, ACTIVE, 'stderr', STDERR, 5),
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

/** Values of the equal("<attribute>", [...]) queries in a list request. */
function equalValues(url: URL, attribute: string): string[] | undefined {
  const raws = [...url.searchParams]
    .filter(([key]) => key.startsWith('queries'))
    .map(([, value]) => value)
  for (const raw of raws) {
    const query = JSON.parse(raw) as {
      method: string
      attribute?: string
      values?: unknown[]
    }
    if (query.method === 'equal' && query.attribute === attribute) {
      return (query.values ?? []).map(String)
    }
  }
  return undefined
}

function corsHeaders(route: Route): Record<string, string> {
  return {
    'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': '*',
    'access-control-allow-methods': '*',
  }
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

    if (apiPath === '/logs') {
      const resourceType = url.searchParams.get('resourceType') ?? ''
      const resourceId = url.searchParams.get('resourceId') ?? ''
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
      const logs = logsFor(resourceType, resourceId).filter(
        (row) =>
          deploymentIds.includes(row.deploymentId) &&
          (!streams || streams.includes(row.stream)),
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
    await mockAppwriteApi(page)

    await page.goto(
      `/projects/${PROJECT.$id}/functions/${FUNCTION.$id}/runtime-logs`,
      { waitUntil: 'domcontentloaded' },
    )

    await expectRuntimeLogs(page)

    // Another deployment from the picker
    await page.getByPlaceholder('Search loaded lines...').fill('')
    await page.getByRole('combobox', { name: 'Deployment' }).click()
    await page.getByRole('option').filter({ hasText: OLDER }).click()
    await expect(page.getByRole('log')).toContainText(OTHER_DEPLOYMENT)
    await expect(page).toHaveURL(new RegExp(`deploymentId=${OLDER}`))
  })

  test('a site lists its active deployment runtime logs', async ({ page }) => {
    await mockAppwriteApi(page)

    await page.goto(`/projects/${PROJECT.$id}/sites/${SITE.$id}/runtime-logs`, {
      waitUntil: 'domcontentloaded',
    })

    await expectRuntimeLogs(page)
  })

  test('a deployment page switches from build to runtime logs', async ({
    page,
  }) => {
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
})
