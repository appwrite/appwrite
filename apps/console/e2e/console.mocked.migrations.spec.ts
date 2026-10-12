import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Import wizard: what the resource checkboxes actually ask the server for.
 *
 * A migration transfers exactly the resources named in the request and skips
 * the rest without raising anything, so an omission here is invisible — the
 * console reported a completed migration having moved none of it. The wizard
 * once rendered a teams checkbox and a whole functions group that never
 * reached the request at all, which is the regression these specs cover.
 *
 * The API is mocked at the network layer so the real page runs, and the
 * assertion is made on the request body the page sends.
 */

const NOW = '2026-09-10T09:30:00.000+00:00'

const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'migrations.write',
] satisfies Models.Roles['scopes']

const ALL_SERVICES = [
  'account',
  'databases',
  'tablesdb',
  'locale',
  'health',
  'project',
  'storage',
  'teams',
  'users',
  'sites',
  'functions',
  'migrations',
  'messaging',
] as const

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
  region: 'default',
  status: 'active',
  services: ALL_SERVICES.map(($id) => ({
    $id,
    enabled: true,
  })) as Models.Project['services'],
} satisfies Partial<Models.Project>

/** Counts the wizard shows next to each group; the values are not asserted. */
const REPORT = {
  user: 3,
  team: 2,
  membership: 3,
  database: 1,
  row: 4,
  file: 0,
  bucket: 0,
  function: 1,
  provider: 1,
  topic: 2,
  subscriber: 2,
  message: 2,
  size: 0,
  version: '1.9.6',
}

function corsHeaders(route: Route): Record<string, string> {
  return {
    'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': '*',
    'access-control-allow-methods': '*',
  }
}

/** Captures the body of the migration request the page sends. */
type Captured = { resources?: string[] }

async function mockAppwriteApi(page: Page, captured: Captured) {
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

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') return json(200, {})
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/health/version') return json(200, { version: '2.0.0' })
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
      apiPath === `/projects/${PROJECT.$id}` ||
      apiPath === `/projects/${PROJECT.$id}/console-access`
    )
      return json(200, PROJECT)
    if (apiPath === '/migrations/appwrite/report') return json(200, REPORT)
    if (apiPath === '/migrations/appwrite') {
      captured.resources = request.postDataJSON()?.resources
      return json(202, {
        $id: 'mig00000000000000000001',
        $createdAt: NOW,
        $updatedAt: NOW,
        status: 'pending',
        stage: 'init',
        source: 'Appwrite',
        destination: 'Appwrite',
        resources: captured.resources ?? [],
        statusCounters: '{}',
        resourceData: '{}',
        errors: [],
      })
    }
    if (apiPath === '/migrations')
      return json(200, { total: 0, migrations: [] })

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })
}

/** Walks provider choice and credentials so the specs start at the checkboxes. */
async function openResourceStep(page: Page) {
  await page.goto(`/projects/${PROJECT.$id}/settings/migrations/import`, {
    waitUntil: 'domcontentloaded',
  })

  await page
    .getByRole('button', { name: 'Appwrite (self-hosted)' })
    .click({ timeout: 30_000 })

  await page.getByLabel('Endpoint').fill('https://source.example.com/v1')
  await page.getByLabel('Project ID').fill('sourceproject')
  await page.getByLabel('API key').fill('secret-key') // pragma: allowlist secret

  await page.getByRole('button', { name: 'Continue' }).click()

  await expect(page.getByText('Users', { exact: true })).toBeVisible({
    timeout: 30_000,
  })
}

test.describe('import wizard resource selection', () => {
  test('offers a messaging group for an Appwrite source', async ({ page }) => {
    const captured: Captured = {}
    await mockAppwriteApi(page, captured)
    await openResourceStep(page)

    await expect(
      page.getByRole('heading', { name: /^Messaging/ }),
    ).toBeVisible()
  })

  test('asks for every resource the checkboxes offer', async ({ page }) => {
    const captured: Captured = {}
    await mockAppwriteApi(page, captured)
    await openResourceStep(page)

    await page.getByRole('button', { name: 'Select all', exact: true }).click()
    await page.getByRole('button', { name: 'Start migration' }).click()

    await expect.poll(() => captured.resources).toBeDefined()

    expect(captured.resources).toEqual(
      expect.arrayContaining([
        'user',
        'team',
        'membership',
        'function',
        'environment-variable',
        'deployment',
        'provider',
        'topic',
        'subscriber',
        'message',
      ]),
    )
  })
})
