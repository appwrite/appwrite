import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * The deny realtime guests policy under Auth → Policies → Realtime.
 *
 * The Appwrite API is mocked at the network layer and the real console pages are
 * asserted.
 */

const NOW = '2026-10-28T09:30:00.000+00:00'

/** Mirrors `ProjectPolicyId.Denyrealtimeguests`. */
const POLICY_ID = 'deny-realtime-guests'
const POLICY_PATH = `/project/policies/${POLICY_ID}`

const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'users.write',
  'teams.read',
  'teams.write',
  'billing.read',
] satisfies Models.Roles['scopes']
const SERVICES = ['account', 'project', 'teams', 'users'] as const

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

const PROJECT = {
  $id: PROJECT_ID,
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme Docs',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
  // The SDK types service ids as enums, so the literals need the cast.
  services: SERVICES.map(($id) => ({
    $id,
    enabled: true,
  })) as Models.Project['services'],
  authMethods: [] as Models.Project['authMethods'],
} satisfies Partial<Models.Project>

type MockOptions = {
  /** Whether the policy starts on. */
  enabled?: boolean
  /** When set, the first PATCH is refused with this JSON body. */
  patchError?: { message: string; code: number; type: string }
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
  options: MockOptions = {},
): Promise<Request[]> {
  let enabled = options.enabled ?? false
  const patches: Request[] = []
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

    if (apiPath === POLICY_PATH && request.method() === 'PATCH') {
      patches.push(request)
      if (options.patchError && patches.length === 1) {
        return json(400, options.patchError)
      }
      enabled = request.postDataJSON().enabled === true
      return json(200, PROJECT)
    }
    // `fetchProjectAuthSecurity` swallows failures here, so a mis-pathed mock
    // would read back as the default snapshot, not as an error.
    if (apiPath === '/project/policies')
      return json(200, {
        total: 1,
        policies: [{ $id: POLICY_ID, enabled }],
      })

    if (apiPath === '/account') return json(200, ACCOUNT)
    // GET returns the prefs, PATCH the updated user.
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
    if (apiPath === '/locale/codes')
      return json(200, { total: 0, localeCodes: [] })
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
      apiPath === `/projects/${PROJECT_ID}` ||
      apiPath === `/projects/${PROJECT_ID}/console-access`
    )
      return json(200, PROJECT)
    if (apiPath === '/project/mock-phones')
      return json(200, { total: 0, mockNumbers: [] })

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return patches
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

function guestsSwitch(page: Page) {
  return page.locator('#deny-realtime-guests-enabled')
}

function updateButton(page: Page) {
  return page.getByRole('button', { name: 'Update', exact: true })
}

async function openRealtimePolicies(page: Page) {
  await page.goto(`/projects/${PROJECT_ID}/auth/policies/realtime`, {
    waitUntil: 'domcontentloaded',
  })
  await expect(
    page.getByRole('heading', { name: 'Guests', exact: true }),
  ).toBeVisible({ timeout: 30_000 })
}

async function attachScreenshot(page: Page, name: string) {
  await test.info().attach(name, {
    body: await page.screenshot({ animations: 'disabled' }),
    contentType: 'image/png',
  })
}

test.describe('realtime guests policy (mocked API)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await useProfile(page)
  })

  test('turns the policy on and off', async ({ page }) => {
    const patches = await mockAppwriteApi(page)
    await openRealtimePolicies(page)

    await expect(
      page
        .getByRole('navigation', { name: 'Policies navigation' })
        .getByRole('link', { name: 'Realtime', exact: true }),
    ).toBeVisible()
    await expect(guestsSwitch(page)).not.toBeChecked()
    await expect(updateButton(page)).toBeDisabled()

    await guestsSwitch(page).click()
    await expect(updateButton(page)).toBeEnabled()
    await updateButton(page).click()
    await expect(
      page.getByText('Updated deny realtime guests policy', { exact: true }),
    ).toBeVisible()
    await expect(guestsSwitch(page)).toBeChecked()
    await expect(updateButton(page)).toBeDisabled()
    await attachScreenshot(page, 'policy-on')

    await guestsSwitch(page).click()
    await updateButton(page).click()
    await expect(guestsSwitch(page)).not.toBeChecked()
    await expect.poll(() => patches.length).toBe(2)
    await expect(updateButton(page)).toBeDisabled()
    await attachScreenshot(page, 'policy-off')
    expect(patches.map((patch) => patch.postDataJSON())).toEqual([
      { enabled: true },
      { enabled: false },
    ])
  })

  test('reads back a policy that is on', async ({ page }) => {
    await mockAppwriteApi(page, { enabled: true })
    await openRealtimePolicies(page)

    await expect(guestsSwitch(page)).toBeChecked()
    await expect(updateButton(page)).toBeDisabled()
  })

  test('shows a refused update and the stored value', async ({ page }) => {
    const message =
      'The current user is not authorized to perform the requested action.'
    const patches = await mockAppwriteApi(page, {
      patchError: { message, code: 401, type: 'general_unauthorized_scope' },
    })
    await openRealtimePolicies(page)

    await guestsSwitch(page).click()
    await updateButton(page).click()
    await expect(page.getByText(message)).toBeVisible()
    await attachScreenshot(page, 'policy-error')
    // The card goes back to what is stored, as the email policies do.
    await expect(guestsSwitch(page)).not.toBeChecked()
    await expect(updateButton(page)).toBeDisabled()

    await guestsSwitch(page).click()
    await updateButton(page).click()
    await expect(
      page.getByText('Updated deny realtime guests policy', { exact: true }),
    ).toBeVisible()
    expect(patches).toHaveLength(2)
  })

  test('is found from policy search', async ({ page }) => {
    await mockAppwriteApi(page)
    await page.goto(`/projects/${PROJECT_ID}/auth/policies/sessions`, {
      waitUntil: 'domcontentloaded',
    })
    const search = page
      .getByPlaceholder('Search policies...')
      .filter({ visible: true })
    await expect(search).toBeVisible({ timeout: 30_000 })

    await search.fill('unauthenticated')
    await expect(
      page.getByRole('heading', { name: 'Guests', exact: true }),
    ).toBeVisible()
  })
})
