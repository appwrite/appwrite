import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * New projects start with a web app for any hostname (`*`), and the app lists
 * flag it with the recommended next step. The Appwrite API is mocked at the
 * network layer and the real console pages are asserted.
 */

const NOW = '2026-11-11T09:30:00.000+00:00'

const WARNING =
  "Any website can send requests to this project. Before going live, replace * with your app's domain, like app.example.com."

const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'platforms.write',
  'keys.write',
  'teams.read',
  'teams.write',
] satisfies Models.Roles['scopes']

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
  name: 'Acme Docs',
  billingPlan: 'tier-0',
  billingEmail: ACCOUNT.email,
  status: 'active',
  prefs: {},
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT_ID = 'proj0000000000000000001'

function project($id: string, name = 'Acme Docs') {
  return {
    $id,
    $createdAt: NOW,
    $updatedAt: NOW,
    name,
    teamId: ORGANIZATION.$id,
    region: 'default',
    status: 'active',
    services: [],
    authMethods: [],
  } satisfies Partial<Models.Project>
}

function webPlatform(hostname: string, name = hostname) {
  return {
    $id: `web-${hostname === '*' ? 'any' : hostname}`,
    $createdAt: NOW,
    $updatedAt: NOW,
    name,
    type: 'web',
    hostname,
  }
}

type Platform = ReturnType<typeof webPlatform>

type Calls = {
  /** Platforms by project, editable mid-test. */
  platforms: Map<string, Platform[]>
  projectCreates: Request[]
  platformCreates: Request[]
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

/** Every `/v1` call, on any region host, is answered here; the app itself is served live. */
async function mockAppwriteApi(
  page: Page,
  platforms: Platform[] = [],
): Promise<Calls> {
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  const projects = [project(PROJECT_ID)]
  const calls: Calls = {
    platforms: new Map([[PROJECT_ID, [...platforms]]]),
    projectCreates: [],
    platformCreates: [],
  }

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
    const projectId = request.headers()['x-appwrite-project'] ?? ''

    if (apiPath === '/organization/projects' && request.method() === 'POST') {
      calls.projectCreates.push(request)
      const body = request.postDataJSON()
      const created = project(body.projectId, body.name)
      projects.push(created)
      calls.platforms.set(created.$id, [])
      return json(201, created)
    }
    if (apiPath === '/project/platforms/web' && request.method() === 'POST') {
      calls.platformCreates.push(request)
      const body = request.postDataJSON()
      const created = {
        ...webPlatform(body.hostname, body.name),
        $id: body.platformId,
      }
      calls.platforms.get(projectId)?.push(created)
      return json(201, created)
    }

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') {
      return request.method() === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, ACCOUNT.prefs)
    }
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
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
      return json(200, { total: projects.length, projects })
    const known = projects.find(
      (p) =>
        apiPath === `/projects/${p.$id}` ||
        apiPath === `/organization/projects/${p.$id}` ||
        apiPath === `/projects/${p.$id}/console-access` ||
        (apiPath === '/project' && projectId === p.$id),
    )
    if (known) return json(200, known)
    if (apiPath === '/project/platforms') {
      const list = calls.platforms.get(projectId) ?? []
      return json(200, { total: list.length, platforms: list })
    }
    const platform = (calls.platforms.get(projectId) ?? []).find(
      (p) => apiPath === `/project/platforms/${p.$id}`,
    )
    if (platform) return json(200, platform)

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return calls
}

/** Self-hosted keeps billing and regions out of the create dialog. */
async function useSelfHosted(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'self-hosted', features: {} }),
    )
  })
}

test.describe('wildcard web platform (mocked API)', () => {
  test('a new project starts with a web app for any hostname', async ({
    page,
  }) => {
    await useSelfHosted(page)
    const calls = await mockAppwriteApi(page)

    await page.goto(`/organizations/${ORGANIZATION.$id}?create=project`, {
      waitUntil: 'domcontentloaded',
    })
    const dialog = page.getByRole('dialog', { name: 'Create project' })
    await expect(dialog).toBeVisible({ timeout: 60_000 })
    await dialog.locator('#name').fill('Launch')
    await dialog.getByRole('button', { name: 'Create', exact: true }).click()

    await expect.poll(() => calls.platformCreates.length).toBe(1)
    const created = calls.projectCreates[0].postDataJSON()
    const platform = calls.platformCreates[0]
    expect(platform.headers()['x-appwrite-project']).toBe(created.projectId)
    expect(platform.postDataJSON()).toMatchObject({
      name: 'Web app',
      hostname: '*',
    })

    // The new project's overview lists it with the warning.
    await expect(page).toHaveURL(new RegExp(`/projects/${created.projectId}`), {
      timeout: 60_000,
    })
    const warning = page.getByTestId('wildcard-platform-warning')
    await expect(warning).toHaveCount(1, { timeout: 60_000 })
    await warning.hover()
    await expect(page.getByRole('tooltip')).toContainText(WARNING)
  })

  test('the apps list flags only the wildcard app', async ({ page }) => {
    await useSelfHosted(page)
    await mockAppwriteApi(page, [
      webPlatform('*', 'Web app'),
      webPlatform('app.example.com'),
      webPlatform('*.example.com'),
    ])

    await page.goto(`/projects/${PROJECT_ID}/apps`, {
      waitUntil: 'domcontentloaded',
    })
    const wildcard = page.getByRole('button', { name: /^Web app/ })
    await expect(wildcard).toBeVisible({ timeout: 60_000 })
    await expect(
      page.getByRole('button', { name: /^(app|\*)\.example\.com/ }),
    ).toHaveCount(2)
    const warning = page.getByTestId('wildcard-platform-warning')
    await expect(warning).toHaveCount(1)
    await expect(wildcard.getByTestId('wildcard-platform-warning')).toHaveCount(
      1,
    )
    await expect(warning).toHaveAccessibleName(WARNING)

    // The drawer repeats it under the hostname until a domain replaces `*`.
    await wildcard.click()
    const hostname = page.locator('#platform-hostname')
    await expect(hostname).toHaveValue('*')
    const note = page.getByRole('dialog').getByText(WARNING)
    await expect(note).toBeVisible()
    await hostname.fill('app.example.com')
    await expect(note).toHaveCount(0)
  })
})
