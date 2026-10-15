import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Project environment switcher in the project sidebar.
 *
 * The `/project/environments` routes are cloud-only, so there is no backend to
 * run these against: the API is mocked at the network layer and the real
 * sidebar and dialogs run on top of it. An environment is a project of its own,
 * so switching opens it, and every environment call has to go through the
 * parent even while the Console shows an environment. What is asserted is the
 * request the page sends and where it navigates.
 *
 * The surface is behind the `projectEnvironments` profile feature, which is off
 * by default; the specs turn it on through the debug profile override.
 */

const NOW = '2026-10-14T09:30:00.000+00:00'

const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
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
  billingPlan: 'tier-1',
  billingEmail: ACCOUNT.email,
  status: 'active',
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT = {
  $id: 'proj0000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme Production',
  teamId: ORGANIZATION.$id,
  region: 'fra',
  status: 'active',
  services: ALL_SERVICES.map(($id) => ({
    $id,
    enabled: true,
  })) as Models.Project['services'],
} satisfies Partial<Models.Project>

/**
 * An environment is itself a project document, linked to the project by
 * `parentId`, so the list endpoint returns project rows.
 */
function environment(id: string, name: string, createdAt = NOW) {
  return {
    $id: id,
    $createdAt: createdAt,
    $updatedAt: createdAt,
    name,
    teamId: ORGANIZATION.$id,
    region: PROJECT.region,
    status: 'active',
    parentId: PROJECT.$id,
    services: PROJECT.services,
  } satisfies Partial<Models.Project>
}

const STAGING = environment(
  'staging',
  'Staging',
  '2026-10-13T09:30:00.000+00:00',
)
const PREVIEW = environment(
  'preview',
  'Preview',
  '2026-10-12T09:30:00.000+00:00',
)

const SWITCHER = { name: 'Switch environment' } as const

type ApiError = { status: number; body: unknown }

type Scenario = {
  /** Rows GET /project/environments returns. Defaults to staging + preview. */
  environments?: Array<ReturnType<typeof environment>>
  /** Error for GET /project/environments instead of a list (e.g. 404). */
  listError?: ApiError
  /** Error for POST /project/environments. */
  createError?: ApiError
  /** Error for PATCH /project/environments/:id. */
  renameError?: ApiError
  /** `projectEnvironments` profile feature. Omit for the on state most specs need. */
  projectEnvironments?: boolean
}

/** What the page asked the API for, so the assertions can check the request. */
type Recorded = {
  listCalls: number
  /** Decoded query string of each GET, so the ordering and limit are pinned. */
  listQueries: string[]
  /** `X-Appwrite-Project` of every environment call: always the parent. */
  environmentCallProjects: string[]
  /** Decoded query string of each organization project list. */
  projectListQueries: string[]
  created: Array<{ environmentId?: string; name?: string }>
  renamed: Array<{ environmentId: string; name?: string }>
  deleted: string[]
}

function corsHeaders(route: Route): Record<string, string> {
  return {
    'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': '*',
    'access-control-allow-methods': '*',
  }
}

async function mockAppwriteApi(
  page: Page,
  scenario: Scenario,
  recorded: Recorded,
) {
  const { projectEnvironments = true, environments = [STAGING, PREVIEW] } =
    scenario

  await page.addInitScript((enabled) => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({
        id: 'cloud',
        features: { projectEnvironments: enabled },
      }),
    )
  }, projectEnvironments)

  // Rows the list endpoint returns; create and delete keep it in step so the
  // refetch the mutations trigger reflects what the user just did.
  let rows = [...environments]

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

    // ---- project environments -------------------------------------------
    if (apiPath.startsWith('/project/environments')) {
      recorded.environmentCallProjects.push(
        request.headers()['x-appwrite-project'] ?? '',
      )
    }
    if (apiPath === '/project/environments') {
      if (request.method() === 'POST') {
        const payload = request.postDataJSON() ?? {}
        recorded.created.push(payload)
        if (scenario.createError) {
          return json(scenario.createError.status, scenario.createError.body)
        }
        const created = environment(
          String(payload.environmentId),
          String(payload.name),
        )
        rows = [created, ...rows]
        return json(201, created)
      }
      recorded.listCalls += 1
      recorded.listQueries.push(decodeURIComponent(url.search))
      if (scenario.listError) {
        return json(scenario.listError.status, scenario.listError.body)
      }
      return json(200, { total: rows.length, projects: rows })
    }

    const environmentId = apiPath.match(
      /^\/project\/environments\/([^/]+)$/,
    )?.[1]
    if (environmentId) {
      if (request.method() === 'PATCH') {
        const payload = request.postDataJSON() ?? {}
        recorded.renamed.push({ environmentId, name: payload.name })
        if (scenario.renameError) {
          return json(scenario.renameError.status, scenario.renameError.body)
        }
        rows = rows.map((row) =>
          row.$id === environmentId
            ? { ...row, name: String(payload.name) }
            : row,
        )
        return json(
          200,
          rows.find((row) => row.$id === environmentId),
        )
      }
      if (request.method() === 'DELETE') {
        recorded.deleted.push(environmentId)
        rows = rows.filter((row) => row.$id !== environmentId)
        return route.fulfill({ status: 204, headers })
      }
    }

    // ---- everything the project shell needs to render --------------------
    if (apiPath === '/account') return json(200, ACCOUNT)
    // GET returns the prefs, PATCH the updated user.
    if (apiPath === '/account/prefs') {
      return request.method() === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, {})
    }
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
    if (apiPath === '/projects' || apiPath === '/organization/projects') {
      recorded.projectListQueries.push(decodeURIComponent(url.search))
      return json(200, { total: 1, projects: [PROJECT] })
    }
    // The parent and each environment are projects the Console can open.
    const projectId =
      apiPath === '/project'
        ? request.headers()['x-appwrite-project']
        : apiPath.match(/^\/projects\/([^/]+)(?:\/console-access)?$/)?.[1]
    const project = [PROJECT, ...rows].find((row) => row.$id === projectId)
    if (project) return json(200, project)

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })
}

function newRecorded(): Recorded {
  return {
    listCalls: 0,
    listQueries: [],
    environmentCallProjects: [],
    projectListQueries: [],
    created: [],
    renamed: [],
    deleted: [],
  }
}

/** Opens a project page that renders the sidebar (settings has no loader). */
async function openProject(page: Page, projectId: string = PROJECT.$id) {
  await page.goto(`/projects/${projectId}/settings`, {
    waitUntil: 'domcontentloaded',
  })
}

function projectUrl(projectId: string) {
  return new RegExp(`/projects/${projectId}(?:[/?#]|$)`)
}

/**
 * Resolves once the project sidebar itself has rendered. The switcher is one of
 * its children, so this is what the "renders nothing" assertions wait for: a
 * missing switcher has to mean gated off, not still mounting.
 */
async function waitForSidebar(page: Page) {
  await expect(
    page.getByRole('link', { name: 'Overview', exact: true }).first(),
  ).toBeVisible({ timeout: 45_000 })
}

async function openSwitcher(page: Page) {
  const trigger = page.getByRole('button', SWITCHER)
  await expect(trigger).toBeVisible({ timeout: 45_000 })
  await trigger.click()
  return page.getByRole('menu')
}

test.describe('project environment switcher (mocked API)', () => {
  test('lists the project first and its environments under it', async ({
    page,
  }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, {}, recorded)
    await openProject(page)

    // The Console is showing the parent, so the parent is selected.
    const trigger = page.getByRole('button', SWITCHER)
    await expect(trigger).toContainText(PROJECT.name, { timeout: 30_000 })

    const menu = await openSwitcher(page)
    const rows = menu.getByRole('menuitem')
    // The project is never one of the rows the list endpoint returns, so the
    // switcher adds it: project, then the listed environments, then actions.
    await expect(rows.nth(0)).toContainText(PROJECT.name)
    await expect(rows.nth(1)).toContainText(STAGING.name)
    await expect(rows.nth(2)).toContainText(PREVIEW.name)
    await expect(
      menu.getByRole('menuitem', { name: 'Create environment' }),
    ).toBeVisible()

    // The rows above came from a request that also has to carry the ordering
    // and the page size; dropping either leaves the list wrong at scale.
    expect(recorded.listQueries[0]).toContain(
      '{"method":"orderDesc","attribute":"$createdAt"}',
    )
    expect(recorded.listQueries[0]).toContain(
      '{"method":"limit","values":[100]}',
    )

    // The project is managed from its own settings, not from the switcher.
    await expect(
      menu.getByRole('menuitem', { name: 'Rename environment' }),
    ).toHaveCount(0)
    await expect(
      menu.getByRole('menuitem', { name: 'Delete environment' }),
    ).toHaveCount(0)
  })

  test('switching opens the environment, and back opens the parent', async ({
    page,
  }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, {}, recorded)
    await openProject(page)

    const menu = await openSwitcher(page)
    await menu.getByRole('menuitem', { name: STAGING.name }).click()
    await expect(page).toHaveURL(projectUrl(STAGING.$id))
    await expect(page.getByRole('button', SWITCHER)).toContainText(STAGING.name)

    const reopened = await openSwitcher(page)
    await reopened.getByRole('menuitem', { name: PROJECT.name }).click()
    await expect(page).toHaveURL(projectUrl(PROJECT.$id))
  })

  test('inside an environment, lists from the parent', async ({ page }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, {}, recorded)
    await openProject(page, STAGING.$id)

    const menu = await openSwitcher(page)
    const rows = menu.getByRole('menuitem')
    await expect(rows.nth(0)).toContainText(PROJECT.name)
    await expect(rows.nth(1)).toContainText(STAGING.name)
    // The environment routes act on the calling project's environments, so
    // asking the environment itself would list nothing.
    expect(recorded.environmentCallProjects).not.toHaveLength(0)
    expect(new Set(recorded.environmentCallProjects)).toEqual(
      new Set([PROJECT.$id]),
    )
  })

  test('shows only the project when it has no environments', async ({
    page,
  }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, { environments: [] }, recorded)
    await openProject(page)

    const menu = await openSwitcher(page)
    await expect(menu.getByRole('menuitem')).toHaveCount(2)
    await expect(menu.getByRole('menuitem').nth(0)).toContainText(PROJECT.name)
    await expect(
      menu.getByRole('menuitem', { name: 'Create environment' }),
    ).toBeVisible()
    expect(recorded.listCalls).toBeGreaterThan(0)
  })

  test('creates an environment with the id the user typed', async ({
    page,
  }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, { environments: [] }, recorded)
    await openProject(page)

    const menu = await openSwitcher(page)
    await menu.getByRole('menuitem', { name: 'Create environment' }).click()

    const dialog = page.getByRole('dialog', { name: 'Create environment' })
    await expect(dialog).toBeVisible()
    await expect(dialog).toContainText('Its usage is billed under this project')

    await dialog.locator('#environment-name').fill('EU Preview')
    await dialog.getByText('Auto-generated').click()
    await dialog.locator('#environment-id').fill('eu-preview')
    await dialog.getByRole('button', { name: 'Create', exact: true }).click()

    await expect(page.getByText('Environment created')).toBeVisible()
    await expect(dialog).toBeHidden()
    expect(recorded.created).toEqual([
      { environmentId: 'eu-preview', name: 'EU Preview' },
    ])

    // The new environment is a project of its own, and create opens it.
    await expect(page).toHaveURL(projectUrl('eu-preview'))
    await expect(page.getByRole('button', SWITCHER)).toContainText('EU Preview')
  })

  test('creates with an ID.unique() id when the field is left blank', async ({
    page,
  }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, { environments: [] }, recorded)
    await openProject(page)

    const menu = await openSwitcher(page)
    await menu.getByRole('menuitem', { name: 'Create environment' }).click()

    const dialog = page.getByRole('dialog', { name: 'Create environment' })
    await dialog.locator('#environment-name').fill('Staging')
    await dialog.getByRole('button', { name: 'Create', exact: true }).click()

    await expect(page.getByText('Environment created')).toBeVisible()
    expect(recorded.created).toHaveLength(1)
    const sent = recorded.created[0]!
    expect(sent.name).toBe('Staging')
    // Blank means auto-generate, and the generated id still has to satisfy the
    // server-side `ProjectId` rule or every such create would 400.
    expect(sent.environmentId).toBeTruthy()
    expect(sent.environmentId).toMatch(/^[a-z0-9][a-z0-9-]*$/)
    expect(sent.environmentId!.length).toBeLessThanOrEqual(36)
  })

  test('will not send an id the server-side rule rejects', async ({ page }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, { environments: [] }, recorded)
    await openProject(page)

    const menu = await openSwitcher(page)
    await menu.getByRole('menuitem', { name: 'Create environment' }).click()

    const dialog = page.getByRole('dialog', { name: 'Create environment' })
    await dialog.locator('#environment-name').fill('Staging')
    await dialog.getByText('Auto-generated').click()

    const create = dialog.getByRole('button', { name: 'Create', exact: true })
    const id = dialog.locator('#environment-id')

    // Uppercase, underscores and a leading hyphen are all valid Appwrite
    // document ids but are rejected by `ProjectId`.
    for (const invalid of ['Staging', 'my_env', '-staging']) {
      await id.fill(invalid)
      await expect(dialog.getByText('Invalid ID format')).toBeVisible()
      await expect(create).toBeDisabled()
    }

    await id.fill('staging-2')
    await expect(dialog.getByText('Invalid ID format')).toHaveCount(0)
    await expect(create).toBeEnabled()
    await create.click()

    await expect(page.getByText('Environment created')).toBeVisible()
    expect(recorded.created).toEqual([
      { environmentId: 'staging-2', name: 'Staging' },
    ])
  })

  test('shows its own copy when the create rate limit is hit', async ({
    page,
  }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(
      page,
      {
        environments: [],
        createError: {
          status: 429,
          body: {
            message: 'Rate limit for the current endpoint has been exceeded.',
            code: 429,
            type: 'general_rate_limit_exceeded',
            version: '1.0',
          },
        },
      },
      recorded,
    )
    await openProject(page)

    const menu = await openSwitcher(page)
    await menu.getByRole('menuitem', { name: 'Create environment' }).click()

    const dialog = page.getByRole('dialog', { name: 'Create environment' })
    await dialog.locator('#environment-name').fill('Staging')
    await dialog.getByRole('button', { name: 'Create', exact: true }).click()

    await expect(
      page.getByText(
        'Too many environments created for this project in the last hour. Try again later.',
      ),
    ).toBeVisible()
    // The dialog stays open with the typed name so the user can retry.
    await expect(dialog).toBeVisible()
    await expect(dialog.locator('#environment-name')).toHaveValue('Staging')
  })

  test('surfaces the server message when the id is already taken', async ({
    page,
  }) => {
    const recorded = newRecorded()
    const message = 'Project with the requested ID already exists.'
    await mockAppwriteApi(
      page,
      {
        environments: [STAGING],
        createError: {
          status: 409,
          body: {
            message,
            code: 409,
            type: 'project_already_exists',
            version: '1.0',
          },
        },
      },
      recorded,
    )
    await openProject(page)

    const menu = await openSwitcher(page)
    await menu.getByRole('menuitem', { name: 'Create environment' }).click()

    const dialog = page.getByRole('dialog', { name: 'Create environment' })
    await dialog.locator('#environment-name').fill('Staging again')
    await dialog.getByText('Auto-generated').click()
    await dialog.locator('#environment-id').fill(STAGING.$id)
    await dialog.getByRole('button', { name: 'Create', exact: true }).click()

    await expect(page.getByText(message)).toBeVisible()
    await expect(dialog).toBeVisible()
    expect(recorded.created).toEqual([
      { environmentId: STAGING.$id, name: 'Staging again' },
    ])
  })

  test('renames the environment being shown', async ({ page }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, {}, recorded)
    await openProject(page, STAGING.$id)

    const menu = await openSwitcher(page)
    await menu.getByRole('menuitem', { name: 'Rename environment' }).click()

    const dialog = page.getByRole('dialog', { name: 'Rename environment' })
    await expect(dialog).toBeVisible()
    await expect(dialog).toContainText('The environment ID stays the same.')

    const input = dialog.locator('#rename-environment-name')
    await expect(input).toHaveValue(STAGING.name)
    // Renaming to the same name is not a change, so there is nothing to submit.
    await expect(dialog.getByRole('button', { name: 'Rename' })).toBeDisabled()

    await input.fill('Staging EU')
    await dialog.getByRole('button', { name: 'Rename' }).click()

    await expect(page.getByText('Environment renamed')).toBeVisible()
    await expect(dialog).toBeHidden()
    expect(recorded.renamed).toEqual([
      { environmentId: STAGING.$id, name: 'Staging EU' },
    ])
    await expect(page.getByRole('button', SWITCHER)).toContainText('Staging EU')
    expect(new Set(recorded.environmentCallProjects)).toEqual(
      new Set([PROJECT.$id]),
    )
  })

  test('keeps the rename dialog usable when the rename fails', async ({
    page,
  }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(
      page,
      {
        renameError: {
          status: 400,
          body: {
            message: 'Invalid `name` param: Value must be a valid string',
            code: 400,
            type: 'general_argument_invalid',
            version: '1.0',
          },
        },
      },
      recorded,
    )
    await openProject(page, STAGING.$id)

    const menu = await openSwitcher(page)
    await menu.getByRole('menuitem', { name: 'Rename environment' }).click()

    const dialog = page.getByRole('dialog', { name: 'Rename environment' })
    await dialog.locator('#rename-environment-name').fill('Staging EU')
    await dialog.getByRole('button', { name: 'Rename' }).click()

    await expect(
      page.getByText('Invalid `name` param: Value must be a valid string'),
    ).toBeVisible()
    await expect(dialog).toBeVisible()
    // A failed rename must not throw away what the user typed.
    await expect(dialog.locator('#rename-environment-name')).toHaveValue(
      'Staging EU',
    )
  })

  test('deletes through the type-the-name confirmation', async ({ page }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, {}, recorded)
    await openProject(page, STAGING.$id)

    const menu = await openSwitcher(page)
    await menu.getByRole('menuitem', { name: 'Delete environment' }).click()

    const dialog = page.getByRole('dialog', { name: 'Delete environment' })
    await expect(dialog).toBeVisible()
    await expect(dialog).toContainText(
      'Its resources and users are removed with it.',
    )

    const confirm = dialog.getByRole('button', { name: 'Delete', exact: true })
    await expect(confirm).toBeDisabled()

    const input = dialog.getByRole('textbox')
    await input.fill('staging')
    // The confirmation is the name, not the id, and it has to match exactly.
    await expect(confirm).toBeDisabled()
    await input.fill(STAGING.name)
    await expect(confirm).toBeEnabled()
    await confirm.click()

    await expect(page.getByText('Environment deleted')).toBeVisible()
    expect(recorded.deleted).toEqual([STAGING.$id])
    expect(new Set(recorded.environmentCallProjects)).toEqual(
      new Set([PROJECT.$id]),
    )
    // The environment being shown is gone, so the parent opens.
    await expect(page).toHaveURL(projectUrl(PROJECT.$id))

    const reopened = await openSwitcher(page)
    await expect(
      reopened.getByRole('menuitem', { name: STAGING.name }),
    ).toHaveCount(0)
    await expect(
      reopened.getByRole('menuitem', { name: PREVIEW.name }),
    ).toBeVisible()
  })

  test('leaves environments out of organization project lists', async ({
    page,
  }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, {}, recorded)
    await page.goto(`/organizations/${ORGANIZATION.$id}`, {
      waitUntil: 'domcontentloaded',
    })

    await expect
      .poll(() => recorded.projectListQueries.length)
      .toBeGreaterThan(0)
    for (const query of recorded.projectListQueries) {
      expect(query).toContain('{"method":"isNull","attribute":"parentId"}')
    }
  })

  test('renders nothing when the endpoint is absent (self-hosted backend)', async ({
    page,
  }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(
      page,
      {
        // A build with the feature on, pointed at a backend without the routes.
        projectEnvironments: true,
        listError: {
          status: 404,
          body: {
            message: 'general_route_not_found',
            code: 404,
            type: 'general_route_not_found',
            version: '1.0',
          },
        },
      },
      recorded,
    )
    await openProject(page)

    // The sidebar rendered; the switcher is the only thing that must be
    // missing, and without an error of its own.
    await waitForSidebar(page)
    await expect.poll(() => recorded.listCalls).toBeGreaterThan(0)
    await expect(page.getByRole('button', SWITCHER)).toHaveCount(0)
  })

  test('asks for nothing when the profile feature is off', async ({ page }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, { projectEnvironments: false }, recorded)
    await openProject(page)

    await waitForSidebar(page)
    await expect(page.getByRole('button', SWITCHER)).toHaveCount(0)
    expect(recorded.listCalls).toBe(0)
  })
})
