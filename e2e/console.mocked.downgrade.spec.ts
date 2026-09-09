import type { Locator, Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'
import { env } from './config/env'
import { appwriteApiPath } from './helpers/appwrite-url'

/**
 * Plan downgrade that has to delete things (mocked API).
 *
 * The flow removes projects, members and project resources, and needs an
 * organization in a precise over-limit state, so the Appwrite API is mocked at
 * the network layer. The point of these specs is that marking is staged: no
 * DELETE may reach the API until the final confirmation is submitted.
 */

const ORG_ID = 'org-downgrade'
const ORG_NAME = 'Downgrade Org'
const FREE_PLAN_ID = 'tier-0'
const PRO_PLAN_ID = 'tier-1'

const ACCOUNT = {
  $id: 'user-owner',
  name: 'Ola Owner',
  email: 'ola.owner@example.com',
}

/** Only the fields the downgrade flow reads off each list payload. */
type NamedResource = Pick<Models.Bucket, '$id' | 'name'>
type MockProject = Pick<Models.Project, '$id' | 'name' | 'region' | 'teamId'>
type MockMembership = Pick<
  Models.Membership,
  '$id' | 'userId' | 'userName' | 'userEmail'
>
type MockOrganization = Pick<
  Models.Organization,
  '$id' | 'name' | 'billingPlan'
>
type MockPlan = Pick<
  Models.BillingPlan,
  '$id' | 'name' | 'desc' | 'order' | 'price' | 'selfService'
> & {
  projects: number
  members: number
  domains: number
}

type ResourceGroup =
  | 'databases'
  | 'buckets'
  | 'functions'
  | 'sites'
  | 'teams'
  | 'topics'
  | 'platforms'
  | 'webhooks'
  | 'wafRules'

type ProjectResources = Record<ResourceGroup, NamedResource[]>

function project(id: string, name: string): MockProject {
  // No region: a regional subdomain would move project calls off the mocked host.
  return { $id: id, name, region: 'unknown', teamId: ORG_ID }
}

const ALPHA = project('proj-alpha', 'Alpha')
const BETA = project('proj-beta', 'Beta')
const GAMMA = project('proj-gamma', 'Gamma')
const PROJECTS = [ALPHA, BETA, GAMMA]

const OWNER_MEMBERSHIP: MockMembership = {
  $id: 'member-ola',
  userId: ACCOUNT.$id,
  userName: ACCOUNT.name,
  userEmail: ACCOUNT.email,
}
const BOB_MEMBERSHIP: MockMembership = {
  $id: 'member-bob',
  userId: 'user-bob',
  userName: 'Bob Builder',
  userEmail: 'bob@example.com',
}
const MEMBERSHIPS = [OWNER_MEMBERSHIP, BOB_MEMBERSHIP]

const EMPTY_RESOURCES: ProjectResources = {
  databases: [],
  buckets: [],
  functions: [],
  sites: [],
  teams: [],
  topics: [],
  platforms: [],
  webhooks: [],
  wafRules: [],
}

const BACKUPS_BUCKET: NamedResource = { $id: 'bucket-backups', name: 'Backups' }

const PROJECT_RESOURCES: Record<string, ProjectResources> = {
  [ALPHA.$id]: {
    ...EMPTY_RESOURCES,
    databases: [{ $id: 'db-alpha', name: 'Alpha DB' }],
    buckets: [{ $id: 'bucket-avatars', name: 'Avatars' }, BACKUPS_BUCKET],
    functions: [{ $id: 'fn-mailer', name: 'Mailer' }],
  },
  [BETA.$id]: {
    ...EMPTY_RESOURCES,
    databases: [{ $id: 'db-beta', name: 'Beta DB' }],
    buckets: [{ $id: 'bucket-uploads', name: 'Uploads' }],
  },
  [GAMMA.$id]: {
    ...EMPTY_RESOURCES,
    databases: [{ $id: 'db-gamma', name: 'Gamma DB' }],
  },
}

const ORGANIZATION: MockOrganization = {
  $id: ORG_ID,
  name: ORG_NAME,
  billingPlan: PRO_PLAN_ID,
}

const FREE_PLAN: MockPlan = {
  $id: FREE_PLAN_ID,
  name: 'Free',
  desc: 'For personal hobby projects',
  order: 0,
  price: 0,
  selfService: true,
  projects: 2,
  members: 1,
  domains: 0,
}

const PRO_PLAN: MockPlan = {
  $id: PRO_PLAN_ID,
  name: 'Pro',
  desc: 'For production applications',
  order: 1,
  price: 15,
  selfService: true,
  projects: 10,
  members: 10,
  domains: 10,
}

/** Endpoint -> resource group -> response envelope key, per project SDK call. */
const PROJECT_RESOURCE_ROUTES: {
  path: string
  group: ResourceGroup
  envelope: string
}[] = [
  { path: '/tablesdb', group: 'databases', envelope: 'databases' },
  { path: '/storage/buckets', group: 'buckets', envelope: 'buckets' },
  { path: '/functions', group: 'functions', envelope: 'functions' },
  { path: '/sites', group: 'sites', envelope: 'sites' },
  { path: '/teams', group: 'teams', envelope: 'teams' },
  { path: '/messaging/topics', group: 'topics', envelope: 'topics' },
  { path: '/project/platforms', group: 'platforms', envelope: 'platforms' },
  { path: '/webhooks', group: 'webhooks', envelope: 'webhooks' },
  { path: '/waf/rules', group: 'wafRules', envelope: 'rules' },
]

const NOT_FOUND = {
  message: 'Not found',
  code: 404,
  type: 'general_route_not_found',
  version: '1.0',
}

const SERVER_ERROR = {
  message: 'Server error',
  code: 500,
  type: 'general_unknown',
  version: '1.0',
}

function compliance(
  type: string,
  currentUsage: number,
  limit: number,
): Models.PlanChangeResourceCompliance {
  const excess = Math.max(0, currentUsage - limit)
  return {
    type,
    currentUsage,
    limit,
    status: excess > 0 ? 'over_limit' : 'within_limit',
    excess,
    resolutionHint: excess > 0 ? `Delete ${excess} ${type}.` : '',
  }
}

function projectCompliance(
  target: MockProject,
  buckets: number,
): Models.PlanChangeProjectCompliance {
  const resources = [
    compliance('databases', PROJECT_RESOURCES[target.$id].databases.length, 1),
    compliance('buckets', buckets, 1),
    compliance('functions', PROJECT_RESOURCES[target.$id].functions.length, 1),
  ]
  return {
    $id: target.$id,
    name: target.name,
    isCompliant: resources.every((resource) => resource.excess === 0),
    resources,
  }
}

/** Server-side compliance for the Free plan: 3 projects, 2 members, Alpha over on buckets. */
const PLAN_CHANGE_LIMITS: Models.PlanChangeLimits = {
  canChangePlan: true,
  unsupportedAddons: [],
  projects: compliance('projects', PROJECTS.length, FREE_PLAN.projects),
  members: compliance('members', MEMBERSHIPS.length, FREE_PLAN.members),
  domains: compliance('domains', 0, FREE_PLAN.domains),
  nonCompliantProjects: 1,
  projectCompliance: [
    projectCompliance(ALPHA, PROJECT_RESOURCES[ALPHA.$id].buckets.length),
    projectCompliance(BETA, PROJECT_RESOURCES[BETA.$id].buckets.length),
    projectCompliance(GAMMA, PROJECT_RESOURCES[GAMMA.$id].buckets.length),
  ],
}

type MockState = {
  /** Flipped by the spec right before the final confirmation is submitted. */
  submitted: boolean
  mutations: { method: string; path: string }[]
  prematureDeletes: string[]
  deletedProjects: Set<string>
  /** Deletes aimed at a project that is already gone; the real API 404s these. */
  orphanedDeletes: string[]
  planChangedTo?: string
  /** Resource list call that answers 500, to exercise the load-failure path. */
  failing?: { projectId: string; group: ResourceGroup }
}

function createMockState(failing?: MockState['failing']): MockState {
  return {
    submitted: false,
    mutations: [],
    prematureDeletes: [],
    deletedProjects: new Set(),
    orphanedDeletes: [],
    failing,
  }
}

function accountResponse(): Models.User<Models.Preferences> {
  const now = new Date().toISOString()
  return {
    ...ACCOUNT,
    $createdAt: now,
    $updatedAt: now,
    registration: now,
    status: true,
    labels: [],
    passwordUpdate: now,
    phone: '',
    emailVerification: true,
    phoneVerification: false,
    mfa: false,
    prefs: {},
    targets: [],
    accessedAt: now,
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
    'access-control-expose-headers': 'x-appwrite-session',
  }
}

async function mockAppwriteApi(page: Page, state: MockState) {
  await page.route(`${env.VITE_APPWRITE_ENDPOINT}/**`, async (route) => {
    const request = route.request()
    const method = request.method()
    const headers = corsHeaders(route)

    if (method === 'OPTIONS') {
      await route.fulfill({ status: 204, headers })
      return
    }

    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })

    const path = appwriteApiPath(request.url())
    const projectId = request.headers()['x-appwrite-project']

    if (method === 'DELETE') {
      // `/project` carries the deleted project only in the header.
      state.mutations.push({
        method,
        path: path === '/project' ? `/project/${projectId}` : path,
      })
      if (!state.submitted) state.prematureDeletes.push(path)

      // Behave like the API: anything scoped to an already-deleted project is
      // gone with it, so the request 404s.
      if (path === '/project' && projectId) {
        state.deletedProjects.add(projectId)
      } else if (projectId && state.deletedProjects.has(projectId)) {
        state.orphanedDeletes.push(path)
        await json(404, { message: 'Project not found' })
        return
      }

      await json(200, {})
      expect(state.prematureDeletes, 'no DELETE before submit').toEqual([])
      return
    }

    if (method === 'PATCH' && path === `/organizations/${ORG_ID}/plan`) {
      state.mutations.push({ method, path })
      state.planChangedTo = FREE_PLAN_ID
      return json(200, { ...ORGANIZATION, billingPlan: FREE_PLAN_ID })
    }

    if (
      method === 'POST' &&
      path === `/organizations/${ORG_ID}/plan/estimations`
    ) {
      return json(200, { direction: 'downgrade', limits: PLAN_CHANGE_LIMITS })
    }

    if (
      method === 'POST' &&
      path === `/organizations/${ORG_ID}/feedbacks/downgrade`
    ) {
      return json(200, {})
    }

    if (method !== 'GET') return json(404, NOT_FOUND)

    if (path === '/account') return json(200, accountResponse())
    if (path === '/account/prefs') return json(200, {})
    if (path === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (path === '/account/payment-methods') {
      return json(200, { total: 0, paymentMethods: [] })
    }

    if (path === '/organizations') {
      return json(200, { total: 1, teams: [ORGANIZATION] })
    }
    if (path === `/organizations/${ORG_ID}`) return json(200, ORGANIZATION)
    if (path === `/organizations/${ORG_ID}/plan`) return json(200, PRO_PLAN)
    // No addons anywhere, so the downgrade never has one to disable.
    if (path.endsWith('/addons')) return json(200, { total: 0, addons: [] })
    if (path === '/console/plans') {
      return json(200, { total: 2, plans: [FREE_PLAN, PRO_PLAN] })
    }
    if (path === '/organization/projects') {
      return json(200, { total: PROJECTS.length, projects: PROJECTS })
    }
    if (
      path === '/organization/memberships' ||
      path === `/teams/${ORG_ID}/memberships`
    ) {
      return json(200, { total: MEMBERSHIPS.length, memberships: MEMBERSHIPS })
    }
    if (path === '/domains') return json(200, { total: 0, domains: [] })

    // Product APIs the console probes alongside tablesDB.
    if (path === '/documentsdb' || path === '/vectorsdb') {
      return json(200, { total: 0, databases: [] })
    }

    const resourceRoute = PROJECT_RESOURCE_ROUTES.find(
      (entry) => entry.path === path,
    )
    if (resourceRoute && projectId && PROJECT_RESOURCES[projectId]) {
      if (
        state.failing?.projectId === projectId &&
        state.failing.group === resourceRoute.group
      ) {
        return json(500, SERVER_ERROR)
      }
      const items = PROJECT_RESOURCES[projectId][resourceRoute.group]
      return json(200, { total: items.length, [resourceRoute.envelope]: items })
    }

    return json(404, NOT_FOUND)
  })
}

/** Innermost element holding both a section heading and one of its own controls. */
function sectionCard(page: Page, heading: string, marker: Locator): Locator {
  return page
    .locator('div')
    .filter({ has: page.getByRole('heading', { name: heading, exact: true }) })
    .filter({ has: marker })
    .last()
}

function selectionCard(page: Page, heading: string): Locator {
  return sectionCard(
    page,
    heading,
    page.getByRole('button', { name: /^(Confirm|Edit) selection$/ }),
  )
}

function resourceStep(page: Page): Locator {
  return sectionCard(
    page,
    'Adjust resources for the target plan',
    page.getByPlaceholder('Search resource types...'),
  )
}

/**
 * Occlusion check. `toBeVisible()` passes for a dialog painted behind the
 * wizard's opaque fullscreen shell, which is exactly how this dialog regressed.
 */
async function expectOnTop(locator: Locator) {
  const box = await locator.boundingBox()
  expect(box).not.toBeNull()
  const onTop = await locator.evaluate(
    (element, point) => {
      const hit = document.elementFromPoint(point.x, point.y)
      return !!hit && element.contains(hit)
    },
    { x: box!.x + box!.width / 2, y: box!.y + box!.height / 2 },
  )
  expect(onTop, 'dialog is painted above the wizard shell').toBe(true)
}

async function openDowngradeWizard(page: Page, state: MockState) {
  await mockAppwriteApi(page, state)
  await page.goto(`/upgrade?orgId=${ORG_ID}`, { waitUntil: 'domcontentloaded' })

  await page
    .getByRole('radio')
    .filter({ hasText: FREE_PLAN.name })
    .click({ timeout: 30_000 })
  await expect(selectionCard(page, 'Projects')).toBeVisible({ timeout: 30_000 })
}

/** Mark Gamma and Bob, confirm both, so the per-project step becomes reachable. */
async function stageOrganizationSelections(page: Page) {
  const projects = selectionCard(page, 'Projects')
  await projects.getByLabel(GAMMA.name).click()
  await projects.getByRole('button', { name: 'Confirm selection' }).click()
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Confirm selection' })
    .click()
  await expect(
    projects.getByRole('button', { name: 'Edit selection' }),
  ).toBeVisible()

  const members = selectionCard(page, 'Members')
  await members.getByLabel(BOB_MEMBERSHIP.userName).click()
  await members.getByRole('button', { name: 'Confirm selection' }).click()
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Confirm selection' })
    .click()
  await expect(
    members.getByRole('button', { name: 'Edit selection' }),
  ).toBeVisible()
}

test.describe('plan downgrade deletions (mocked API)', () => {
  test('marking projects stages the deletion behind a visible confirmation', async ({
    page,
  }) => {
    const state = createMockState()
    await openDowngradeWizard(page, state)

    const projects = selectionCard(page, 'Projects')
    await projects.getByLabel(GAMMA.name).click()
    await expect(projects.getByLabel(GAMMA.name)).toBeChecked()
    await projects.getByRole('button', { name: 'Confirm selection' }).click()

    const dialog = page.getByRole('dialog')
    const title = dialog.getByRole('heading', {
      name: 'Delete selected projects',
    })
    await expect(title).toBeVisible()
    await expectOnTop(title)
    await expect(dialog.getByText(GAMMA.name, { exact: true })).toBeVisible()
    await expect(
      dialog.getByText('Nothing is removed yet', { exact: false }),
    ).toBeVisible()

    await dialog.getByRole('button', { name: 'Confirm selection' }).click()

    await expect(
      projects.getByText('1 project marked for deletion'),
    ).toBeVisible()
    const editSelection = projects.getByRole('button', {
      name: 'Edit selection',
    })
    await expect(editSelection).toBeVisible()

    await editSelection.click()
    await expect(projects.getByLabel(GAMMA.name)).toBeChecked()

    expect(state.prematureDeletes).toEqual([])
    expect(state.mutations).toEqual([])
  })

  test('the project resources step lists only projects that need attention', async ({
    page,
  }) => {
    const state = createMockState()
    await openDowngradeWizard(page, state)
    await stageOrganizationSelections(page)

    const step = resourceStep(page)
    await expect(step).toBeVisible({ timeout: 30_000 })
    // Alpha is over limit, Beta the estimation reported compliant, and Gamma
    // is marked for deletion: only Alpha is worth nine list calls.
    await expect(step.getByRole('button', { name: ALPHA.name })).toBeVisible()
    await expect(step.getByRole('button', { name: BETA.name })).toHaveCount(0)
    await expect(step.getByRole('button', { name: GAMMA.name })).toHaveCount(0)

    await step.getByRole('button', { name: ALPHA.name }).click()
    await expect(
      step.getByRole('button', { name: /^Databases 1\/1$/ }),
    ).toBeVisible()
    const buckets = step.getByRole('button', { name: /^Buckets 2\/1$/ })
    await expect(buckets).toBeVisible()

    await buckets.click()
    for (const bucket of PROJECT_RESOURCES[ALPHA.$id].buckets) {
      await expect(step.getByLabel(bucket.name)).toBeVisible()
    }

    await expect(
      step.getByText('Delete at least 1 to fit the selected plan.'),
    ).toBeVisible()
    expect(state.prematureDeletes).toEqual([])
    expect(state.mutations).toEqual([])
  })

  test('submitting deletes the marked items, then updates the plan', async ({
    page,
  }) => {
    const state = createMockState()
    await openDowngradeWizard(page, state)
    await stageOrganizationSelections(page)

    const step = resourceStep(page)
    await step.getByRole('button', { name: ALPHA.name }).click()
    await step.getByRole('button', { name: /^Buckets 2\/1$/ }).click()
    await step.getByLabel(BACKUPS_BUCKET.name).click()
    await step.getByRole('button', { name: 'Confirm selection' }).click()
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Confirm selection' })
      .click()
    await expect(step.getByText('1 buckets marked for deletion')).toBeVisible()

    await page.getByLabel(/Your feedback/).fill('Too expensive for now.')

    const submit = page.getByRole('button', { name: 'Change plan' })
    await expect(submit).toBeEnabled({ timeout: 30_000 })
    await submit.click()

    const dialog = page.getByRole('dialog')
    await expect(
      dialog.getByRole('heading', { name: 'Confirm plan change' }),
    ).toBeVisible()
    for (const named of [
      { name: GAMMA.name, id: GAMMA.$id },
      { name: BOB_MEMBERSHIP.userName, id: BOB_MEMBERSHIP.$id },
      { name: ALPHA.name, id: ALPHA.$id },
      { name: BACKUPS_BUCKET.name, id: BACKUPS_BUCKET.$id },
    ]) {
      await expect(dialog.getByText(named.name, { exact: true })).toBeVisible()
      await expect(dialog.getByText(named.id, { exact: true })).toBeVisible()
    }
    await expect(
      dialog.getByText(/moves to the Free plan on |will move to the Free plan/),
    ).toBeVisible()

    state.submitted = true
    await dialog.getByRole('button', { name: 'Delete and change plan' }).click()

    await expect
      .poll(() => state.planChangedTo, { timeout: 30_000 })
      .toBe(FREE_PLAN_ID)

    // Exactly what was marked, nothing else, and nothing lost to a project
    // that had already been removed.
    const deletedIds = state.mutations
      .filter((m) => m.method === 'DELETE')
      .map((m) => m.path.split('/').pop())
    expect(new Set(deletedIds)).toEqual(
      new Set([BACKUPS_BUCKET.$id, GAMMA.$id, BOB_MEMBERSHIP.$id]),
    )
    expect(state.orphanedDeletes).toEqual([])
    expect(state.prematureDeletes).toEqual([])
  })

  test('a resource list that fails is reported as failed, not as empty', async ({
    page,
  }) => {
    const state = createMockState({ projectId: ALPHA.$id, group: 'buckets' })
    await openDowngradeWizard(page, state)
    await stageOrganizationSelections(page)

    const step = resourceStep(page)
    await step.getByRole('button', { name: ALPHA.name }).click()

    const buckets = step.getByRole('button', { name: /^Buckets/ })
    await expect(buckets.getByText('Failed')).toBeVisible()
    await expect(
      step.getByRole('button', { name: /^Buckets \d+\/\d+$/ }),
    ).toHaveCount(0)

    await buckets.click()
    await expect(
      step.getByText('Could not load buckets for this project.'),
    ).toBeVisible()

    await page.getByLabel(/Your feedback/).fill('Too expensive for now.')
    await expect(
      page.getByRole('button', { name: 'Change plan' }),
    ).toBeDisabled()
    await expect(
      page.getByText(
        'Some project resources could not be loaded. Reload and try again.',
      ),
    ).toBeVisible()
    expect(state.mutations).toEqual([])
  })
})
