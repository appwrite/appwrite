import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'
import { CONSOLE_SESSION_COOKIE_NAME } from '../src/lib/console-session-cookie'

/**
 * Organization settings → Installed apps.
 *
 * Owners change which projects an installed app can reach; other entries in
 * its authorization details are kept as they are. Developers see the list but
 * not the management actions. A failed load offers a retry instead of the
 * empty state.
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
  name: 'Acme',
  billingPlan: 'tier-1',
  billingEmail: ACCOUNT.email,
  status: 'active',
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECTS = ['Storefront', 'Mobile', 'Marketing'].map((name, index) => ({
  $id: `proj000000000000000000${index}`,
  $createdAt: NOW,
  $updatedAt: NOW,
  name,
  teamId: ORGANIZATION.$id,
  region: 'fra',
  status: 'active',
}))

const APP = {
  $id: 'app00000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Pagewise Analytics',
  enabled: true,
  teamId: 'publisher00000000000001',
  tags: ['analytics'],
  labels: [],
  images: [],
  contacts: [],
  installationScopes: ['projects.read'],
}

const ORGANIZATION_DETAIL = {
  type: 'organization',
  identifiers: [ORGANIZATION.$id],
}

function installation(): Models.AppInstallation {
  return {
    $id: 'installation00000000001',
    $createdAt: NOW,
    $updatedAt: NOW,
    appId: APP.$id,
    teamId: ORGANIZATION.$id,
    scopes: ['projects.read'],
    authorizationDetails: [
      ORGANIZATION_DETAIL,
      { type: 'project', identifiers: [PROJECTS[0]!.$id] },
    ],
    createdById: ACCOUNT.$id,
    createdByName: ACCOUNT.name,
    lastAccessedAt: NOW,
  } as Models.AppInstallation
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
  { role = 'owner', failList = false } = {},
) {
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  const updates: string[] = []
  let current = installation()

  await page.context().addCookies([
    {
      name: CONSOLE_SESSION_COOKIE_NAME,
      value: 'mock-session',
      url: localOrigin,
    },
  ])

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
    if (apiPath === '/console/variables') return json(200, {})
    if (apiPath === '/health/version') return json(200, { version: '2.0.0' })
    if (apiPath === `/organizations/${ORGANIZATION.$id}/roles`)
      return json(200, {
        roles: [role],
        scopes: ['projects.read', 'teams.read'],
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
      return json(200, { total: PROJECTS.length, projects: PROJECTS })
    if (apiPath === `/apps/${APP.$id}`) return json(200, APP)

    if (apiPath === `/teams/${ORGANIZATION.$id}/installations`) {
      return failList
        ? json(500, {
            message: 'Server error',
            code: 500,
            type: 'general_server_error',
            version: '1.0',
          })
        : json(200, { total: 1, installations: [current] })
    }
    if (
      apiPath === `/teams/${ORGANIZATION.$id}/installations/${current.$id}` &&
      request.method() === 'PUT'
    ) {
      const details = request.postDataJSON().authorizationDetails as string
      updates.push(details)
      current = { ...current, authorizationDetails: JSON.parse(details) }
      return json(200, current)
    }

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return { updates }
}

const openRowMenu = (page: Page) =>
  page
    .getByRole('button', { name: /actions|more/i })
    .first()
    .click({ timeout: 30_000 })

test.describe('installed apps', () => {
  test('owner edits project access and keeps other grants', async ({
    page,
  }) => {
    const { updates } = await mockAppwriteApi(page)
    await page.goto(
      `/organizations/${ORGANIZATION.$id}/settings/installations`,
      {
        waitUntil: 'domcontentloaded',
      },
    )

    await expect(page.getByText('Pagewise Analytics')).toBeVisible({
      timeout: 30_000,
    })
    await expect(page.getByText('Projects: 1')).toBeVisible()

    await openRowMenu(page)
    await page.getByRole('menuitem', { name: 'Edit access' }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByText('Marketing').click()
    await dialog.getByRole('button', { name: 'Save' }).click()

    await expect(page.getByText('Projects: 2')).toBeVisible()
    expect(JSON.parse(updates[0]!)).toEqual([
      ORGANIZATION_DETAIL,
      { type: 'project', identifiers: [PROJECTS[0]!.$id, PROJECTS[2]!.$id] },
    ])
  })

  test('developer sees installed apps without management actions', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { role: 'developer' })
    await page.goto(
      `/organizations/${ORGANIZATION.$id}/settings/installations`,
      {
        waitUntil: 'domcontentloaded',
      },
    )

    await expect(page.getByText('Pagewise Analytics')).toBeVisible({
      timeout: 30_000,
    })
    await openRowMenu(page)

    await expect(
      page.getByRole('menuitem', { name: 'View in marketplace' }),
    ).toBeVisible()
    await expect(
      page.getByRole('menuitem', { name: 'Edit access' }),
    ).toHaveCount(0)
    await expect(page.getByRole('menuitem', { name: 'Uninstall' })).toHaveCount(
      0,
    )
  })

  test('offers a retry when the list fails to load', async ({ page }) => {
    await mockAppwriteApi(page, { failList: true })
    await page.goto(
      `/organizations/${ORGANIZATION.$id}/settings/installations`,
      {
        waitUntil: 'domcontentloaded',
      },
    )

    await expect(page.getByText('Failed to load installed apps')).toBeVisible({
      timeout: 30_000,
    })
    await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible()
    await expect(page.getByText('No installed apps')).toHaveCount(0)
  })
})
