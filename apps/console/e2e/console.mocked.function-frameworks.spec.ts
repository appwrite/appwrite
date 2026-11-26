import type { Locator, Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * The Framework picker for functions (Express, …). The list comes from
 * `GET /v1/functions/frameworks`. Picking one limits the runtimes to the ones it
 * supports, fills in its entrypoint and install command, and sends `framework`
 * on create. The function's Runtime settings change it with a full update.
 */

const NOW = '2026-11-26T09:30:00.000+00:00'

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
  prefs: {},
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT_ID = 'proj0000000000000000001'

const PROJECT = {
  $id: PROJECT_ID,
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme API',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
} satisfies Partial<Models.Project>

const EXPRESS = {
  key: 'express',
  name: 'Express',
  runtimes: ['node-22'],
  entrypoint: 'server.js',
  installCommand: 'npm install',
}

function runtime(id: string, key: string, name: string, version: string) {
  return {
    $id: id,
    key,
    name,
    version,
    base: `openruntimes/${key}:${version}`,
    image: `openruntimes/${key}:${version}`,
    logo: `${key}.png`,
    supports: ['amd64'],
  }
}

const RUNTIMES = [
  runtime('node-16.0', 'node', 'Node.js', '16.0'),
  runtime('node-22', 'node', 'Node.js', '22'),
  runtime('python-3.12', 'python', 'Python', '3.12'),
]

const SPECIFICATION = {
  memory: 512,
  cpus: 0.5,
  enabled: true,
  slug: 's-0.5vcpu-512mb',
}

const FUNCTION_ID = 'func0000000000000000001'

function func(overrides: Record<string, unknown> = {}) {
  return {
    $id: FUNCTION_ID,
    $createdAt: NOW,
    $updatedAt: NOW,
    execute: ['any'],
    name: 'Orders API',
    enabled: true,
    live: true,
    logging: true,
    runtime: 'node-22',
    deploymentId: '',
    deploymentCreatedAt: '',
    latestDeploymentId: '',
    latestDeploymentCreatedAt: '',
    latestDeploymentStatus: '',
    scopes: [],
    vars: [],
    events: [],
    schedule: '',
    timeout: 15,
    entrypoint: 'server.js',
    commands: 'npm install',
    version: 'v5',
    installationId: 'inst0000000000000000001',
    providerRepositoryId: 'repo0000000000000000001',
    providerBranch: 'main',
    providerRootDirectory: 'api',
    providerSilentMode: false,
    buildSpecification: SPECIFICATION.slug,
    runtimeSpecification: SPECIFICATION.slug,
    framework: '',
    ...overrides,
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
 * served live. Records the function create and update requests.
 */
async function mockAppwriteApi(
  page: Page,
  options: { frameworks?: unknown[]; func?: Record<string, unknown> } = {},
) {
  const frameworks = options.frameworks ?? [EXPRESS]
  const calls = {
    creates: [] as Request[],
    updates: [] as Request[],
    rules: [] as Request[],
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

    if (apiPath === '/functions/frameworks')
      return json(200, { total: frameworks.length, frameworks })
    if (apiPath === '/functions/runtimes')
      return json(200, { total: RUNTIMES.length, runtimes: RUNTIMES })
    if (apiPath === '/functions/templates')
      return json(200, { total: 0, templates: [] })
    if (apiPath === '/vcs/installations')
      return json(200, { total: 0, installations: [] })
    if (apiPath === `/teams/${ORGANIZATION.$id}/memberships`)
      return json(200, { total: 0, memberships: [] })
    if (
      apiPath === '/project/variables' ||
      apiPath === `/functions/${FUNCTION_ID}/variables`
    )
      return json(200, { total: 0, variables: [] })
    if (apiPath === '/vcs/installations/inst0000000000000000001')
      return json(200, {
        $id: 'inst0000000000000000001',
        $createdAt: NOW,
        $updatedAt: NOW,
        provider: 'github',
        organization: 'acme',
        providerInstallationId: '1',
      })
    if (apiPath === '/functions/specifications')
      return json(200, { total: 1, specifications: [SPECIFICATION] })
    if (apiPath === '/functions' && request.method() === 'POST') {
      calls.creates.push(request)
      return json(201, func(request.postDataJSON()))
    }
    if (apiPath === `/functions/${FUNCTION_ID}` && request.method() === 'PUT') {
      calls.updates.push(request)
      return json(200, func({ ...options.func, ...request.postDataJSON() }))
    }
    if (apiPath === `/functions/${FUNCTION_ID}`)
      return json(200, func(options.func))
    if (apiPath === `/functions/${FUNCTION_ID}/deployments`)
      return json(200, { total: 0, deployments: [] })
    if (
      apiPath ===
      '/vcs/github/installations/inst0000000000000000001/providerRepositories/repo0000000000000000001'
    )
      return json(200, {
        id: 'repo0000000000000000001',
        name: 'orders-api',
        organization: 'acme',
        provider: 'github',
        private: false,
        defaultBranch: 'main',
        providerInstallationId: 'inst0000000000000000001',
        authorized: true,
        pushedAt: NOW,
        runtime: 'node-22',
      })
    // Stop the create flow after the function exists: the domain, upload and
    // deploy steps are not what this spec asserts.
    if (apiPath === '/proxy/rules/function') {
      calls.rules.push(request)
      return json(400, {
        message: 'Stopped by the test.',
        code: 400,
        type: 'general_argument_invalid',
        version: '1.0',
      })
    }
    if (apiPath === '/console/variables')
      return json(200, { _APP_DOMAIN_FUNCTIONS: 'functions.localhost' })

    if (apiPath === '/account') return json(200, ACCOUNT)
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
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, {
        roles: ['owner'],
        scopes: ['projects.read', 'functions.read', 'functions.write'],
      })
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

/** Pins the console profile. */
async function useProfile(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: {} }),
    )
  })
}

async function openManualCreate(page: Page) {
  await page.goto(`/projects/${PROJECT_ID}/functions/create/manual`, {
    waitUntil: 'domcontentloaded',
  })
  await expect(page.locator('#function-name')).toBeVisible({ timeout: 30_000 })
}

function frameworkSelect(page: Page) {
  return page.getByRole('combobox', { name: 'Framework' })
}

/** Opens a select until `option` shows: the page can re-render while loading. */
async function openSelect(trigger: Locator, option: Locator) {
  await expect(async () => {
    if (!(await option.isVisible())) await trigger.click()
    await expect(option).toBeVisible({ timeout: 2_000 })
  }).toPass({ timeout: 30_000 })
}

async function choose(trigger: Locator, option: Locator) {
  await expect(async () => {
    await openSelect(trigger, option)
    await option.click({ timeout: 2_000 })
  }).toPass({ timeout: 30_000 })
}

async function pickFramework(page: Page, name: string) {
  await choose(
    frameworkSelect(page),
    page.getByRole('option', { name, exact: true }),
  )
  await expect(frameworkSelect(page)).toHaveText(name)
}

function runtimeSelect(page: Page) {
  return page
    .getByRole('combobox')
    .filter({ hasText: /Node\.js|Python|Select runtime/ })
    .first()
}

async function fillAndDeploy(page: Page) {
  await page.locator('#function-name').fill('Orders API')
  await page.locator('#domain').fill('orders-api')
  await page.locator('input[type="file"][accept*=".tar.gz"]').setInputFiles({
    name: 'code.tar.gz',
    mimeType: 'application/gzip',
    buffer: Buffer.from('not really gzip'),
  })
  const deploy = page.getByRole('button', { name: 'Create and deploy' })
  await expect(deploy).toBeEnabled({ timeout: 15_000 })
  await deploy.click()
}

test.describe('function framework picker (mocked API)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await useProfile(page)
  })

  test('lists the frameworks and fills in the defaults of the one picked', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    await openManualCreate(page)

    await expect(frameworkSelect(page)).toHaveText('None (Appwrite function)')
    await openSelect(
      frameworkSelect(page),
      page.getByRole('option', { name: 'Express', exact: true }),
    )
    await expect(page.getByRole('option')).toHaveText([
      'None (Appwrite function)',
      'Express',
    ])
    await pickFramework(page, 'Express')

    await expect(
      page.getByText(/Deploy your existing Express app/),
    ).toBeVisible()
    await expect(page.locator('#entrypoint')).toHaveValue('server.js')
    await expect(page.locator('#commands')).toHaveValue('npm install')
  })

  test('keeps only the runtimes the framework supports', async ({ page }) => {
    await mockAppwriteApi(page)
    await openManualCreate(page)
    await pickFramework(page, 'Express')

    // The only Node.js 18+ runtime is picked for you, and is the only choice.
    await expect(runtimeSelect(page)).toContainText('Node.js 22')
    await openSelect(
      runtimeSelect(page),
      page.getByRole('option', { name: /Node\.js 22/ }),
    )
    await expect(page.getByRole('option')).toHaveCount(1)
  })

  test('keeps an entrypoint the owner already typed', async ({ page }) => {
    await mockAppwriteApi(page)
    await openManualCreate(page)

    await page.locator('#entrypoint').fill('src/app.js')
    await pickFramework(page, 'Express')

    await expect(page.locator('#entrypoint')).toHaveValue('src/app.js')
    await expect(page.locator('#commands')).toHaveValue('npm install')
  })

  test('sends the framework on create', async ({ page }) => {
    const calls = await mockAppwriteApi(page)
    await openManualCreate(page)
    await pickFramework(page, 'Express')

    await fillAndDeploy(page)

    await expect.poll(() => calls.creates.length).toBe(1)
    expect(calls.creates[0].postDataJSON()).toMatchObject({
      name: 'Orders API',
      runtime: 'node-22',
      framework: 'express',
      entrypoint: 'server.js',
      commands: 'npm install',
    })
  })

  test('creates a classic function without a framework', async ({ page }) => {
    const calls = await mockAppwriteApi(page)
    await openManualCreate(page)

    await choose(
      runtimeSelect(page),
      page.getByRole('option', { name: /Node\.js 22/ }),
    )
    await page.locator('#entrypoint').fill('src/main.js')

    await fillAndDeploy(page)

    await expect.poll(() => calls.creates.length).toBe(1)
    const body = calls.creates[0].postDataJSON()
    expect(body.framework ?? '').toBe('')
  })

  test('hides the picker when the server offers no framework', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { frameworks: [] })
    await openManualCreate(page)

    await expect(page.getByText('Runtime', { exact: true })).toBeVisible()
    await expect(frameworkSelect(page)).toHaveCount(0)
  })
})

test.describe('function framework settings (mocked API)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await useProfile(page)
  })

  async function openRuntimeSettings(page: Page) {
    await page.goto(
      `/projects/${PROJECT_ID}/functions/${FUNCTION_ID}/settings/runtime`,
      { waitUntil: 'domcontentloaded' },
    )
  }

  test('updates the framework and keeps the Git connection', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page)
    await openRuntimeSettings(page)

    await expect(frameworkSelect(page)).toBeVisible({ timeout: 30_000 })
    await pickFramework(page, 'Express')
    const updateButton = page.getByRole('button', {
      name: 'Update',
      exact: true,
    })
    await page
      .locator('div')
      .filter({
        has: page.getByRole('heading', { name: 'Framework', exact: true }),
      })
      .filter({ has: updateButton })
      .last()
      .getByRole('button', { name: 'Update', exact: true })
      .click()

    await expect.poll(() => calls.updates.length).toBe(1)
    expect(calls.updates[0].postDataJSON()).toMatchObject({
      name: 'Orders API',
      runtime: 'node-22',
      framework: 'express',
      installationId: 'inst0000000000000000001',
      providerBranch: 'main',
      providerRootDirectory: 'api',
    })
    await expect(
      page.getByText('Framework updated. Redeploy to apply it.'),
    ).toBeVisible()
  })

  test('says no framework runs on a runtime without one', async ({ page }) => {
    await mockAppwriteApi(page, { func: { runtime: 'python-3.12' } })
    await openRuntimeSettings(page)

    await expect(
      page.getByText('No framework runs on this runtime yet.'),
    ).toBeVisible({ timeout: 30_000 })
    await expect(frameworkSelect(page)).toHaveCount(0)
  })
})
