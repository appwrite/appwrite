import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Deploying a site from a template that declares scopes.
 *
 * No template in the gallery declares scopes yet, so the Appwrite API is mocked
 * at the network layer and the real console pages are asserted.
 */

const NOW = '2026-11-02T09:30:00.000+00:00'

const SCOPES = ['databases.read', 'tables.write', 'rows.write']

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

const PROJECT = {
  $id: PROJECT_ID,
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme Docs',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
} satisfies Partial<Models.Project>

const TEMPLATE_KEY = 'starter-for-react'

const FRAMEWORK = {
  key: 'react',
  name: 'React',
  installCommand: 'npm install',
  buildCommand: 'npm run build',
  outputDirectory: './dist',
  providerRootDirectory: './',
  buildRuntime: 'node-22',
  adapter: 'static',
  fallbackFile: 'index.html',
} satisfies Models.TemplateFramework

function template(scopes: string[]): Models.TemplateSite {
  return {
    key: TEMPLATE_KEY,
    name: 'React starter',
    tagline: 'Simple React application integrated with Appwrite SDK.',
    demoUrl: '',
    screenshotDark: '',
    screenshotLight: '',
    useCases: ['starter'],
    frameworks: [FRAMEWORK],
    vcsProvider: 'github',
    providerRepositoryId: 'starter-for-react',
    providerOwner: 'appwrite',
    providerVersion: '0.1.*',
    variables: [],
    scopes,
  }
}

const SITE_ID = 'site0000000000000000001'

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

/**
 * Every `/v1` call, on any region host, is answered here; the app itself is
 * served live. Returns the `POST /v1/sites` requests.
 */
async function mockAppwriteApi(
  page: Page,
  scopes: string[],
): Promise<Request[]> {
  const creates: Request[] = []
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

    if (apiPath === '/sites' && request.method() === 'POST') {
      creates.push(request)
      return json(201, {
        $id: SITE_ID,
        $createdAt: NOW,
        $updatedAt: NOW,
        name: request.postDataJSON().name,
        scopes: request.postDataJSON().scopes ?? [],
      })
    }
    if (apiPath === `/sites/templates/${TEMPLATE_KEY}`)
      return json(200, template(scopes))
    if (apiPath === '/sites/templates')
      return json(200, { total: 1, templates: [template(scopes)] })
    if (apiPath === '/sites/frameworks')
      return json(200, { total: 0, frameworks: [] })
    if (apiPath === '/vcs/installations')
      return json(200, { total: 0, installations: [] })
    // Stop the flow after the site is created: the steps after it are not
    // what this spec asserts, and failing them sends the console to the site.
    if (apiPath === '/proxy/rules/site')
      return json(400, {
        message: 'Stopped by the test.',
        code: 400,
        type: 'general_argument_invalid',
        version: '1.0',
      })

    if (apiPath === '/account') return json(200, ACCOUNT)
    // GET returns the prefs, PATCH the updated user. Answering PATCH with the
    // prefs alone replaces the cached account and sends it to /verify-email.
    if (apiPath === '/account/prefs') {
      return request.method() === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, ACCOUNT.prefs)
    }
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/account/consents')
      return json(200, { total: 0, consents: [] })
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
    if (apiPath === '/console/plans') return json(200, { total: 0, plans: [] })
    if (apiPath === `/projects/${PROJECT_ID}/stages`)
      return json(200, { total: 0, stages: [] })
    if (apiPath === '/console/variables') return json(200, {})
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, { roles: ['owner'], scopes: ['projects.read'] })
    if (apiPath === '/organizations' || apiPath === '/teams')
      return json(200, { total: 1, teams: [ORGANIZATION] })
    if (
      apiPath === `/organizations/${ORGANIZATION.$id}` ||
      apiPath === `/teams/${ORGANIZATION.$id}`
    )
      return json(200, ORGANIZATION)
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

  return creates
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

async function openTemplate(page: Page) {
  await page.goto(
    `/projects/${PROJECT_ID}/sites/create/templates/${TEMPLATE_KEY}`,
    { waitUntil: 'domcontentloaded' },
  )
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible({ timeout: 30_000 })
}

function scopesHeading(page: Page) {
  return page.getByRole('heading', { name: 'Scopes', exact: true })
}

test.describe('site template scopes (mocked API)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await useProfile(page)
  })

  test("shows the template's scopes and creates the site with them", async ({
    page,
  }) => {
    const creates = await mockAppwriteApi(page, SCOPES)
    await openTemplate(page)

    await expect(scopesHeading(page)).toBeVisible()
    for (const scope of SCOPES) {
      await expect(page.getByText(scope, { exact: true })).toBeVisible()
    }

    await page.getByRole('button', { name: 'Deploy', exact: true }).click()
    await expect.poll(() => creates.length).toBe(1)
    expect(creates[0].postDataJSON().scopes).toEqual(SCOPES)
  })

  test('creates the site without scopes when the template has none', async ({
    page,
  }) => {
    const creates = await mockAppwriteApi(page, [])
    await openTemplate(page)

    await expect(scopesHeading(page)).toHaveCount(0)

    await page.getByRole('button', { name: 'Deploy', exact: true }).click()
    await expect.poll(() => creates.length).toBe(1)
    expect(creates[0].postDataJSON()).not.toHaveProperty('scopes')
  })
})
