import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * The "Enable analytics" toggle on the site create form, behind the
 * `analytics` console flag. `sites.create` takes no analytics param, so the
 * console enables it with a follow-up `PATCH /v1/sites/:siteId/analytics`.
 */

const NOW = '2026-11-02T09:30:00.000+00:00'

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

function template(): Models.TemplateSite {
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
    scopes: [],
  }
}

const SITE_ID = 'site0000000000000000001'

const PROPERTY_LIMIT_MESSAGE =
  'Your plan allows 1 analytics property per project. Upgrade to add more.'

function site(analyticsEnabled: boolean) {
  return {
    $id: SITE_ID,
    $createdAt: NOW,
    $updatedAt: NOW,
    name: 'Acme site',
    scopes: [],
    analyticsEnabled,
  }
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

/**
 * Every `/v1` call, on any region host, is answered here; the app itself is
 * served live. Records the site create, analytics update and domain requests.
 */
async function mockAppwriteApi(
  page: Page,
  analyticsStatus = 200,
  planPropertyLimit = 0,
  countGate?: Promise<void>,
) {
  const calls = {
    creates: [] as Request[],
    analytics: [] as Request[],
    domains: [] as Request[],
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

    if (apiPath === '/sites' && request.method() === 'POST') {
      calls.creates.push(request)
      return json(201, site(false))
    }
    if (apiPath === `/sites/${SITE_ID}/analytics`) {
      calls.analytics.push(request)
      return analyticsStatus === 200
        ? json(200, site(true))
        : json(analyticsStatus, {
            message: PROPERTY_LIMIT_MESSAGE,
            code: analyticsStatus,
            type: 'analytics_property_limit_exceeded',
            version: '1.0',
          })
    }
    // The project already holds one property.
    if (apiPath === '/analytics/properties') {
      await countGate
      return json(200, { total: 1, properties: [] })
    }
    if (apiPath === `/organizations/${ORGANIZATION.$id}/plan`) {
      return json(200, {
        $id: ORGANIZATION.billingPlan,
        name: 'Free',
        analyticsProperties: planPropertyLimit,
      })
    }
    if (apiPath === `/sites/templates/${TEMPLATE_KEY}`)
      return json(200, template())
    if (apiPath === '/sites/templates')
      return json(200, { total: 1, templates: [template()] })
    if (apiPath === '/sites/frameworks')
      return json(200, { total: 0, frameworks: [] })
    if (apiPath === '/vcs/installations')
      return json(200, { total: 0, installations: [] })
    // Stop the flow after the site is created: the steps after it are not
    // what this spec asserts, and failing them sends the console to the site.
    if (apiPath === '/proxy/rules/site') {
      calls.domains.push(request)
      return json(400, {
        message: 'Stopped by the test.',
        code: 400,
        type: 'general_argument_invalid',
        version: '1.0',
      })
    }

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

  return calls
}

/** Pins the console profile with the analytics flag on. */
async function useProfile(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: { analytics: true } }),
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

function analyticsSwitch(page: Page) {
  return page.getByRole('switch', { name: 'Enable analytics' })
}

test.describe('site create analytics toggle (mocked API)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await useProfile(page)
  })

  test('is on by default and enables analytics after the create', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page)
    await openTemplate(page)

    await expect(analyticsSwitch(page)).toBeChecked()

    await page.getByRole('button', { name: 'Deploy', exact: true }).click()
    await expect.poll(() => calls.analytics.length).toBe(1)
    expect(calls.creates).toHaveLength(1)
    expect(calls.creates[0].postDataJSON()).not.toHaveProperty('analytics')
    expect(calls.analytics[0].method()).toBe('PATCH')
    expect(calls.analytics[0].postDataJSON()).toEqual({ enabled: true })
  })

  test('skips the analytics call when switched off', async ({ page }) => {
    const calls = await mockAppwriteApi(page)
    await openTemplate(page)

    await analyticsSwitch(page).click()
    await expect(analyticsSwitch(page)).not.toBeChecked()

    await page.getByRole('button', { name: 'Deploy', exact: true }).click()
    await expect.poll(() => calls.domains.length).toBe(1)
    expect(calls.creates).toHaveLength(1)
    expect(calls.analytics).toHaveLength(0)
  })

  test('is off and locked at the plan property limit', async ({ page }) => {
    const calls = await mockAppwriteApi(page, 200, 1)
    await openTemplate(page)

    await expect(analyticsSwitch(page)).not.toBeChecked()
    await expect(analyticsSwitch(page)).toBeDisabled()
    await expect(
      page.getByText(
        'Your current plan includes up to 1 analytics property per project.',
      ),
    ).toBeVisible()

    await page.getByRole('button', { name: 'Deploy', exact: true }).click()
    await expect.poll(() => calls.domains.length).toBe(1)
    expect(calls.creates).toHaveLength(1)
    expect(calls.analytics).toHaveLength(0)
  })

  test('waits for the plan limit before analytics can be sent', async ({
    page,
  }) => {
    let releaseCount = () => {}
    const countGate = new Promise<void>((resolve) => {
      releaseCount = resolve
    })
    const calls = await mockAppwriteApi(page, 200, 5, countGate)
    await openTemplate(page)

    await expect(analyticsSwitch(page)).toBeDisabled()
    await expect(analyticsSwitch(page)).not.toBeChecked()
    releaseCount()
    await expect(analyticsSwitch(page)).toBeEnabled()
    await expect(analyticsSwitch(page)).toBeChecked()

    await page.getByRole('button', { name: 'Deploy', exact: true }).click()
    await expect.poll(() => calls.analytics.length).toBe(1)
  })

  test('still creates the site when enabling analytics is refused', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page, 402)
    await openTemplate(page)

    await page.getByRole('button', { name: 'Deploy', exact: true }).click()
    await expect(page.getByText(PROPERTY_LIMIT_MESSAGE)).toBeVisible()
    expect(calls.creates).toHaveLength(1)
    expect(calls.analytics).toHaveLength(1)
    // The flow carried on past the refusal to the domain step.
    await expect.poll(() => calls.domains.length).toBe(1)
  })
})
