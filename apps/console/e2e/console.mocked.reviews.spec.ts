import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * The Review list and a review's page.
 *
 * The list keeps its page and filters in the URL, so going back from a review
 * returns to the same page, and a filtered link opens filtered. A project that
 * reviews nothing yet gets the product empty state instead of an empty table.
 *
 * The API is mocked at the network layer.
 */

const NOW = '2026-11-20T12:00:00.000+00:00'

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
  region: 'fra',
  status: 'active',
} satisfies Partial<Models.Project>

const STATUSES = ['completed', 'failed', 'skipped', 'processing'] as const

/** 30 reviews of acme/api, newest first: three pages of 10. */
const REVIEWS = Array.from({ length: 30 }, (_, index) => {
  const status = STATUSES[index % STATUSES.length]
  const completed = status === 'completed'
  return {
    $id: `review${String(index).padStart(4, '0')}`,
    $createdAt: new Date(Date.parse(NOW) - index * 3_600_000).toISOString(),
    $updatedAt: NOW,
    repositoryId: 'repository0001',
    providerRepositoryId: '1000',
    providerRepositoryOwner: 'acme',
    providerRepositoryName: 'api',
    providerPullRequestId: String(500 - index),
    providerPullRequestAuthor: 'octocat',
    providerCommitHash: '0123456789abcdef0123456789abcdef01234567',
    providerBaseCommitHash: 'fedcba9876543210fedcba9876543210fedcba98',
    trigger: 'opened',
    strength: 'normal',
    status,
    verdict: completed ? 'approve' : '',
    tier: completed ? 'A' : '',
    summary: '',
    reason: status === 'failed' ? 'The sandbox did not start.' : '',
    providerReviewId: completed ? '900' : '',
    startedAt: NOW,
    completedAt: status === 'processing' ? '' : NOW,
    findings: [],
  }
})

function corsHeaders(route: Route): Record<string, string> {
  return {
    'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': '*',
    'access-control-allow-methods': '*',
  }
}

type Query = { method: string; attribute?: string; values?: unknown[] }

function queries(url: URL): Query[] {
  return [...url.searchParams]
    .filter(([key]) => key.startsWith('queries['))
    .map(([, query]) => JSON.parse(query) as Query)
}

function queryValue(list: Query[], method: string): number | undefined {
  const query = list.find((q) => q.method === method)
  return query ? Number(query.values?.[0]) : undefined
}

async function mockAppwriteApi(page: Page, { empty = false } = {}) {
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin

  // Review is still behind a feature flag.
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
    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') {
      // Updating prefs answers with the account, which keeps it verified.
      return request.method() === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, {})
    }
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/console/variables')
      return json(200, { _APP_REVIEWS_ENABLED: true })
    if (apiPath === '/health/version') return json(200, { version: '2.0.0' })
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, {
        roles: ['owner'],
        scopes: ['projects.read', 'reviews.read', 'reviews.write'],
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
    // The installation tells the review links which provider to point at.
    if (apiPath === '/vcs/installations')
      return json(
        200,
        empty
          ? { total: 0, installations: [] }
          : {
              total: 1,
              installations: [
                {
                  $id: 'installation0001',
                  $createdAt: NOW,
                  $updatedAt: NOW,
                  provider: 'github',
                  organization: 'acme',
                  providerInstallationId: '1',
                },
              ],
            },
      )

    if (apiPath === '/reviews') {
      const list = queries(url)
      const status = list.find(
        (q) => q.method === 'equal' && q.attribute === 'status',
      )
      const rows = (empty ? [] : REVIEWS).filter(
        (review) => !status || (status.values ?? []).includes(review.status),
      )
      const limit = queryValue(list, 'limit') ?? 10
      const offset = queryValue(list, 'offset') ?? 0
      return json(200, {
        total: rows.length,
        reviews: rows.slice(offset, offset + limit),
      })
    }
    if (apiPath === '/reviews/repositories') {
      return json(
        200,
        empty
          ? { total: 0, repositories: [] }
          : {
              total: 1,
              repositories: [
                {
                  $id: 'repository0001',
                  $createdAt: NOW,
                  $updatedAt: NOW,
                  installationId: 'installation0001',
                  providerRepositoryId: '1000',
                  strength: 'normal',
                },
              ],
            },
      )
    }
    if (apiPath === '/reviews/learnings')
      return json(200, { total: 0, learnings: [] })
    if (apiPath.endsWith('/events')) return json(200, { total: 0, events: [] })
    if (apiPath.startsWith('/reviews/')) {
      const review = REVIEWS.find(
        (r) => r.$id === apiPath.slice('/reviews/'.length),
      )
      return review
        ? json(200, review)
        : json(404, { message: 'Review not found', code: 404 })
    }

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })
}

const row = (page: Page, pull: number) =>
  page.getByRole('row').filter({ hasText: `#${pull}` })

/** Answers a request the shared mock does not know, preflight included. */
function fulfillJson(route: Route, body: unknown) {
  return route.request().method() === 'OPTIONS'
    ? route.fulfill({ status: 204, headers: corsHeaders(route) })
    : route.fulfill({
        status: 200,
        headers: { ...corsHeaders(route), 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })
}

test.describe('reviews', () => {
  test('keeps the page in the URL across a visit to a review', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    await page.goto(`/projects/${PROJECT.$id}/reviews`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(row(page, 500)).toBeVisible({ timeout: 30_000 })
    await page.getByRole('button', { name: /next/i }).click()
    await expect(row(page, 490)).toBeVisible()
    await expect(page).toHaveURL(/[?&]page=2/)

    await row(page, 490).getByRole('link', { name: /#490/ }).click()
    await expect(page.getByRole('heading', { name: /acme\/api/ })).toBeVisible()
    await page.getByRole('button', { name: 'Back to reviews' }).click()

    await expect(page).toHaveURL(/[?&]page=2/)
    await expect(row(page, 490)).toBeVisible()
  })

  test('Back from a review opened by link goes to the list', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    await page.goto(`/projects/${PROJECT.$id}/reviews/${REVIEWS[10]!.$id}`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(page.getByRole('heading', { name: /acme\/api/ })).toBeVisible({
      timeout: 30_000,
    })
    await page.getByRole('button', { name: 'Back to reviews' }).click()

    await expect(page).toHaveURL(/\/reviews\/?$/)
    await expect(row(page, 500)).toBeVisible()
  })

  test('opens a filtered link filtered', async ({ page }) => {
    await mockAppwriteApi(page)
    // The router stores the encoded filter keys as a string.
    const query = encodeURIComponent(
      encodeURIComponent(
        JSON.stringify([{ c: 'status', o: 'equal', v: 'failed' }]),
      ),
    )
    await page.goto(`/projects/${PROJECT.$id}/reviews?query=${query}`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(row(page, 499)).toBeVisible({ timeout: 30_000 })
    await expect(row(page, 500)).toHaveCount(0)
  })

  test('row menu copies and links without opening the review', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    await page.goto(`/projects/${PROJECT.$id}/reviews`, {
      waitUntil: 'domcontentloaded',
    })

    await row(page, 500)
      .getByRole('button', { name: /actions for/i })
      .click({ timeout: 30_000 })
    await expect(page.getByRole('menuitem', { name: 'Copy' })).toBeVisible()
    await expect(
      page.getByRole('menuitem', { name: 'Open on GitHub' }),
    ).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page).toHaveURL(/\/reviews\/?$/)
  })

  test('Performance opens without waiting on categories or repository names', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    // Registered later, so these answer before the shared mock.
    let releaseCategories = () => {}
    const categoriesGate = new Promise<void>((resolve) => {
      releaseCategories = resolve
    })
    const nameLookups: string[] = []
    const fulfill = (route: Route, body: unknown) =>
      route.fulfill({
        status: 200,
        headers: {
          ...corsHeaders(route),
          'content-type': 'application/json',
        },
        body: JSON.stringify(body),
      })
    await page.route('**/v1/reviews/authors**', (route) =>
      route.request().method() === 'OPTIONS'
        ? route.fulfill({ status: 204, headers: corsHeaders(route) })
        : fulfill(route, {
            total: 1,
            authors: [
              {
                author: 'octocat',
                pulls: 3,
                reviews: 4,
                approved: 2,
                approvedFirst: 1,
                findings: 5,
                critical: 0,
                major: 1,
                resolved: 2,
                dismissed: 1,
                tier: 'A',
                lastReviewAt: NOW,
              },
            ],
          }),
    )
    await page.route('**/v1/reviews/categories**', async (route) => {
      if (route.request().method() === 'OPTIONS') {
        return route.fulfill({ status: 204, headers: corsHeaders(route) })
      }
      await categoriesGate
      return fulfill(route, {
        total: 1,
        categories: [
          { category: 'bug', findings: 5, resolved: 2, dismissed: 1 },
        ],
      })
    })
    await page.route('**/providerRepositories/**', (route) => {
      if (route.request().method() === 'OPTIONS') {
        return route.fulfill({ status: 204, headers: corsHeaders(route) })
      }
      nameLookups.push(route.request().url())
      return fulfill(route, {
        id: '1000',
        name: 'api',
        organization: 'acme',
        provider: 'github',
        private: false,
      })
    })

    await page.goto(`/projects/${PROJECT.$id}/reviews`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(row(page, 500)).toBeVisible({ timeout: 30_000 })
    await page.getByRole('tab', { name: 'Performance' }).click()

    // The authors show while categories are still on their way.
    await expect(
      page.getByRole('row').filter({ hasText: 'octocat' }),
    ).toBeVisible()
    await expect(page.getByText('Findings by category')).toHaveCount(0)
    expect(nameLookups).toEqual([])

    // Opening the repository list names the repositories.
    await page.getByRole('combobox', { name: 'Repository' }).click()
    await expect(page.getByRole('option', { name: 'acme/api' })).toBeVisible()
    expect(nameLookups.length).toBeGreaterThan(0)
    await page.keyboard.press('Escape')

    releaseCategories()
    await expect(page.getByText('Findings by category')).toBeVisible()

    // Without a summary (an API that predates it), the authors add up to the
    // tiles and the chart stays out.
    await expect(page.getByText('Approved').first()).toBeVisible()
    await expect(page.getByRole('tablist', { name: 'Chart' })).toHaveCount(0)
  })

  test('Performance compares with the days before and opens an author’s reviews', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    const fulfill = (route: Route, body: unknown) =>
      route.request().method() === 'OPTIONS'
        ? route.fulfill({ status: 204, headers: corsHeaders(route) })
        : route.fulfill({
            status: 200,
            headers: {
              ...corsHeaders(route),
              'content-type': 'application/json',
            },
            body: JSON.stringify(body),
          })
    const period = {
      start: NOW,
      end: NOW,
      critical: 0,
      major: 1,
      minor: 3,
      info: 1,
    }
    const today = Date.UTC(2026, 10, 20)
    await page.route('**/v1/reviews/summary**', (route) =>
      fulfill(route, {
        periods: [
          {
            ...period,
            pulls: 3,
            reviews: 4,
            approved: 2,
            approvedFirst: 1,
            findings: 5,
            resolved: 2,
            dismissed: 1,
            duration: 90_000,
          },
          {
            ...period,
            pulls: 2,
            reviews: 2,
            approved: 1,
            approvedFirst: 1,
            findings: 4,
            resolved: 1,
            dismissed: 1,
            duration: 120_000,
          },
        ],
        days: Array.from({ length: 30 }, (_, index) => {
          const last = index === 29
          return {
            date: new Date(today - (29 - index) * 86_400_000).toISOString(),
            reviews: last ? 4 : 0,
            approved: last ? 2 : 0,
            changesRequested: last ? 2 : 0,
            commented: 0,
            findings: last ? 5 : 0,
            critical: 0,
            major: last ? 1 : 0,
            minor: last ? 3 : 0,
            info: last ? 1 : 0,
            resolved: last ? 2 : 0,
            dismissed: last ? 1 : 0,
          }
        }),
      }),
    )
    await page.route('**/v1/reviews/authors**', (route) =>
      fulfill(route, {
        total: 1,
        authors: [
          {
            author: 'octocat',
            pulls: 3,
            reviews: 4,
            approved: 2,
            approvedFirst: 1,
            findings: 5,
            critical: 0,
            major: 1,
            resolved: 2,
            dismissed: 1,
            tier: 'A',
            lastReviewAt: NOW,
          },
        ],
      }),
    )
    const reviewListQueries: string[] = []
    page.on('request', (request) => {
      const url = new URL(request.url())
      if (url.pathname === '/v1/reviews') {
        reviewListQueries.push(decodeURIComponent(url.search))
      }
    })

    await page.goto(`/projects/${PROJECT.$id}/reviews/performance`, {
      waitUntil: 'domcontentloaded',
    })

    // Three pull requests against two the days before, and the median review time.
    await expect(page.getByText('+50%')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('1m 30s')).toBeVisible()

    // The chart switches from verdicts to severity.
    const chart = page.getByRole('tablist', { name: 'Chart' })
    await expect(page.getByText('Changes requested')).toBeVisible()
    await chart.getByRole('tab', { name: 'Findings' }).click()
    await expect(page.getByText('Critical and major')).toBeVisible()

    // Selecting an author opens the reviews list filtered to them.
    await page
      .getByRole('row')
      .filter({ hasText: 'octocat' })
      .getByText('4 reviews')
      .click()
    await expect(page).toHaveURL(/\/reviews\?query=/)
    await expect
      .poll(() =>
        reviewListQueries.some(
          (query) =>
            query.includes('providerPullRequestAuthor') &&
            query.includes('octocat'),
        ),
      )
      .toBe(true)
  })

  test('Performance pages, searches and sorts hundreds of authors and repositories', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    // dev-000 is the busiest author and acme/service-000 the busiest repository.
    const figures = (index: number, count: number) => ({
      pulls: count - index,
      reviews: count - index + 1,
      approved: Math.floor((count - index) / 2),
      approvedFirst: Math.floor((count - index) / 3),
      findings: 4,
      critical: index % 3 === 0 ? 1 : 0,
      major: 1,
      resolved: 2,
      dismissed: 1,
      lastReviewAt: NOW,
    })
    const authors = Array.from({ length: 240 }, (_, index) => ({
      author: `dev-${String(index).padStart(3, '0')}`,
      tier: 'A',
      ...figures(index, 240),
    }))
    const repositories = Array.from({ length: 130 }, (_, index) => ({
      repositoryId: `repository${String(index).padStart(4, '0')}`,
      providerRepositoryOwner: 'acme',
      providerRepositoryName: `service-${String(index).padStart(3, '0')}`,
      ...figures(index, 130),
    }))
    const period = {
      start: NOW,
      end: NOW,
      pulls: 500,
      reviews: 600,
      approved: 300,
      approvedFirst: 200,
      findings: 900,
      critical: 10,
      major: 40,
      minor: 600,
      info: 250,
      resolved: 400,
      dismissed: 100,
      duration: 90_000,
    }
    await page.route('**/v1/reviews/summary**', (route) =>
      fulfillJson(route, {
        periods: [period, period],
        days: [],
        repositories,
      }),
    )
    await page.route('**/v1/reviews/authors**', (route) =>
      fulfillJson(route, { total: authors.length, authors }),
    )
    await page.route('**/v1/reviews/categories**', (route) =>
      fulfillJson(route, { total: 0, categories: [] }),
    )
    const nameLookups: string[] = []
    page.on('request', (request) => {
      if (request.url().includes('/providerRepositories/')) {
        nameLookups.push(request.url())
      }
    })

    await page.goto(`/projects/${PROJECT.$id}/reviews/performance`, {
      waitUntil: 'domcontentloaded',
    })

    // Ten of the 240 authors, the busiest first.
    const authorsCard = page.getByRole('region', { name: 'Authors' })
    const authorRows = authorsCard.getByRole('row')
    await expect(authorRows.filter({ hasText: 'dev-000' })).toBeVisible({
      timeout: 30_000,
    })
    await expect(authorRows).toHaveCount(11)
    await expect(authorsCard.getByText('1-10 of 240')).toBeVisible()

    // The next page carries on from the eleventh.
    await authorsCard.getByRole('button', { name: 'Go to next page' }).click()
    await expect(authorRows.filter({ hasText: 'dev-010' })).toBeVisible()
    await expect(authorRows.filter({ hasText: 'dev-000' })).toHaveCount(0)

    // Sorting covers every author, not only the page, and starts over.
    await authorsCard
      .getByRole('columnheader', { name: 'Pull requests' })
      .getByRole('button')
      .click()
    await expect(authorRows.nth(1)).toContainText('dev-239')
    await expect(authorsCard.getByText('1-10 of 240')).toBeVisible()

    // The search finds an author on any page.
    await authorsCard
      .getByRole('textbox', { name: 'Search authors...' })
      .fill('dev-123')
    await expect(authorRows).toHaveCount(2)
    await expect(authorRows.nth(1)).toContainText('dev-123')
    await authorsCard
      .getByRole('textbox', { name: 'Search authors...' })
      .fill('nobody')
    await expect(
      authorsCard.getByText('No authors match your search'),
    ).toBeVisible()

    // The repositories page and search the same way.
    const repositoriesCard = page.getByRole('region', { name: 'Repositories' })
    const repositoryRows = repositoriesCard.getByRole('row')
    await expect(repositoryRows).toHaveCount(11)
    await expect(repositoriesCard.getByText('1-10 of 130')).toBeVisible()
    await repositoriesCard
      .getByRole('textbox', { name: 'Search repositories...' })
      .fill('service-129')
    await expect(repositoryRows).toHaveCount(2)
    await expect(repositoryRows.nth(1)).toContainText('acme/service-129')

    // The picker lists every repository of the range, named by its reviews.
    await page.getByRole('combobox', { name: 'Repository' }).click()
    await expect(page.getByRole('option')).toHaveCount(131)
    await page
      .getByRole('dialog')
      .getByPlaceholder('Search repositories...')
      .fill('service-129')
    await page.getByRole('option', { name: /acme\/service-129/ }).click()
    await expect(page).toHaveURL(/repositoryId=repository0129/)
    await expect(
      page.getByRole('combobox', { name: 'Repository' }),
    ).toContainText('acme/service-129')
    expect(nameLookups).toEqual([])
  })

  test('status tabs count the reviews and filter the list', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    await page.goto(`/projects/${PROJECT.$id}/reviews`, {
      waitUntil: 'domcontentloaded',
    })

    // 30 reviews: 8 failed, 7 skipped and 7 running among them.
    const tabs = page.getByRole('tablist', { name: 'Status' })
    await expect(tabs.getByRole('tab', { name: /^All\s*30$/ })).toBeVisible({
      timeout: 30_000,
    })
    await expect(
      tabs.getByRole('tab', { name: /^In progress\s*7$/ }),
    ).toBeVisible()
    await expect(tabs.getByRole('tab', { name: /^Failed\s*8$/ })).toBeVisible()

    await tabs.getByRole('tab', { name: /^Failed/ }).click()
    await expect(page).toHaveURL(/[?&]query=/)
    await expect(row(page, 499)).toBeVisible()
    await expect(row(page, 500)).toHaveCount(0)
    await expect(
      tabs.getByRole('tab', { name: /^Failed/, selected: true }),
    ).toBeVisible()

    await tabs.getByRole('tab', { name: /^All/ }).click()
    await expect(row(page, 500)).toBeVisible()
    await expect(page).not.toHaveURL(/[?&]query=/)
  })

  test('repositories show their last 30 days and open their reviews and performance', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    await page.route('**/v1/reviews/summary**', (route) =>
      fulfillJson(route, {
        periods: [],
        days: [],
        repositories: [
          {
            repositoryId: 'repository0001',
            providerRepositoryOwner: 'acme',
            providerRepositoryName: 'api',
            pulls: 12,
            reviews: 15,
            approved: 9,
            approvedFirst: 6,
            findings: 20,
            critical: 2,
            major: 5,
            resolved: 11,
            dismissed: 3,
            lastReviewAt: NOW,
          },
        ],
      }),
    )
    await page.route('**/providerRepositories/**', (route) =>
      fulfillJson(route, {
        id: '1000',
        name: 'api',
        organization: 'acme',
        provider: 'github',
        private: false,
      }),
    )
    // Performance needs its authors before it opens.
    await page.route('**/v1/reviews/authors**', (route) =>
      fulfillJson(route, { total: 0, authors: [] }),
    )
    await page.route('**/v1/reviews/categories**', (route) =>
      fulfillJson(route, { total: 0, categories: [] }),
    )
    const listQueries: string[] = []
    page.on('request', (request) => {
      const url = new URL(request.url())
      if (url.pathname === '/v1/reviews') {
        listQueries.push(decodeURIComponent(url.search))
      }
    })

    await page.goto(`/projects/${PROJECT.$id}/reviews/repositories`, {
      waitUntil: 'domcontentloaded',
    })
    const repository = page.getByRole('row').filter({ hasText: 'acme/api' })
    await expect(repository.getByText('15 reviews')).toBeVisible({
      timeout: 30_000,
    })
    await expect(repository.getByText('2 critical')).toBeVisible()

    // The row menu opens the repository's tabs first, like other Console menus.
    await repository.getByRole('button', { name: /actions for/i }).click()
    await expect(page.getByRole('menuitem').first()).toHaveText('Reviews')
    await page
      .getByRole('menuitem', { name: 'Performance', exact: true })
      .click()
    await expect(page).toHaveURL(
      /\/reviews\/performance\?repositoryId=repository0001/,
    )
    await expect(
      page.getByRole('combobox', { name: 'Repository' }),
    ).toContainText('acme/api')

    // Selecting the row opens its reviews.
    await page.goBack()
    await page
      .getByRole('row')
      .filter({ hasText: 'acme/api' })
      .getByText('15 reviews')
      .click()
    await expect(page).toHaveURL(/\/reviews\?query=/)
    await expect
      .poll(() =>
        listQueries.some(
          (query) =>
            query.includes('repositoryId') && query.includes('repository0001'),
        ),
      )
      .toBe(true)
  })

  test('learnings narrow to one repository', async ({ page }) => {
    await mockAppwriteApi(page)
    const learning = (id: string, body: string) => ({
      $id: id,
      $createdAt: NOW,
      repositoryId: 'repository0001',
      body,
      author: 'octocat',
      sourceUrl: 'https://github.com/acme/api/pull/7#issuecomment-1',
    })
    const learningQueries: string[] = []
    await page.route('**/v1/reviews/learnings**', (route) => {
      learningQueries.push(
        decodeURIComponent(new URL(route.request().url()).search),
      )
      return fulfillJson(route, {
        total: 2,
        learnings: [
          learning('learning0001', 'Prefer early returns.'),
          learning('learning0002', 'Keep snake_case column names.'),
        ],
      })
    })
    await page.route('**/providerRepositories/**', (route) =>
      fulfillJson(route, {
        id: '1000',
        name: 'api',
        organization: 'acme',
        provider: 'github',
        private: false,
      }),
    )

    await page.goto(`/projects/${PROJECT.$id}/reviews/learnings`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page.getByText('Prefer early returns.')).toBeVisible({
      timeout: 30_000,
    })

    // The repository a learning is about narrows the list to it.
    await page
      .getByRole('row')
      .filter({ hasText: 'Prefer early returns.' })
      .getByRole('button', { name: 'acme/api' })
      .click()
    await expect(page).toHaveURL(/[?&]repositoryId=repository0001/)
    await expect
      .poll(() =>
        learningQueries.some((query) => query.includes('repository0001')),
      )
      .toBe(true)
    await expect(
      page.getByRole('combobox', { name: 'Repository' }),
    ).toContainText('acme/api')
  })

  test('shows the product empty state before the first repository', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { empty: true })
    await page.goto(`/projects/${PROJECT.$id}/reviews`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(
      page.getByRole('heading', { name: 'Review pull requests with AI' }),
    ).toBeVisible({ timeout: 30_000 })
    await page.getByRole('button', { name: 'Choose repositories' }).click()
    await expect(page).toHaveURL(/\/reviews\/repositories/)
  })
})
