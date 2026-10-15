import type { Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'
import { waitForFullscreenLoaderHidden } from './helpers/fullscreen-loader'
import { openSelectAndChoose } from './helpers/ui'

/**
 * The periodic password change policy card on Auth > Policies > Passwords: when
 * it is shown at all, what it sends, and what it refuses to send.
 *
 * The Appwrite API is mocked at the network layer and the real console page is
 * asserted, so no backend is needed.
 */

const NOW = '2026-09-09T09:30:00.000+00:00'

/** Mirrors `ProjectPolicyId.Passwordrotation`. */
const ROTATION_ID = 'password-rotation'

const ROTATION_POLICY_PATH = `/project/policies/${ROTATION_ID}`

/** The API's default maximum password age: 1 year. */
const ONE_YEAR = 31_536_000
const THREE_MONTHS = 7_776_000

const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'users.write',
  'teams.read',
  'billing.read',
] satisfies Models.Roles['scopes']
const ALL_SERVICES = [
  'account',
  'avatars',
  'databases',
  'locale',
  'health',
  'project',
  'storage',
  'teams',
  'users',
] as const
const AUTH_METHODS = ['email-password', 'magic-url', 'email-otp'] as const

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
  services: ALL_SERVICES.map(($id) => ({
    $id,
    enabled: true,
  })) as Models.Project['services'],
  authMethods: AUTH_METHODS.map(($id) => ({
    $id,
    enabled: true,
  })) as Models.Project['authMethods'],
} satisfies Partial<Models.Project>

type RotationPolicy = { enabled: boolean; maxAge: number }

type MockOptions = {
  /**
   * The stored rotation policy, or null for a server that reports none, which
   * is every self-hosted install from before the policy existed.
   */
  rotation?: RotationPolicy | null
  /** When set, the first policy PATCH is refused with this JSON body. */
  patchError?: { message: string; code: number; type: string }
}

type Calls = {
  /** The stored policy, as the mock keeps it between requests. */
  rotation: RotationPolicy | null
  patches: Request[]
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

/** Every `/v1` call, on any region host, is answered here; the app itself is served live. */
async function mockAppwriteApi(
  page: Page,
  options: MockOptions = {},
): Promise<Calls> {
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  const calls: Calls = {
    rotation:
      options.rotation === undefined
        ? { enabled: false, maxAge: ONE_YEAR }
        : options.rotation,
    patches: [],
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

    if (apiPath === ROTATION_POLICY_PATH && request.method() === 'PATCH') {
      calls.patches.push(request)
      if (options.patchError && calls.patches.length === 1) {
        return json(400, options.patchError)
      }
      // Like the server: options left out keep their current value, which is
      // how the stored maximum age survives the policy being turned off.
      const body = request.postDataJSON() ?? {}
      calls.rotation = {
        enabled: body.enabled ?? calls.rotation?.enabled ?? false,
        maxAge: body.maxAge ?? calls.rotation?.maxAge ?? ONE_YEAR,
      }
      return json(200, PROJECT)
    }

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
    // `fetchProjectAuthSecurity` swallows failures on both of these, so a
    // mis-pathed mock would read back as the default snapshot, not as an error.
    if (apiPath === '/project/policies')
      return json(200, {
        total: calls.rotation ? 2 : 1,
        policies: [
          // Always present, so the page has a card to render either way.
          {
            $id: 'password-strength',
            min: 8,
            uppercase: false,
            lowercase: false,
            number: false,
            symbols: false,
          },
          ...(calls.rotation ? [{ $id: ROTATION_ID, ...calls.rotation }] : []),
        ],
      })
    if (apiPath === '/project/platforms')
      return json(200, { total: 0, platforms: [] })
    if (apiPath === '/project/mock-phones')
      return json(200, { total: 0, mockNumbers: [] })

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return calls
}

function card(page: Page) {
  return page.getByTestId('password-rotation-card')
}

function toggle(page: Page) {
  return page.locator('#password-rotation-enabled')
}

function maxAgeInput(page: Page) {
  return page.locator('#password-rotation-max-age')
}

function unitTrigger(page: Page) {
  return page.locator('#password-rotation-unit')
}

function updateButton(page: Page) {
  return card(page).getByRole('button', { name: 'Update', exact: true })
}

async function openPasswordPolicies(page: Page) {
  await page.goto(`/projects/${PROJECT_ID}/auth/policies/passwords`, {
    waitUntil: 'domcontentloaded',
  })
  await waitForFullscreenLoaderHidden(page)
  // The strength card is in the list whatever the server reports, so it is the
  // anchor that says the page itself finished rendering.
  await expect(
    page.getByRole('heading', { name: 'Strength', exact: true }),
  ).toBeVisible({ timeout: 30_000 })
}

function bodies(requests: Request[]): unknown[] {
  return requests.map((request) => request.postDataJSON())
}

test.describe('periodic password change policy (mocked API)', () => {
  test('reads the stored policy and saves a maximum age', async ({ page }) => {
    const calls = await mockAppwriteApi(page, {
      rotation: { enabled: false, maxAge: ONE_YEAR },
    })
    await openPasswordPolicies(page)

    // The card reads back the stored policy: off, with 1 year shown as a pair.
    await expect(
      page.getByRole('heading', {
        name: 'Periodic password change',
        exact: true,
      }),
    ).toBeVisible()
    await expect(toggle(page)).not.toBeChecked()
    await expect(maxAgeInput(page)).toHaveValue('1')
    await expect(unitTrigger(page)).toContainText('Years')
    // The maximum age only applies while the policy is on.
    await expect(maxAgeInput(page)).toBeDisabled()
    await expect(updateButton(page)).toBeDisabled()
    await expect(card(page)).toContainText('Between 1 day and 10 years')

    // Turning it on sends both options.
    await toggle(page).click()
    await expect(maxAgeInput(page)).toBeEnabled()
    await expect(updateButton(page)).toBeEnabled()
    await updateButton(page).click()
    await expect(
      page.getByText('Updated periodic password change policy', {
        exact: true,
      }),
    ).toBeVisible()
    expect(bodies(calls.patches)).toEqual([{ enabled: true, maxAge: ONE_YEAR }])
    await expect(toggle(page)).toBeChecked()
    await expect(updateButton(page)).toBeDisabled()

    // A new maximum age is sent in seconds, not in the unit on screen.
    await openSelectAndChoose(page, unitTrigger(page), 'Days')
    await maxAgeInput(page).fill('90')
    await expect(updateButton(page)).toBeEnabled()
    await updateButton(page).click()
    await expect.poll(() => calls.patches.length).toBe(2)
    expect(calls.patches[1].postDataJSON()).toEqual({
      enabled: true,
      maxAge: THREE_MONTHS,
    })
    await expect(updateButton(page)).toBeDisabled()
  })

  test('is absent on a server that reports no rotation policy', async ({
    page,
  }) => {
    // The older-server path. `listPolicies` has no `password-rotation` entry, so
    // the card is hidden rather than offering defaults the server cannot save.
    const calls = await mockAppwriteApi(page, { rotation: null })
    await openPasswordPolicies(page)

    await expect(card(page)).toHaveCount(0)
    await expect(
      page.getByRole('heading', {
        name: 'Periodic password change',
        exact: true,
      }),
    ).toHaveCount(0)
    await expect(toggle(page)).toHaveCount(0)
    // Nothing on the page probes the endpoint behind the card's back.
    await page.waitForLoadState('networkidle')
    expect(calls.patches).toHaveLength(0)
  })

  test('refuses a maximum age outside the range the API accepts', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page, {
      rotation: { enabled: false, maxAge: ONE_YEAR },
    })
    await openPasswordPolicies(page)

    // Turning the policy on is a change, so only the range can disable Update.
    await toggle(page).click()
    await expect(updateButton(page)).toBeEnabled()

    await maxAgeInput(page).fill('11')
    await expect(card(page)).toContainText('Maximum is 10 years')
    await expect(updateButton(page)).toBeDisabled()

    await maxAgeInput(page).fill('0')
    await expect(card(page)).toContainText('Minimum is 1 day')
    await expect(updateButton(page)).toBeDisabled()

    // A day is the smallest age the API takes, and it is accepted.
    await openSelectAndChoose(page, unitTrigger(page), 'Days')
    await maxAgeInput(page).fill('1')
    await expect(card(page)).toContainText('Between 1 day and 10 years')
    await expect(updateButton(page)).toBeEnabled()
    expect(calls.patches).toHaveLength(0)
  })

  test('leaves the maximum age out when the policy is turned off', async ({
    page,
  }) => {
    const calls = await mockAppwriteApi(page, {
      rotation: { enabled: true, maxAge: THREE_MONTHS },
    })
    await openPasswordPolicies(page)

    await expect(toggle(page)).toBeChecked()
    await expect(maxAgeInput(page)).toHaveValue('3')
    await expect(unitTrigger(page)).toContainText('Months')

    await toggle(page).click()
    await expect(maxAgeInput(page)).toBeDisabled()
    await expect(updateButton(page)).toBeEnabled()
    await updateButton(page).click()
    await expect.poll(() => calls.patches.length).toBe(1)

    // `maxAge` is absent, so the server keeps the stored 3 months for the next
    // time the policy is on.
    expect(calls.patches[0].postDataJSON()).toEqual({ enabled: false })
    await expect(toggle(page)).not.toBeChecked()
    expect(calls.rotation).toEqual({ enabled: false, maxAge: THREE_MONTHS })

    // Turning it back on offers the age the server kept, not a default.
    await expect(maxAgeInput(page)).toHaveValue('3')
    await toggle(page).click()
    await expect(updateButton(page)).toBeEnabled()
    await updateButton(page).click()
    await expect.poll(() => calls.patches.length).toBe(2)
    expect(calls.patches[1].postDataJSON()).toEqual({
      enabled: true,
      maxAge: THREE_MONTHS,
    })
  })

  test('changing only the time period is not an edit', async ({ page }) => {
    // Two decimals cannot hold 1 year in months or weeks, and the card used to
    // convert the number on screen rather than the stored age: every switch
    // drifted it by hours, lit up Update, and offered to save the drift.
    const calls = await mockAppwriteApi(page, {
      rotation: { enabled: true, maxAge: ONE_YEAR },
    })
    await openPasswordPolicies(page)

    await expect(maxAgeInput(page)).toHaveValue('1')
    await expect(updateButton(page)).toBeDisabled()

    // Each unit writes the stored year, and going round twice does not drift it.
    for (const [unit, value] of [
      ['Months', '12.17'],
      ['Weeks', '52.14'],
      ['Days', '365'],
      ['Years', '1'],
      ['Weeks', '52.14'],
      ['Months', '12.17'],
      ['Days', '365'],
      ['Years', '1'],
    ] as const) {
      await openSelectAndChoose(page, unitTrigger(page), unit)
      await expect(unitTrigger(page)).toContainText(unit)
      await expect(maxAgeInput(page)).toHaveValue(value)
      await expect(updateButton(page)).toBeDisabled()
    }
    expect(calls.patches).toHaveLength(0)

    // A real edit still enables Update, and saves the age that was typed.
    await maxAgeInput(page).fill('2')
    await expect(updateButton(page)).toBeEnabled()
    await updateButton(page).click()
    await expect.poll(() => calls.patches.length).toBe(1)
    expect(calls.patches[0].postDataJSON()).toEqual({
      enabled: true,
      maxAge: ONE_YEAR * 2,
    })
  })

  test('keeps the edit when the server refuses the save', async ({ page }) => {
    const message = 'Maximum password age must be between 1 day and 10 years'
    const calls = await mockAppwriteApi(page, {
      rotation: { enabled: false, maxAge: ONE_YEAR },
      patchError: { message, code: 400, type: 'general_argument_invalid' },
    })
    await openPasswordPolicies(page)

    await toggle(page).click()
    await openSelectAndChoose(page, unitTrigger(page), 'Days')
    await maxAgeInput(page).fill('90')
    await updateButton(page).click()

    // The refusal is shown as written and the edit stays on screen.
    await expect(page.getByText(message)).toBeVisible()
    await expect(toggle(page)).toBeChecked()
    await expect(maxAgeInput(page)).toHaveValue('90')
    await expect(unitTrigger(page)).toContainText('Days')

    // A retry saves it.
    await updateButton(page).click()
    await expect.poll(() => calls.patches.length).toBe(2)
    expect(bodies(calls.patches)).toEqual([
      { enabled: true, maxAge: THREE_MONTHS },
      { enabled: true, maxAge: THREE_MONTHS },
    ])
    await expect(updateButton(page)).toBeDisabled()
  })
})
