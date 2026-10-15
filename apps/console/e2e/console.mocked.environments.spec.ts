import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Project environment switcher in the project sidebar.
 *
 * The `/project/environments` routes are cloud-only, so there is no backend to
 * run these against: the API is mocked at the network layer and the real
 * sidebar, dialogs and selection storage run on top of it. What is asserted is
 * the request the page sends (an environment is created with the id the user
 * typed, or with `ID.unique()` when the field is blank) and what the page does
 * with each failure the endpoints can return.
 *
 * The surface stays behind the `showProjectEnvironments` debug override and the
 * `projectEnvironments` profile feature; both gates are covered below.
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
  /** `projectEnvironments` profile feature. Cloud has it on; omit for that. */
  projectEnvironments?: boolean
  /** The debug override. Omit for the on state the other specs need. */
  showProjectEnvironments?: boolean
}

/** What the page asked the API for, so the assertions can check the request. */
type Recorded = {
  listCalls: number
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
  const {
    showProjectEnvironments = true,
    projectEnvironments,
    environments = [STAGING, PREVIEW],
  } = scenario

  await page.addInitScript(
    ({ debugFlag, featureOverride }) => {
      window.localStorage.setItem(
        'debug:showProjectEnvironments',
        debugFlag ? 'true' : 'false',
      )
      if (featureOverride !== null) {
        window.localStorage.setItem(
          'debug:consoleProfile',
          JSON.stringify({
            id: 'cloud',
            features: { projectEnvironments: featureOverride },
          }),
        )
      }
    },
    {
      debugFlag: showProjectEnvironments,
      featureOverride:
        typeof projectEnvironments === 'boolean' ? projectEnvironments : null,
    },
  )

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
    if (apiPath === '/projects' || apiPath === '/organization/projects')
      return json(200, { total: 1, projects: [PROJECT] })
    if (
      apiPath === '/project' ||
      apiPath === `/projects/${PROJECT.$id}` ||
      apiPath === `/projects/${PROJECT.$id}/console-access`
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

function newRecorded(): Recorded {
  return { listCalls: 0, created: [], renamed: [], deleted: [] }
}

/** Opens a project page that renders the sidebar (settings has no loader). */
async function openProject(page: Page) {
  await page.goto(`/projects/${PROJECT.$id}/settings`, {
    waitUntil: 'domcontentloaded',
  })
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

/** Selects a row in the switcher, which is what rename and delete act on. */
async function selectEnvironment(page: Page, name: string) {
  const menu = await openSwitcher(page)
  await menu.getByRole('menuitem', { name, exact: false }).click()
  await expect(page.getByRole('button', SWITCHER)).toContainText(name)
}

function storedSelection(page: Page) {
  return page.evaluate(
    (projectId) =>
      window.localStorage.getItem(`console.projectEnvironment.${projectId}`),
    PROJECT.$id,
  )
}

test.describe('project environment switcher (mocked API)', () => {
  test('lists the project first and its environments under it', async ({
    page,
  }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, {}, recorded)
    await openProject(page)

    // Nothing is stored yet, so the project is the selected environment.
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

    // The project is managed from its own settings, not from the switcher.
    await expect(
      menu.getByRole('menuitem', { name: 'Rename environment' }),
    ).toHaveCount(0)
    await expect(
      menu.getByRole('menuitem', { name: 'Delete environment' }),
    ).toHaveCount(0)
  })

  test('switching an environment persists the selection per project', async ({
    page,
  }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, {}, recorded)
    await openProject(page)

    await selectEnvironment(page, STAGING.name)
    // One toast per switch; a second switch stacks another, so check it here.
    await expect(page.getByText('Environment switched').first()).toBeVisible()
    expect(await storedSelection(page)).toBe(STAGING.$id)

    // Back to the project: the key is removed rather than set to the project id.
    await selectEnvironment(page, PROJECT.name)
    expect(await storedSelection(page)).toBeNull()
  })

  test('falls back to the project when the stored environment is gone', async ({
    page,
  }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, { environments: [PREVIEW] }, recorded)
    await page.addInitScript(
      ({ projectId, environmentId }) => {
        window.localStorage.setItem(
          `console.projectEnvironment.${projectId}`,
          environmentId,
        )
      },
      { projectId: PROJECT.$id, environmentId: 'deleted-on-another-device' },
    )
    await openProject(page)

    const trigger = page.getByRole('button', SWITCHER)
    await expect(trigger).toContainText(PROJECT.name, { timeout: 30_000 })
    // A loaded list that does not contain the stored id clears it.
    await expect.poll(() => storedSelection(page)).toBeNull()
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

    // Create switches to the new environment and it is listed after the refetch.
    await expect(page.getByRole('button', SWITCHER)).toContainText('EU Preview')
    expect(await storedSelection(page)).toBe('eu-preview')
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
    await expect(dialog).toContainText(
      'Allowed characters: lowercase letters, numbers, non-leading hyphen',
    )

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

  test('renames the selected environment', async ({ page }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, {}, recorded)
    await openProject(page)
    await selectEnvironment(page, STAGING.name)

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
    // The id is unchanged, so the selection survives the rename.
    await expect(page.getByRole('button', SWITCHER)).toContainText('Staging EU')
    expect(await storedSelection(page)).toBe(STAGING.$id)
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
    await openProject(page)
    await selectEnvironment(page, STAGING.name)

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
    await openProject(page)
    await selectEnvironment(page, STAGING.name)

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
    // Deleting the selected environment falls back to the project.
    await expect(page.getByRole('button', SWITCHER)).toContainText(PROJECT.name)
    expect(await storedSelection(page)).toBeNull()

    const reopened = await openSwitcher(page)
    await expect(
      reopened.getByRole('menuitem', { name: STAGING.name }),
    ).toHaveCount(0)
    await expect(
      reopened.getByRole('menuitem', { name: PREVIEW.name }),
    ).toBeVisible()
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
    await expect(page.getByText('Failed to')).toHaveCount(0)
  })

  test('asks for nothing when the profile feature is off', async ({ page }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, { projectEnvironments: false }, recorded)
    await openProject(page)

    await waitForSidebar(page)
    await expect(page.getByRole('button', SWITCHER)).toHaveCount(0)
    expect(recorded.listCalls).toBe(0)
  })

  test('asks for nothing when the debug override is off', async ({ page }) => {
    const recorded = newRecorded()
    await mockAppwriteApi(page, { showProjectEnvironments: false }, recorded)
    await openProject(page)

    await waitForSidebar(page)
    await expect(page.getByRole('button', SWITCHER)).toHaveCount(0)
    expect(recorded.listCalls).toBe(0)
  })
})
