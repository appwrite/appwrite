import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * The prefilled create-key link `/setup.md` hands to coding agents:
 * `/projects/<id>/api-keys?name=…&scopes=a,b` opens the create drawer filled in.
 *
 * The Appwrite API is mocked at the network layer and the real console pages
 * are asserted.
 */

const NOW = '2026-11-03T09:30:00.000+00:00'

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
  billingPlan: 'tier-0',
  billingEmail: ACCOUNT.email,
  status: 'active',
  prefs: {},
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT_ID = 'proj0000000000000000001'

const PROJECT = {
  $id: PROJECT_ID,
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
} satisfies Partial<Models.Project>

const SCOPE_CATALOG = {
  total: 3,
  scopes: [
    {
      $id: 'sessions.write',
      description:
        "Access to create, update, and delete your project's sessions",
      category: 'Auth',
      deprecated: false,
    },
    {
      $id: 'users.read',
      description: "Access to read your project's users",
      category: 'Auth',
      deprecated: false,
    },
    {
      $id: 'rows.write',
      description: "Access to create, update, and delete your project's rows",
      category: 'Database',
      deprecated: false,
    },
  ],
} satisfies Models.ConsoleKeyScopeList

const SECRET = 'standard_0123456789abcdef'

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

/**
 * Every `/v1` call, on any region host, is answered here; the app itself is
 * served live. `canWriteKeys` decides whether the member's scopes include
 * `keys.write`, and `catalogDelayMs` holds back the scope catalog. Returns the
 * `POST /v1/project/keys` requests.
 */
async function mockAppwriteApi(
  page: Page,
  {
    canWriteKeys,
    catalogDelayMs = 0,
  }: { canWriteKeys: boolean; catalogDelayMs?: number },
): Promise<Request[]> {
  const creates: Request[] = []
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

    if (apiPath === '/project/keys') {
      if (request.method() === 'POST') {
        creates.push(request)
        const body = request.postDataJSON()
        return json(201, {
          $id: body.keyId,
          $createdAt: NOW,
          $updatedAt: NOW,
          name: body.name,
          expire: body.expire ?? '',
          scopes: body.scopes ?? [],
          secret: SECRET,
          accessedAt: '',
          sdks: [],
        })
      }
      return json(200, { total: 0, keys: [] })
    }
    if (apiPath === '/console/scopes/project') {
      await new Promise((resolve) => setTimeout(resolve, catalogDelayMs))
      return json(200, SCOPE_CATALOG)
    }

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') {
      return request.method() === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, ACCOUNT.prefs)
    }
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/account/consents')
      return json(200, { total: 0, consents: [] })
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
    if (apiPath === '/console/plans') return json(200, { total: 0, plans: [] })
    if (apiPath === `/projects/${PROJECT_ID}/stages`)
      return json(200, { total: 0, stages: [] })
    if (apiPath === '/console/variables') return json(200, {})
    if (apiPath === `/organizations/${ORGANIZATION.$id}/roles`)
      return json(200, {
        roles: ['developer'],
        scopes: canWriteKeys
          ? ['projects.read', 'keys.read', 'keys.write']
          : ['projects.read', 'keys.read'],
      })
    if (apiPath === '/organizations' || apiPath === '/teams')
      return json(200, { total: 1, teams: [ORGANIZATION] })
    if (
      apiPath === `/organizations/${ORGANIZATION.$id}` ||
      apiPath === `/teams/${ORGANIZATION.$id}`
    )
      return json(200, ORGANIZATION)
    if (apiPath === '/projects' || apiPath === '/organization/projects')
      return json(200, { total: 1, projects: [PROJECT] })
    if (
      apiPath === '/project' ||
      apiPath === `/projects/${PROJECT_ID}` ||
      apiPath === `/projects/${PROJECT_ID}/console-access`
    )
      return json(200, PROJECT)

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return creates
}

/** Pins the console profile, so the spec never relies on the endpoint's. */
async function useProfile(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: {} }),
    )
  })
}

const PREFILL_PATH = `/projects/${PROJECT_ID}/api-keys?name=Acme%20server&scopes=sessions.write,users.read,not.a.scope`

test.describe('prefilled API key link (mocked API)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await useProfile(page)
  })

  test('opens the create drawer filled in and creates the key with it', async ({
    page,
  }) => {
    // A slow catalog must not let the drawer open with the unknown scope.
    const creates = await mockAppwriteApi(page, {
      canWriteKeys: true,
      catalogDelayMs: 1500,
    })
    await page.goto(PREFILL_PATH, { waitUntil: 'domcontentloaded' })

    const drawer = page.getByRole('dialog')
    await expect(
      drawer.getByRole('heading', { name: 'Create API key' }),
    ).toBeVisible({ timeout: 30_000 })
    await expect(drawer.locator('#name')).toHaveValue('Acme server')

    await drawer.getByRole('button', { name: 'Create API key' }).click()
    await expect.poll(() => creates.length).toBe(1)
    const body = creates[0].postDataJSON()
    expect(body.name).toBe('Acme server')
    expect(body.scopes).toEqual(['sessions.write', 'users.read'])

    await expect(drawer.getByText(SECRET)).toBeVisible()
    await drawer.getByRole('button', { name: 'Done' }).click()
    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT_ID}/api-keys$`),
    )
  })

  test('explains instead of opening the drawer without keys.write', async ({
    page,
  }) => {
    await mockAppwriteApi(page, { canWriteKeys: false })
    await page.goto(PREFILL_PATH, { waitUntil: 'domcontentloaded' })

    await expect(
      page.getByText("You don't have permission to create API keys.").first(),
    ).toBeVisible({ timeout: 30_000 })
    await expect(page.getByRole('dialog')).toHaveCount(0)
  })
})
