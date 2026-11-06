import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * The Review repositories tab.
 *
 * It used to load the first 100 reviewed repositories with no paging or
 * search, and adding one went through a dialog that closed after every pick.
 * Now "Reviewed" pages through what the project reviews, and "All
 * repositories" pages and searches through the GitHub installation, with a
 * switch on every row that turns review on or off in place.
 *
 * The API is mocked at the network layer, with more repositories than fit on
 * one page on both lists.
 */

const NOW = '2026-11-06T08:00:00.000+00:00'

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

const INSTALLATION = {
  $id: 'inst0000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  provider: 'github',
  organization: 'acme',
  providerInstallationId: '1',
}

/** 137 GitHub repositories, more than the old 100 cap. */
const GITHUB = Array.from({ length: 137 }, (_, index) => ({
  id: String(1000 + index),
  name: `service-${String(index).padStart(3, '0')}`,
  organization: 'acme',
  provider: 'github',
  private: index % 3 === 0,
  defaultBranch: 'main',
  providerInstallationId: '1',
  authorized: true,
  pushedAt: NOW,
  variables: [],
  runtime: 'node-22',
}))

type Row = {
  $id: string
  $createdAt: string
  $updatedAt: string
  installationId: string
  providerRepositoryId: string
}

function reviewRow(providerRepositoryId: string): Row {
  return {
    $id: `reviews-1-${providerRepositoryId}`,
    $createdAt: NOW,
    $updatedAt: NOW,
    installationId: INSTALLATION.$id,
    providerRepositoryId,
  }
}

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

/** Reviews start on for the first 12 GitHub repositories. */
async function mockAppwriteApi(page: Page) {
  const reviewed = new Map<string, Row>(
    GITHUB.slice(0, 12).map((repository) => [
      repository.id,
      reviewRow(repository.id),
    ]),
  )
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
        scopes: [
          'projects.read',
          'projects.write',
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

    if (apiPath === '/vcs/installations')
      return json(200, { total: 1, installations: [INSTALLATION] })

    const installationPath = `/vcs/github/installations/${INSTALLATION.$id}/providerRepositories`
    if (apiPath === installationPath) {
      const list = queries(url)
      const limit = queryValue(list, 'limit') ?? 4
      const offset = queryValue(list, 'offset') ?? 0
      const search = (url.searchParams.get('search') ?? '').toLowerCase()
      const matches = GITHUB.filter((repository) =>
        repository.name.includes(search),
      )
      return json(200, {
        total: matches.length,
        runtimeProviderRepositories: matches.slice(offset, offset + limit),
        frameworkProviderRepositories: [],
      })
    }
    if (apiPath.startsWith(`${installationPath}/`)) {
      const id = apiPath.slice(installationPath.length + 1)
      const repository = GITHUB.find((r) => r.id === id)
      return repository
        ? json(200, repository)
        : json(404, { message: 'Not found', code: 404 })
    }

    if (apiPath === '/reviews') return json(200, { total: 0, reviews: [] })
    if (apiPath === '/reviews/repositories') {
      if (request.method() === 'POST') {
        const { providerRepositoryId } = request.postDataJSON() as {
          providerRepositoryId: string
        }
        const row = reviewRow(providerRepositoryId)
        reviewed.set(providerRepositoryId, row)
        return json(201, row)
      }
      const list = queries(url)
      const equal = list.find(
        (q) => q.method === 'equal' && q.attribute === 'providerRepositoryId',
      )
      const rows = [...reviewed.values()].filter(
        (row) =>
          !equal || (equal.values ?? []).includes(row.providerRepositoryId),
      )
      const limit = queryValue(list, 'limit') ?? 25
      const offset = queryValue(list, 'offset') ?? 0
      return json(200, {
        total: rows.length,
        repositories: rows.slice(offset, offset + limit),
      })
    }
    if (
      apiPath.startsWith('/reviews/repositories/') &&
      request.method() === 'DELETE'
    ) {
      const id = apiPath.slice('/reviews/repositories/'.length)
      for (const [key, row] of reviewed) {
        if (row.$id === id) reviewed.delete(key)
      }
      return route.fulfill({ status: 204, headers })
    }

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })
}

test.describe('review repositories', () => {
  test('pages, searches and toggles repositories in place', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    await page.goto(`/projects/${PROJECT.$id}/reviews/repositories`, {
      waitUntil: 'domcontentloaded',
    })

    const row = (name: string) =>
      page.getByRole('row').filter({ hasText: `acme/${name}` })
    const toggle = (name: string) => row(name).getByRole('switch')

    // Reviewed: the first page of what the project reviews, then the next.
    await expect(row('service-000')).toBeVisible({ timeout: 30_000 })
    await expect(toggle('service-000')).toBeChecked()
    await expect(row('service-010')).toHaveCount(0)
    await page.getByRole('button', { name: /next/i }).click()
    await expect(row('service-010')).toBeVisible()

    // All repositories: GitHub's list, searchable, with reviewed ones on.
    await page.getByRole('radio', { name: 'All repositories' }).click()
    await expect(toggle('service-000')).toBeChecked()
    await page.getByRole('button', { name: /next/i }).click()
    await expect(toggle('service-011')).toBeChecked()
    await expect(toggle('service-012')).not.toBeChecked()

    await page.getByPlaceholder('Search GitHub repositories...').fill('130')
    await expect(row('service-130')).toBeVisible()
    await expect(row('service-000')).toHaveCount(0)

    // Turning one on happens in place, no dialog.
    await toggle('service-130').click()
    await expect(toggle('service-130')).toBeChecked()
    await expect(page.getByRole('tab', { name: /Repositories/ })).toContainText(
      '13',
    )

    // And off again.
    await toggle('service-130').click()
    await expect(toggle('service-130')).not.toBeChecked()
  })
})
