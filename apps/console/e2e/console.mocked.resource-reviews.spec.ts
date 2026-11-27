import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Code review from a function or site: the "Review pull requests" switch of
 * the create wizards, the Code review card in Settings > Git, and the Reviews
 * tab, which keeps the reviews of pull requests that change the resource's
 * root directory.
 *
 * The API is mocked at the network layer. The repository holds a function in
 * `functions/api` and a web app in `apps/web`.
 */

const NOW = '2026-11-24T08:00:00.000+00:00'

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
  billingPlan: 'tier-1',
  billingEmail: ACCOUNT.email,
  status: 'active',
  prefs: {},
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT = {
  $id: 'proj0000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme',
  teamId: ORGANIZATION.$id,
  region: 'fra',
  status: 'active',
} satisfies Partial<Models.Project>

const INSTALLATION = {
  $id: 'inst0000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  provider: 'github',
  organization: 'acme',
  providerInstallationId: '1',
}

const REPOSITORY = {
  id: '2001',
  name: 'platform',
  organization: 'acme',
  provider: 'github',
  private: true,
  defaultBranch: 'main',
  providerInstallationId: '1',
  authorized: true,
  pushedAt: NOW,
  variables: [],
  runtime: 'node-22',
  framework: 'react',
}

const FUNCTION = {
  $id: 'fn00000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'api',
  enabled: true,
  live: true,
  logging: true,
  runtime: 'node-22',
  deploymentId: '',
  deploymentCreatedAt: '',
  latestDeploymentId: '',
  latestDeploymentCreatedAt: '',
  latestDeploymentStatus: '',
  execute: ['any'],
  scopes: [],
  vars: [],
  events: [],
  schedule: '',
  timeout: 15,
  entrypoint: 'src/main.js',
  commands: 'npm install',
  version: 'v5',
  installationId: INSTALLATION.$id,
  providerRepositoryId: REPOSITORY.id,
  providerBranch: 'main',
  providerRootDirectory: './functions/api',
  providerSilentMode: false,
  buildSpecification: 's-1vcpu-512mb',
  runtimeSpecification: 's-1vcpu-512mb',
}

const SITE_ID = 'site0000000000000000001'

const REPOSITORY_ROW_ID = `reviews-1-${REPOSITORY.id}`

type ReviewRow = {
  $id: string
  $createdAt: string
  $updatedAt: string
  installationId: string
  providerRepositoryId: string
  strength: string
}

function repositoryRow(): ReviewRow {
  return {
    $id: REPOSITORY_ROW_ID,
    $createdAt: NOW,
    $updatedAt: NOW,
    installationId: INSTALLATION.$id,
    providerRepositoryId: REPOSITORY.id,
    strength: 'normal',
  }
}

function review(
  pullRequest: number,
  status: string,
  paths: string[],
  minutesAgo: number,
) {
  const at = new Date(Date.parse(NOW) - minutesAgo * 60_000).toISOString()
  const completed = status === 'completed'
  return {
    $id: `review${pullRequest}`,
    $createdAt: at,
    $updatedAt: at,
    repositoryId: REPOSITORY_ROW_ID,
    providerRepositoryId: REPOSITORY.id,
    providerRepositoryOwner: REPOSITORY.organization,
    providerRepositoryName: REPOSITORY.name,
    providerPullRequestId: String(pullRequest),
    providerPullRequestAuthor: 'octocat',
    providerCommitHash: `abcdef${pullRequest}0000000000000000000000000000000000`,
    providerBaseCommitHash: '',
    trigger: 'opened',
    strength: 'normal',
    status,
    verdict: completed ? 'approve' : '',
    tier: completed ? 'A' : '',
    summary: '',
    walkthrough: paths.map((path) => ({ path, change: 'Changes it.' })),
    reason: '',
    providerReviewId: '',
    startedAt: completed ? at : '',
    completedAt: completed ? at : '',
    findings: [],
  }
}

/** Newest first, as the API orders them. */
const REVIEWS = [
  review(43, 'queued', [], 1),
  review(42, 'completed', ['apps/web/src/page.tsx'], 30),
  review(41, 'completed', ['functions/api/src/main.js', 'README.md'], 60),
]

function corsHeaders(route: Route): Record<string, string> {
  const request = route.request()
  return {
    'access-control-allow-origin': request.headers()['origin'] ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
    'access-control-allow-headers':
      request.headers()['access-control-request-headers'] ?? '*',
  }
}

type Query = { method: string; attribute?: string; values?: unknown[] }

function queries(url: URL): Query[] {
  return [...url.searchParams]
    .filter(([key]) => key.startsWith('queries['))
    .map(([, query]) => JSON.parse(query) as Query)
}

/**
 * Answers every `/v1` call. `reviewed` says whether the project reviews the
 * repository at the start. Records the calls the specs assert on.
 */
async function mockAppwriteApi(page: Page, reviewed: boolean) {
  let row: ReviewRow | undefined = reviewed ? repositoryRow() : undefined
  const calls = {
    order: [] as string[],
    reviewCreates: [] as Request[],
    reviewDeletes: [] as Request[],
  }
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin

  await page.addInitScript(() => {
    localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: { reviews: true } }),
    )
  })

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
    const method = request.method()
    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })

    // Code review
    if (apiPath === '/reviews/repositories') {
      if (method === 'POST') {
        calls.order.push('review')
        calls.reviewCreates.push(request)
        row = repositoryRow()
        return json(201, row)
      }
      const equal = queries(url).find(
        (q) => q.method === 'equal' && q.attribute === 'providerRepositoryId',
      )
      const rows =
        row &&
        (!equal || (equal.values ?? []).includes(row.providerRepositoryId))
          ? [row]
          : []
      return json(200, { total: rows.length, repositories: rows })
    }
    if (apiPath === `/reviews/repositories/${REPOSITORY_ROW_ID}`) {
      if (method === 'DELETE') {
        calls.reviewDeletes.push(request)
        row = undefined
        return route.fulfill({ status: 204, headers })
      }
    }
    if (apiPath === '/reviews') {
      const equal = queries(url).find(
        (q) => q.method === 'equal' && q.attribute === 'repositoryId',
      )
      const reviews = row && equal?.values?.includes(row.$id) ? REVIEWS : []
      return json(200, { total: 0, reviews })
    }

    // Git
    if (apiPath === '/vcs/installations')
      return json(200, { total: 1, installations: [INSTALLATION] })
    if (apiPath === `/vcs/installations/${INSTALLATION.$id}`)
      return json(200, INSTALLATION)
    const repositoryPath = `/vcs/github/installations/${INSTALLATION.$id}/providerRepositories/${REPOSITORY.id}`
    if (apiPath === repositoryPath) return json(200, REPOSITORY)
    if (apiPath === `${repositoryPath}/branches`)
      return json(200, { total: 1, branches: [{ name: 'main' }] })
    if (apiPath === `${repositoryPath}/contents`)
      return json(200, { total: 0, contents: [] })
    if (apiPath === `/vcs/github/installations/${INSTALLATION.$id}/detections`)
      return json(200, {
        framework: 'react',
        installCommand: 'npm install',
        buildCommand: 'npm run build',
        outputDirectory: './dist',
        variables: [],
      })

    // Functions and sites
    if (apiPath === `/functions/${FUNCTION.$id}`) return json(200, FUNCTION)
    if (apiPath === `/functions/${FUNCTION.$id}/deployments`)
      return json(200, { total: 0, deployments: [] })
    if (
      apiPath === `/functions/${FUNCTION.$id}/variables` ||
      apiPath === '/project/variables'
    )
      return json(200, { total: 0, variables: [] })
    if (apiPath === '/functions/runtimes')
      return json(200, {
        total: 1,
        runtimes: [
          {
            $id: 'node-22',
            key: 'node',
            name: 'Node.js',
            version: '22',
            base: '',
            image: '',
            logo: 'node.png',
            supports: ['amd64'],
          },
        ],
      })
    if (apiPath === '/functions/specifications')
      return json(200, { total: 0, specifications: [] })
    if (apiPath === '/sites/frameworks')
      return json(200, {
        total: 1,
        frameworks: [
          {
            key: 'react',
            name: 'React',
            buildRuntime: 'node-22',
            runtimes: ['node-22'],
            adapters: [
              {
                key: 'static',
                installCommand: 'npm install',
                buildCommand: 'npm run build',
                outputDirectory: './dist',
                fallbackFile: 'index.html',
              },
            ],
          },
        ],
      })
    if (apiPath === '/sites' && method === 'POST') {
      calls.order.push('site')
      return json(201, {
        $id: SITE_ID,
        $createdAt: NOW,
        $updatedAt: NOW,
        name: 'web',
      })
    }
    // Stop the create flow after the review step: the steps after it are not
    // what these specs assert.
    if (apiPath === '/proxy/rules/site') {
      calls.order.push('domain')
      return json(400, {
        message: 'Stopped by the test.',
        code: 400,
        type: 'general_argument_invalid',
        version: '1.0',
      })
    }

    // Shell
    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') {
      return method === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, ACCOUNT.prefs)
    }
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/console/variables')
      return json(200, {
        _APP_REVIEWS_ENABLED: true,
        _APP_DOMAIN_SITES: 'appwrite.network',
        _APP_DOMAIN_FUNCTIONS: 'fra.appwrite.run',
      })
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
          'reviews.read',
          'reviews.write',
          'vcs.read',
          'vcs.write',
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

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return calls
}

/** Set to a folder to save a screenshot of each surface, for review. */
const shots = process.env.REVIEWS_SCREENSHOTS

async function capture(page: Page, name: string) {
  if (!shots) return
  // Let the boot overlay fade out first.
  await page.waitForTimeout(1500)
  await page.screenshot({ path: `${shots}/${name}.png` })
}

test.describe('code review from functions and sites (mocked API)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
  })

  test('the Reviews tab shows only the reviews of the function', async ({
    page,
  }) => {
    await mockAppwriteApi(page, true)
    await page.goto(
      `/projects/${PROJECT.$id}/functions/${FUNCTION.$id}/reviews`,
      { waitUntil: 'domcontentloaded' },
    )

    const pullRequest = (number: number) =>
      page.getByRole('row').filter({ hasText: `#${number}` })

    await expect(page.getByRole('tab', { name: 'Reviews' })).toBeVisible({
      timeout: 30_000,
    })
    await expect(pullRequest(41)).toBeVisible()
    await expect(
      page.getByText('Pull requests that change files in functions/api'),
    ).toBeVisible()
    // Changes apps/web only.
    await expect(pullRequest(42)).toHaveCount(0)
    // Queued: nothing shows yet that it changes the function.
    await expect(pullRequest(43)).toHaveCount(0)
    await expect(
      page.getByRole('switch', {
        name: 'Review pull requests in acme/platform',
      }),
    ).toBeChecked()
    await expect(page.getByRole('radio')).toHaveCount(0)
    await capture(page, 'function-reviews-tab')

    // Right-click offers the same actions as the row's ⋯ menu.
    await pullRequest(41).click({ button: 'right' })
    await expect(
      page.getByRole('menuitem', { name: 'Open on GitHub' }),
    ).toBeVisible()
    await capture(page, 'function-reviews-context-menu')
    await page.keyboard.press('Escape')
  })

  test('the Reviews tab opens with its reviews already loaded', async ({
    page,
  }) => {
    await mockAppwriteApi(page, true)
    await page.goto(
      `/projects/${PROJECT.$id}/functions/${FUNCTION.$id}/settings/git`,
      { waitUntil: 'domcontentloaded' },
    )
    await expect(
      page.getByRole('heading', { name: 'Code review', exact: true }),
    ).toBeVisible({ timeout: 30_000 })

    await page.getByRole('tab', { name: 'Reviews' }).click()
    await expect(
      page.getByText('Pull requests that change files in'),
    ).toBeVisible()
    // The loader prefetched the list: it is there on the tab's first paint.
    expect(await page.getByRole('row').filter({ hasText: '#41' }).count()).toBe(
      1,
    )
    expect(await page.locator('main .animate-spin').count()).toBe(0)
  })

  test('Settings > Git turns code review off and on', async ({ page }) => {
    const calls = await mockAppwriteApi(page, true)
    await page.goto(
      `/projects/${PROJECT.$id}/functions/${FUNCTION.$id}/settings/git`,
      { waitUntil: 'domcontentloaded' },
    )

    const toggle = page.getByRole('switch', {
      name: 'Review pull requests in acme/platform',
    })
    await expect(
      page.getByRole('heading', { name: 'Code review', exact: true }),
    ).toBeVisible({ timeout: 30_000 })
    await expect(toggle).toBeChecked()
    await expect(page.getByText('Review strength')).toBeVisible()
    await page
      .getByRole('heading', { name: 'Code review', exact: true })
      .scrollIntoViewIfNeeded()
    await capture(page, 'function-settings-git')

    await toggle.click()
    await expect(toggle).not.toBeChecked()
    expect(calls.reviewDeletes).toHaveLength(1)
    await expect(page.getByText('Review strength')).toHaveCount(0)

    await toggle.click()
    await expect(toggle).toBeChecked()
    expect(calls.reviewCreates).toHaveLength(1)
    expect(calls.reviewCreates[0].postDataJSON()).toEqual({
      installationId: INSTALLATION.$id,
      providerRepositoryId: REPOSITORY.id,
    })
  })

  test('the site wizard turns code review on after the create', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page, false)
    await page.goto(
      `/projects/${PROJECT.$id}/sites/create/repositories/${INSTALLATION.$id}/${REPOSITORY.id}`,
      { waitUntil: 'domcontentloaded' },
    )

    const toggle = page.getByRole('switch', { name: 'Review pull requests' })
    await expect(toggle).toBeChecked({ timeout: 30_000 })
    await capture(page, 'site-wizard')

    await page.getByRole('button', { name: 'Deploy', exact: true }).click()
    await expect.poll(() => calls.order).toEqual(['site', 'review', 'domain'])
    expect(calls.reviewCreates[0].postDataJSON()).toEqual({
      installationId: INSTALLATION.$id,
      providerRepositoryId: REPOSITORY.id,
    })
  })

  test('the site wizard skips code review when switched off', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page, false)
    await page.goto(
      `/projects/${PROJECT.$id}/sites/create/repositories/${INSTALLATION.$id}/${REPOSITORY.id}`,
      { waitUntil: 'domcontentloaded' },
    )

    const toggle = page.getByRole('switch', { name: 'Review pull requests' })
    await expect(toggle).toBeChecked({ timeout: 30_000 })
    await toggle.click()
    await expect(toggle).not.toBeChecked()

    await page.getByRole('button', { name: 'Deploy', exact: true }).click()
    await expect.poll(() => calls.order).toEqual(['site', 'domain'])
    expect(calls.reviewCreates).toHaveLength(0)
  })
})
