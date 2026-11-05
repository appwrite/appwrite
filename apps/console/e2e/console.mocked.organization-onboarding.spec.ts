import type { Page } from '@playwright/test'
import { expect, test } from './fixtures'

const ORGANIZATION = {
  $id: 'existing-org',
  name: 'Existing organization',
  total: 1,
  prefs: {},
}
async function mockApi(
  page: Page,
  options: {
    profile?: 'cloud' | 'self-hosted'
    member?: boolean
    listError?: boolean
  } = {},
) {
  await page.addInitScript((profile) => {
    localStorage.setItem('screenshot:modeOpen', 'false')
    localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: profile, features: {} }),
    )
  }, options.profile ?? 'self-hosted')

  const origin = new URL(String(test.info().project.use.baseURL)).origin
  await page
    .context()
    .addCookies([
      { name: 'a_session_console', value: 'mock-session', url: origin },
    ])
  const account = {
    $id: 'uninvited-user',
    name: 'New member',
    email: 'member@example.com',
    emailVerification: true,
    prefs: {} as Record<string, unknown>,
  }
  const projects = [
    {
      $id: 'existing-project',
      name: 'Existing project',
      teamId: ORGANIZATION.$id,
      region: 'default',
    },
  ]
  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
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
    if (path === '/account') return json(200, account)
    if (path === '/account/prefs') {
      if (request.method() === 'PATCH') {
        account.prefs = request.postDataJSON().prefs
        return json(200, account)
      }
      return json(200, account.prefs)
    }
    if (path === '/teams' || path === '/organizations') {
      if (request.method() === 'POST') {
        return failure(403, 'organization_creation_prohibited')
      }
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
      if (request.method() === 'POST') {
        if (!options.member) return failure(403, 'general_unauthorized_scope')
        const body = request.postDataJSON()
        const project = {
          $id: body.projectId,
          name: body.name,
          teamId: body.teamId,
          region: 'default',
        }
        projects.push(project)
        return json(201, project)
      }
      const visible = options.member ? projects : []
      return json(200, { total: visible.length, projects: visible })
    }
    if (path === '/account/sessions') {
      return json(200, { total: 0, sessions: [] })
    }
    return failure(404, 'general_route_not_found')
  })
}

test('invitation guidance after a policy denial survives reload', async ({
  page,
}) => {
  await mockApi(page)
  await page.goto('/')
  await expect(page).toHaveURL(/\/account\/?$/)
  const guidance = page
    .getByRole('status')
    .filter({ hasText: 'Join an organization' })
  await expect(guidance).toContainText(
    'Ask an organization owner to invite you',
  )
  await page.reload()
  await expect(guidance).toBeVisible()
})

test('membership granted after a denial is rechecked without creating another project', async ({
  page,
}) => {
  const options = { member: false }
  await mockApi(page, options)
  await page.goto('/')
  await expect(page).toHaveURL(/\/account\/?$/)
  await expect(
    page.getByRole('heading', { name: 'Join an organization' }),
  ).toBeVisible()

  // Accept an invitation without clearing the page's remembered denial.
  options.member = true
  await page
    .locator('header')
    .getByRole('link', { name: 'Appwrite', exact: true })
    .click()
  await expect(page).toHaveURL(/\/organizations\/existing-org\/?$/)
  const project = page.getByText('Existing project', { exact: true }).first()
  await expect(project).toBeVisible()
  // Reload so cached list data cannot hide an accidentally created project.
  await page.reload()
  await expect(project).toBeVisible()
  await expect(page.getByText('My first project', { exact: true })).toHaveCount(
    0,
  )
})

for (const scenario of [
  { name: 'existing members', options: { member: true } },
  { name: 'Cloud accounts', options: { profile: 'cloud' as const } },
  { name: 'failed organization lookup', options: { listError: true } },
]) {
  test(`invitation guidance is not shown for ${scenario.name}`, async ({
    page,
  }) => {
    await mockApi(page, scenario.options)
    const organizationsLoaded =
      scenario.options.profile !== 'cloud'
        ? page.waitForResponse(
            (response) =>
              /\/v1\/(teams|organizations)$/.test(
                new URL(response.url()).pathname,
              ) && response.request().method() === 'GET',
          )
        : undefined
    await page.goto('/account')
    await organizationsLoaded
    await expect(page.getByTestId('account-logout')).toBeVisible()
    await expect(
      page.getByRole('heading', { name: 'Join an organization' }),
    ).toHaveCount(0)
  })
}
