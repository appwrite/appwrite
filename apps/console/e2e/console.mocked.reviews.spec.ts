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
    if (apiPath === '/vcs/installations')
      return json(200, { total: 0, installations: [] })

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
