import type { Page } from '@playwright/test'
import { expect, test } from './fixtures'

const NOW = '2026-09-08T00:00:00.000Z'
const ORGANIZATION = {
  $id: 'existing-org',
  name: 'Existing organization',
  total: 1,
  prefs: {},
}
const ACCOUNT = {
  $id: 'uninvited-user',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'New member',
  email: 'member@example.com',
  registration: NOW,
  status: true,
  labels: [],
  passwordUpdate: NOW,
  phone: '',
  emailVerification: true,
  phoneVerification: false,
  mfa: false,
  prefs: {},
  targets: [],
  accessedAt: NOW,
}

async function mockApi(
  page: Page,
  options: {
    profile?: 'cloud' | 'self-hosted'
    member?: boolean
    listError?: boolean
    creationError?: string
  } = {},
) {
  await page.addInitScript((profile) => {
    localStorage.setItem('screenshot:modeOpen', 'false')
    localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: profile, features: {} }),
    )
  }, options.profile ?? 'self-hosted')

  const state = {
    authenticated: true,
    creates: 0,
    projects: 0,
    lists: 0,
    prefs: {} as Record<string, unknown>,
  }
  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const origin = new URL(String(test.info().project.use.baseURL)).origin
    const path = url.pathname.match(/^\/v1(\/.*)$/)?.[1]
    if (url.origin === origin && !path) return route.continue()

    const headers = {
      'access-control-allow-origin': request.headers().origin ?? '*',
      'access-control-allow-credentials': 'true',
      'access-control-allow-methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
      'access-control-allow-headers':
        request.headers()['access-control-request-headers'] ?? '*',
    }
    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })
    const failure = (code: number, type: string) =>
      json(code, { code, type, message: type, version: '1.0' })

    // Never send requests to a real API or other external services.
    if (!path || request.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers })
    }
    if (path === '/account/sessions/email' && request.method() === 'POST') {
      state.authenticated = true
      await page
        .context()
        .addCookies([
          { name: 'a_session_console', value: 'mock-session', url: origin },
        ])
      return json(201, { $id: 'session', userId: ACCOUNT.$id })
    }
    if (path === '/account') {
      return state.authenticated
        ? json(200, { ...ACCOUNT, prefs: state.prefs })
        : failure(401, 'user_unauthorized')
    }
    if (path === '/account/prefs') {
      if (request.method() === 'PATCH') {
        state.prefs = request.postDataJSON().prefs
        return json(200, { ...ACCOUNT, prefs: state.prefs })
      }
      return json(200, state.prefs)
    }
    if (path === '/teams' || path === '/organizations') {
      if (request.method() === 'POST') {
        state.creates++
        return failure(
          403,
          options.creationError ?? 'organization_creation_prohibited',
        )
      }
      state.lists++
      if (options.listError) return failure(503, 'general_server_error')
      const teams = options.member ? [ORGANIZATION] : []
      return json(200, { total: teams.length, teams })
    }
    if (
      path === '/teams/existing-org' ||
      path === '/organizations/existing-org'
    ) {
      return json(200, ORGANIZATION)
    }
    if (path.endsWith('/projects')) {
      if (request.method() === 'POST') state.projects++
      const projects = options.member
        ? [
            {
              $id: 'existing-project',
              name: 'Existing project',
              teamId: ORGANIZATION.$id,
              region: 'default',
            },
          ]
        : []
      return json(200, { total: projects.length, projects })
    }
    if (path === '/account/sessions') {
      return json(200, { total: 0, sessions: [] })
    }
    return failure(404, 'general_route_not_found')
  })
  return state
}

async function signIn(page: Page) {
  await page.goto('/sign-in')
  await page.locator('input[type="email"]').fill(ACCOUNT.email)
  await page.locator('input[type="password"]').fill('Example-password-123!')
  await page.getByRole('button', { name: 'Login', exact: true }).click()
  await expect(page).toHaveURL(/\/account\/?$/)
}

test('policy denial is not retried during sign-in and invitation guidance survives reload', async ({
  page,
}) => {
  const state = await mockApi(page)
  state.authenticated = false
  await signIn(page)
  const guidance = page
    .getByRole('status')
    .filter({ hasText: 'Join an organization' })
  await expect(guidance).toContainText(
    'Ask an organization owner to invite you',
  )
  expect(state.creates).toBe(1)
  expect(state.projects).toBe(0)
  await page.reload()
  await expect(guidance).toBeVisible()
})

test('membership granted after a denial is rechecked without creating another project', async ({
  page,
}) => {
  const options = { member: false }
  const state = await mockApi(page, options)
  state.authenticated = false
  await signIn(page)
  await expect(
    page.getByRole('heading', { name: 'Join an organization' }),
  ).toBeVisible()

  // Keep the same page runtime (and its remembered denial). An invitation has
  // now been accepted, so the next console navigation must recheck membership.
  options.member = true
  // A still-finishing post-auth lookup may already have set the preferred org.
  // Follow the console logo whether it points at / or that organization.
  await page
    .locator('header')
    .getByRole('link', { name: 'Appwrite', exact: true })
    .click()
  await expect(page).toHaveURL(/\/organizations\/existing-org\/?$/)
  await expect(
    page.getByText('Existing project', { exact: true }).first(),
  ).toBeVisible()
  expect(state.creates).toBe(1)
  expect(state.projects).toBe(0)
})

test('other permission failures are not remembered as organization policy denials', async ({
  page,
}) => {
  const state = await mockApi(page, {
    creationError: 'general_unauthorized_scope',
  })
  state.authenticated = false
  await signIn(page)
  await expect.poll(() => state.creates).toBeGreaterThan(1)
  expect(state.projects).toBe(0)
})

for (const scenario of [
  { name: 'existing members', options: { member: true } },
  { name: 'Cloud accounts', options: { profile: 'cloud' as const } },
  { name: 'failed organization lookup', options: { listError: true } },
]) {
  test(`invitation guidance is not shown for ${scenario.name}`, async ({
    page,
  }) => {
    const state = await mockApi(page, scenario.options)
    await page.goto('/account')
    await expect(page.getByTestId('account-logout')).toBeVisible()
    if (scenario.options.profile !== 'cloud') {
      await expect.poll(() => state.lists).toBeGreaterThan(0)
    }
    await expect(
      page.getByRole('heading', { name: 'Join an organization' }),
    ).toHaveCount(0)
    expect(state.creates).toBe(0)
  })
}
