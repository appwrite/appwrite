import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Working inside a project environment.
 *
 * An environment has no project ID of its own: it is the project's ID plus an
 * environment ID, sent as `X-Appwrite-Environment`. Inside one, `GET /project`
 * answers as the environment, under the project's ID and with the
 * environment's name. Everything here is a place where the Console took that
 * answer for the project: the header named the environment twice and the
 * project not at all, the settings page offered to transfer and delete "the
 * project", and saving the name renamed production.
 *
 * The API is mocked at the network layer so the real pages run. What the page
 * asks the server to do is asserted on the requests it sends.
 */

const NOW = '2026-11-10T09:30:00.000+00:00'

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
} satisfies Partial<Models.Organization<Models.Preferences>>

const SERVICES = [
  'account',
  'databases',
  'tablesdb',
  'storage',
  'teams',
  'users',
  'sites',
  'functions',
  'migrations',
  'messaging',
].map(($id) => ({ $id, enabled: true })) as Models.Project['services']

const PROJECT = {
  $id: 'storefront',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Storefront',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
  services: SERVICES,
} satisfies Partial<Models.Project>

const ENVIRONMENT = {
  $id: 'staging',
  $createdAt: NOW,
  $updatedAt: NOW,
  projectId: PROJECT.$id,
  name: 'Staging',
}

/** The project as it answers inside the environment: its ID, the environment's name. */
const PROJECT_AS_ENVIRONMENT = {
  ...PROJECT,
  name: ENVIRONMENT.name,
  parentId: PROJECT.$id,
  environmentId: ENVIRONMENT.$id,
}

const MIGRATION = {
  $id: 'mig00000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  status: 'failed',
  stage: 'finished',
  source: 'Appwrite',
  destination: 'Appwrite',
  resources: ['bucket', 'function'],
  statusCounters: JSON.stringify({
    bucket: {
      pending: 0,
      success: 0,
      error: 1,
      skip: 0,
      processing: 0,
      warning: 0,
    },
    function: {
      pending: 0,
      success: 1,
      error: 0,
      skip: 0,
      processing: 0,
      warning: 0,
    },
  }),
  resourceData: '{}',
  errors: [],
}

/** What the page asked the server to change, and where each request acted. */
interface Sent {
  /** PATCH /project/environments/:id — the name sent, and the environment header it carried */
  environmentRenames: Array<{ id: string; name: string; inside: string | null }>
  /** DELETE /project/environments/:id */
  environmentDeletes: Array<{ id: string; inside: string | null }>
  /** PATCH /organization/projects/:id, which renames the project itself */
  projectRenames: string[]
  /** DELETE /project, which deletes whatever the request acts in */
  projectDeletes: Array<string | null>
  /** POST /project/environments */
  environmentCreates: Array<{ environmentId: string; name: string }>
}

interface Mock {
  sent: Sent
  /** Status the migrations list answers with; flipped by a spec to recover */
  migrations: { status: number }
  /** The organization's plan: Free and Start offer no environments, Pro and above 3. Null fails the request. */
  plan: { $id: string; name: string; environments: number } | null
  /** The project's environments, as its list answers */
  environments: Array<typeof ENVIRONMENT>
}

const PRO = { $id: 'tier-1', name: 'Pro', environments: 3 }
const FREE = { $id: 'tier-0', name: 'Free', environments: 0 }

function corsHeaders(route: Route): Record<string, string> {
  return {
    'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': '*',
    'access-control-allow-methods': '*',
  }
}

async function mockAppwriteApi(page: Page): Promise<Mock> {
  const mock: Mock = {
    sent: {
      environmentRenames: [],
      environmentDeletes: [],
      projectRenames: [],
      projectDeletes: [],
      environmentCreates: [],
    },
    migrations: { status: 200 },
    plan: PRO,
    environments: [ENVIRONMENT],
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
    const method = request.method()
    const inside = request.headers()['x-appwrite-environment'] ?? null

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') {
      return method === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, {})
    }
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/health/version') return json(200, { version: '2.0.0' })
    if (apiPath === '/console/variables') return json(200, {})
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, {
        roles: ['owner'],
        scopes: [
          'projects.read',
          'projects.write',
          'project.read',
          'project.write',
          'migrations.read',
          'migrations.write',
        ],
      })
    if (apiPath === '/organizations' || apiPath === '/teams')
      return json(200, { total: 1, teams: [ORGANIZATION] })
    if (
      apiPath === `/organizations/${ORGANIZATION.$id}` ||
      apiPath === `/teams/${ORGANIZATION.$id}`
    )
      return json(200, {
        ...ORGANIZATION,
        billingPlan: mock.plan?.$id ?? PRO.$id,
      })
    if (apiPath === `/organizations/${ORGANIZATION.$id}/plan`) {
      return mock.plan
        ? json(200, mock.plan)
        : json(500, {
            message: 'Server Error',
            code: 500,
            type: 'general_unknown',
            version: '1.0',
          })
    }
    if (apiPath.endsWith('/memberships'))
      return json(200, { total: 0, memberships: [] })

    // Environments are never listed beside projects
    if (apiPath === '/projects' || apiPath === '/organization/projects')
      return json(200, { total: 1, projects: [PROJECT] })
    if (apiPath === `/organization/projects/${PROJECT.$id}`) {
      if (method === 'PATCH') {
        mock.sent.projectRenames.push(request.postDataJSON()?.name)
      }
      return json(200, PROJECT)
    }

    if (apiPath === '/project') {
      if (method === 'DELETE') {
        mock.sent.projectDeletes.push(inside)
        return route.fulfill({ status: 204, headers })
      }
      // Unknown environments answer 404, as the server does
      if (inside && inside !== ENVIRONMENT.$id) {
        return json(404, {
          message: 'Environment not found',
          code: 404,
          type: 'project_environment_not_found',
          version: '1.0',
        })
      }
      return json(200, inside ? PROJECT_AS_ENVIRONMENT : PROJECT)
    }
    if (apiPath === '/project/environments') {
      if (method === 'POST') {
        const { environmentId, name } = request.postDataJSON()
        mock.sent.environmentCreates.push({ environmentId, name })
        return json(201, { ...ENVIRONMENT, $id: environmentId, name })
      }
      return json(200, {
        total: mock.environments.length,
        environments: mock.environments,
      })
    }
    if (apiPath === `/project/environments/${ENVIRONMENT.$id}`) {
      if (method === 'PATCH') {
        const name = request.postDataJSON()?.name
        mock.sent.environmentRenames.push({ id: ENVIRONMENT.$id, name, inside })
        return json(200, { ...ENVIRONMENT, name })
      }
      if (method === 'DELETE') {
        mock.sent.environmentDeletes.push({ id: ENVIRONMENT.$id, inside })
        return route.fulfill({ status: 204, headers })
      }
      return json(200, ENVIRONMENT)
    }
    if (apiPath === '/project/prefs') return json(200, {})
    if (apiPath === `/projects/${PROJECT.$id}/console-access`)
      return json(200, PROJECT)

    if (apiPath === '/migrations') {
      if (mock.migrations.status !== 200) {
        return json(mock.migrations.status, {
          message: 'Server Error',
          code: mock.migrations.status,
          type: 'general_unknown',
          version: '1.0',
        })
      }
      // The copy is a migration of the environment: the project itself has none
      return json(
        200,
        inside
          ? { total: 1, migrations: [MIGRATION] }
          : { total: 0, migrations: [] },
      )
    }

    // Lists the pages around these ones read; empty is enough for them
    if (
      method === 'GET' &&
      /^\/(project\/(keys|platforms|variables|webhooks)|vcs\/installations|proxy\/rules)$/.test(
        apiPath,
      )
    ) {
      const key = apiPath.split('/').pop() as string
      return json(200, { total: 0, [key]: [] })
    }

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return mock
}

/** Open the Console already inside the environment, as a reload of a tab that was in it does. */
async function enterEnvironment(page: Page) {
  await page.addInitScript(
    ([projectId, environmentId]) => {
      try {
        window.sessionStorage.setItem(
          `console:environment:${projectId}`,
          environmentId,
        )
      } catch {
        /* storage disabled */
      }
    },
    [PROJECT.$id, ENVIRONMENT.$id],
  )
}

/**
 * Turn the environments experiment on, as the quality team's experiments
 * toggle does: the sidebar switcher and the create page sit behind it.
 */
async function enableEnvironmentsExperiment(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: { projectEnvironments: true } }),
    )
  })
}

async function openSettings(page: Page) {
  await page.goto(`/projects/${PROJECT.$id}/settings`, {
    waitUntil: 'domcontentloaded',
  })
  await expect(
    page.getByRole('heading', { name: 'API credentials' }),
  ).toBeVisible({
    timeout: 45_000,
  })
}

test.describe('inside a project environment', () => {
  test('the header names the organization, the project and then the environment', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    await enterEnvironment(page)
    await openSettings(page)

    const switcher = page.locator('[data-analytics="project-switcher"]').first()
    await expect(switcher).toContainText('Acme / Storefront / Staging')
    // The environment's name once, after the project's: not in the project's place
    await expect(switcher).not.toContainText('Staging / Staging')

    // The list is of projects, and the open one is the project the environment belongs to
    await switcher.click()
    const current = page.getByRole('link', { name: /Storefront/ }).first()
    await expect(current).toBeVisible()
    await expect(current).toContainText('Current')
  })

  test("settings are the environment's: no transfer, and delete names the environment", async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    await enterEnvironment(page)
    await openSettings(page)

    await expect(
      page.getByRole('heading', { name: 'Delete environment' }),
    ).toBeVisible()
    await expect(
      page.getByRole('heading', { name: 'Delete project' }),
    ).toHaveCount(0)
    await expect(
      page.getByRole('heading', { name: 'Transfer project' }),
    ).toHaveCount(0)

    // The card says what stays: the project the environment belongs to
    const card = page.locator('[data-card-id="delete-environment"]')
    await expect(card).toContainText('Staging')
    await expect(card).toContainText('Environment')
    await expect(card).toContainText('Storefront')

    // The environment ID is what tells its requests apart from production's
    const credentials = page.locator('[data-card-id="api-credentials"]')
    await expect(
      credentials.getByText('Environment ID', { exact: true }),
    ).toBeVisible()
    await expect(credentials.locator('input[value="staging"]')).toBeVisible()
  })

  test('saving the name renames the environment, not the project', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    await enterEnvironment(page)
    await openSettings(page)

    const name = page.locator('#name')
    await expect(name).toHaveValue('Staging')
    await name.fill('Stage')
    await page
      .locator('[data-card-id="project-name"]')
      .getByRole('button', { name: 'Update' })
      .click()

    await expect.poll(() => mock.sent.environmentRenames).toHaveLength(1)
    // Named on the project's environments route, outside the environment
    expect(mock.sent.environmentRenames[0]).toEqual({
      id: 'staging',
      name: 'Stage',
      inside: null,
    })
    // The organization's project route would have renamed production
    expect(mock.sent.projectRenames).toEqual([])
  })

  test('deleting removes the environment alone and returns to production', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    await enterEnvironment(page)
    await openSettings(page)

    await page
      .locator('[data-card-id="delete-environment"]')
      .getByRole('button', { name: 'Delete environment' })
      .click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toContainText('Delete environment')
    await dialog.getByPlaceholder('Enter environment name').fill('Staging')
    await dialog.getByRole('button', { name: 'Delete' }).click()

    await expect.poll(() => mock.sent.environmentDeletes).toHaveLength(1)
    expect(mock.sent.environmentDeletes[0]).toEqual({
      id: 'staging',
      inside: null,
    })
    // The project's own delete route takes the project and every environment with it
    expect(mock.sent.projectDeletes).toEqual([])

    // Back in production: the environment is left, and the header says so
    await expect
      .poll(() =>
        page.evaluate(
          (projectId) =>
            window.sessionStorage.getItem(`console:environment:${projectId}`),
          PROJECT.$id,
        ),
      )
      .toBeNull()
    await expect(
      page.locator('[data-analytics="project-switcher"]').first(),
    ).toContainText('Acme / Storefront')
  })

  test('a migrations list that cannot be read is not shown as empty', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    mock.migrations.status = 500
    await enterEnvironment(page)

    await page.goto(`/projects/${PROJECT.$id}/settings/migrations`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(page.getByText('Failed to load migrations')).toBeVisible({
      timeout: 45_000,
    })
    // The first-run screen says there are no migrations, which is not known
    await expect(page.getByText('Bring your data to Appwrite')).toHaveCount(0)

    // Once the list can be read, the environment's copy is there
    mock.migrations.status = 200
    await page.getByRole('button', { name: 'Retry' }).click()
    await expect(page.getByText(MIGRATION.$id)).toBeVisible()
    await expect(page.getByText('Failed to load migrations')).toHaveCount(0)
  })
})

test.describe('the project itself', () => {
  test("keeps its own settings: transfer and delete are the project's", async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    await openSettings(page)

    await expect(
      page.locator('[data-analytics="project-switcher"]').first(),
    ).toContainText('Acme / Storefront')
    await expect(
      page.getByRole('heading', { name: 'Delete project' }),
    ).toBeVisible()
    await expect(
      page.getByRole('heading', { name: 'Transfer project' }),
    ).toBeVisible()
    await expect(
      page.getByRole('heading', { name: 'Delete environment' }),
    ).toHaveCount(0)
    await expect(
      page
        .locator('[data-card-id="api-credentials"]')
        .getByText('Environment ID', { exact: true }),
    ).toHaveCount(0)

    // Saving the name is the project's rename
    await page.locator('#name').fill('Storefront 2')
    await page
      .locator('[data-card-id="project-name"]')
      .getByRole('button', { name: 'Update' })
      .click()
    await expect.poll(() => mock.sent.projectRenames).toEqual(['Storefront 2'])
    expect(mock.sent.environmentRenames).toEqual([])
  })
})

test.describe('creating an environment on the plan', () => {
  test('on Free the switcher offers environments as an upgrade', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    mock.plan = FREE
    mock.environments = []
    await enableEnvironmentsExperiment(page)
    await openSettings(page)

    // The feature stays in view, marked Beta, with creating marked Pro
    await page.getByRole('button', { name: 'Switch environment' }).click()
    const menu = page.getByRole('menu')
    await expect(menu).toContainText('Beta')
    const create = menu.getByRole('menuitem', { name: /Create environment/ })
    await expect(create).toContainText('Pro')
    await create.click()

    await expect(page).toHaveURL(/\/environments\/create/)
    const notice = page
      .getByRole('alert')
      .filter({ hasText: 'Project environments are available on Pro' })
    await expect(notice).toBeVisible({ timeout: 30_000 })
    await expect(notice).toContainText('Free')
    // The way out is the upgrade of this organization
    await expect(notice.getByRole('link', { name: 'Upgrade' })).toHaveAttribute(
      'href',
      new RegExp(`/upgrade\\?orgId=${ORGANIZATION.$id}`),
    )
    await expect(page.getByText('Beta', { exact: true }).first()).toBeVisible()

    // Shown, not usable: nothing can be sent that the plan refuses
    await expect(page.locator('#environment-name')).toBeDisabled()
    await expect(
      page.getByRole('button', { name: 'Create environment' }),
    ).toBeDisabled()
    expect(mock.sent.environmentCreates).toEqual([])
  })

  test('on Pro at its cap the page says so, with no upgrade to offer', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    mock.environments = ['staging', 'preview', 'qa'].map(($id) => ({
      ...ENVIRONMENT,
      $id,
      name: $id,
    }))
    await enableEnvironmentsExperiment(page)
    await openSettings(page)

    await page.getByRole('button', { name: 'Switch environment' }).click()
    await expect(
      page.getByRole('menuitem', { name: /Create environment/ }),
    ).toContainText('3/3')

    await page.goto(`/projects/${PROJECT.$id}/environments/create`, {
      waitUntil: 'domcontentloaded',
    })
    const notice = page
      .getByRole('alert')
      .filter({ hasText: "You've reached the limit of 3 environments" })
    await expect(notice).toBeVisible({ timeout: 30_000 })
    // Every plan with environments stops at the same cap: an upgrade would not help
    await expect(notice.getByRole('link', { name: 'Upgrade' })).toHaveCount(0)
    await expect(
      page.getByRole('button', { name: 'Create environment' }),
    ).toBeDisabled()
  })

  test('a plan that cannot be read neither upsells nor blocks', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    mock.plan = null
    await enableEnvironmentsExperiment(page)
    await page.goto(`/projects/${PROJECT.$id}/environments/create`, {
      waitUntil: 'domcontentloaded',
    })

    // The failed request says nothing about the plan: the API decides
    const name = page.locator('#environment-name')
    await expect(name).toBeEnabled({ timeout: 45_000 })
    await expect(page.getByRole('alert')).toHaveCount(0)
    await name.fill('Preview')
    await page.getByRole('button', { name: 'Create environment' }).click()

    await expect
      .poll(() => mock.sent.environmentCreates)
      .toEqual([{ environmentId: 'preview', name: 'Preview' }])
  })

  test('on Pro with room an environment is created', async ({ page }) => {
    const mock = await mockAppwriteApi(page)
    await enableEnvironmentsExperiment(page)
    await page.goto(`/projects/${PROJECT.$id}/environments/create`, {
      waitUntil: 'domcontentloaded',
    })

    const name = page.locator('#environment-name')
    await expect(name).toBeEnabled({ timeout: 45_000 })
    await name.fill('Preview')
    await expect(page.getByRole('alert')).toHaveCount(0)
    await page.getByRole('button', { name: 'Create environment' }).click()

    await expect
      .poll(() => mock.sent.environmentCreates)
      .toEqual([{ environmentId: 'preview', name: 'Preview' }])
  })
})
