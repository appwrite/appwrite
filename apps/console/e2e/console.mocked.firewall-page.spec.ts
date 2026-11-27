import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Firewall is a project page of its own, opened from the sidebar: the API tab
 * holds the project API's rules, and the Functions and Sites tabs list each
 * resource with a link to its own Firewall tab.
 *
 * The Appwrite API is mocked at the network layer and the real console pages are
 * asserted.
 */

const NOW = '2026-11-25T09:30:00.000+00:00'

const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'functions.read',
  'functions.write',
  'sites.read',
  'sites.write',
  'rules.read',
  'rules.write',
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
  name: 'Acme',
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
  name: 'Acme Store',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
  services: [],
} satisfies Partial<Models.Project>

const FUNCTIONS = [
  ['checkout', 'Checkout', 'node-22'],
  ['webhooks', 'Stripe webhooks', 'python-3.12'],
].map(([$id, name, runtime]) => ({
  $id,
  $createdAt: NOW,
  $updatedAt: NOW,
  name,
  runtime,
  enabled: true,
  live: true,
  execute: [],
  events: [],
  schedule: '',
  timeout: 15,
  deploymentId: '',
  latestDeploymentId: '',
  latestDeploymentStatus: '',
  vars: [],
  scopes: [],
}))

const SITES = [
  ['storefront', 'Storefront', 'nextjs'],
  ['docs', 'Docs', 'astro'],
].map(([$id, name, framework]) => ({
  $id,
  $createdAt: NOW,
  $updatedAt: NOW,
  name,
  framework,
  enabled: true,
  live: true,
  deploymentId: '',
  latestDeploymentId: '',
  latestDeploymentStatus: '',
  vars: [],
}))

const API_RULE = {
  $id: 'rule000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Block admin from outside the office',
  description: '',
  teamId: ORGANIZATION.$id,
  projectId: PROJECT_ID,
  resourceType: 'api',
  resourceId: '',
  action: 'deny',
  priority: 100,
  enabled: true,
  conditions: [{ method: 'startsWith', attribute: 'path', values: ['/admin'] }],
  config: {},
}

/** Rules scoped to each function and site; any other has none. */
const RULE_COUNTS: Record<string, number> = { webhooks: 3, storefront: 1 }

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
async function mockAppwriteApi(page: Page) {
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
    // The SDK sends `queries[0]`, `queries[1]`, …
    const queries = [...url.searchParams]
      .filter(([key]) => key.startsWith('queries['))
      .map(
        ([, query]) =>
          JSON.parse(query) as { attribute?: string; values?: string[] },
      )

    if (apiPath === '/waf/rules') {
      // The API scope has one rule; functions and sites have RULE_COUNTS.
      const resourceId = queries.find(
        ({ attribute }) => attribute === 'resourceId',
      )?.values?.[0]
      const rules = resourceId
        ? Array.from({ length: RULE_COUNTS[resourceId] ?? 0 }, (_, index) => ({
            ...API_RULE,
            $id: `rule${resourceId}${index}`,
            name: `Rule ${index + 1}`,
            resourceType: FUNCTIONS.some(({ $id }) => $id === resourceId)
              ? 'functions'
              : 'sites',
            resourceId,
          }))
        : [API_RULE]
      return json(200, { total: rules.length, rules })
    }
    if (apiPath === '/usage/events' || apiPath === '/usage/gauges')
      return json(200, { interval: '1h', metrics: [] })
    if (apiPath === '/functions')
      return json(200, { total: FUNCTIONS.length, functions: FUNCTIONS })
    if (apiPath === '/sites')
      return json(200, { total: SITES.length, sites: SITES })
    const func = FUNCTIONS.find(({ $id }) => apiPath === `/functions/${$id}`)
    if (func) return json(200, func)
    const site = SITES.find(({ $id }) => apiPath === `/sites/${$id}`)
    if (site) return json(200, site)

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
}

/** Pins the Cloud profile: Firewall is Cloud only. */
async function useCloudProfile(page: Page) {
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

function tab(page: Page, name: string) {
  return page.getByRole('tab', { name, exact: true })
}

function ruleCount(page: Page, name: string) {
  return page
    .getByRole('row', { name: new RegExp(name) })
    .getByTestId('firewall-rule-count')
}

/** Position and size of every cell in the table body. */
function cellBoxes(page: Page) {
  return page.locator('tbody tr').evaluateAll((rows) =>
    rows.map((row) =>
      [...row.children].map((cell) => {
        const { x, y, width, height } = cell.getBoundingClientRect()
        return { x, y, width, height }
      }),
    ),
  )
}

test.describe('firewall page (mocked API)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await useCloudProfile(page)
    await mockAppwriteApi(page)
  })

  test('opens from the sidebar on the API tab', async ({ page }) => {
    await page.goto(`/projects/${PROJECT_ID}/overview`, {
      waitUntil: 'domcontentloaded',
    })
    await page
      .getByRole('navigation')
      .getByRole('link', { name: 'Firewall', exact: true })
      .first()
      .click()

    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/firewall$`),
    )
    await expect(
      page.getByRole('heading', { name: 'Firewall', exact: true }),
    ).toBeVisible({ timeout: 30_000 })
    await expect(tab(page, 'API')).toBeVisible()
    await expect(tab(page, 'Functions')).toBeVisible()
    await expect(tab(page, 'Sites')).toBeVisible()
    await expect(page.getByText(API_RULE.name)).toBeVisible()
    await attachScreenshot(page, 'firewall-api')
  })

  test('lists functions, each opening its Firewall tab', async ({ page }) => {
    await page.goto(`/projects/${PROJECT_ID}/firewall`, {
      waitUntil: 'domcontentloaded',
    })
    await tab(page, 'Functions').click()

    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/firewall/functions$`),
    )
    await expect(page.getByPlaceholder('Search functions...')).toBeVisible()
    await expect(page.getByRole('row')).toHaveCount(FUNCTIONS.length + 1)
    await expect(ruleCount(page, 'Stripe webhooks')).toHaveText('3')
    await expect(ruleCount(page, 'Checkout')).toHaveText('0')
    await attachScreenshot(page, 'firewall-functions')

    await page.getByRole('link', { name: /Checkout/ }).click()
    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/functions/checkout/firewall$`),
    )
  })

  test('lists sites, each opening its Firewall tab', async ({ page }) => {
    await page.goto(`/projects/${PROJECT_ID}/firewall`, {
      waitUntil: 'domcontentloaded',
    })
    await tab(page, 'Sites').click()

    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/firewall/sites$`),
    )
    await expect(page.getByPlaceholder('Search sites...')).toBeVisible()
    await expect(page.getByRole('row')).toHaveCount(SITES.length + 1)
    await expect(ruleCount(page, 'Storefront')).toHaveText('1')
    await expect(ruleCount(page, 'Docs')).toHaveText('0')
    await attachScreenshot(page, 'firewall-sites')

    // The whole row opens the site's Firewall tab, not only its name.
    await page
      .getByRole('row', { name: /Docs/ })
      .getByRole('cell')
      .last()
      .click()
    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/sites/docs/firewall$`),
    )
  })

  test('shows the list before rule counts, without moving it', async ({
    page,
  }) => {
    // Hold every rule count until the list has rendered.
    let release = () => {}
    const counted = new Promise<void>((resolve) => (release = resolve))
    await page.route(
      (url) => url.pathname.endsWith('/v1/waf/rules'),
      async (route) => {
        await counted
        await route.fallback()
      },
    )

    await page.goto(`/projects/${PROJECT_ID}/firewall/functions`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page.getByText('Stripe webhooks')).toBeVisible({
      timeout: 30_000,
    })
    // The boot loader is gone: the counts do not hold the page back.
    await expect(page.locator('[data-fullscreen-loader]')).toHaveCount(0)
    await expect(
      ruleCount(page, 'Stripe webhooks').locator('[data-slot="skeleton"]'),
    ).toBeVisible()
    const pending = await cellBoxes(page)

    release()
    await expect(ruleCount(page, 'Stripe webhooks')).toHaveText('3')
    await expect(ruleCount(page, 'Checkout')).toHaveText('0')
    expect(await cellBoxes(page)).toEqual(pending)
  })

  test('leaves project settings without a Firewall tab', async ({ page }) => {
    await page.goto(`/projects/${PROJECT_ID}/settings`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(tab(page, 'Custom domains')).toBeVisible({ timeout: 30_000 })
    await expect(tab(page, 'Firewall')).toHaveCount(0)
  })

  test('keeps old Firewall links working', async ({ page }) => {
    // API rules were a project settings tab.
    await page.goto(`/projects/${PROJECT_ID}/settings/firewall`, {
      waitUntil: 'domcontentloaded',
    })
    // Each redirect runs once the app has booted.
    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/firewall$`),
      { timeout: 30_000 },
    )
    await expect(page.getByText(API_RULE.name)).toBeVisible()

    // The resource picker named a function or site in the search.
    await page.goto(
      `/projects/${PROJECT_ID}/firewall?resourceType=functions&resourceId=webhooks`,
      { waitUntil: 'domcontentloaded' },
    )
    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/functions/webhooks/firewall$`),
      { timeout: 30_000 },
    )
  })
})
