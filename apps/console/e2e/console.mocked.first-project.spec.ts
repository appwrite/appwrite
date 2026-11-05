import type { Page } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'
import { CONSOLE_SESSION_COOKIE_NAME } from '../src/lib/console-session-cookie'

const NOW = '2026-11-04T00:00:00.000Z'
const ORGANIZATION_ID = 'personal000000000001'
const PROJECT_ID = 'firstproject00000001'

function region($id: string, name: string, flag: string): Models.ConsoleRegion {
  return {
    $id,
    name,
    flag,
    available: true,
    disabled: false,
    default: $id === 'fra',
  }
}

const REGIONS = [
  region('fra', 'Frankfurt', 'de'),
  region('nyc', 'New York', 'us'),
  region('sfo', 'San Francisco', 'us'),
  region('sgp', 'Singapore', 'sg'),
  region('syd', 'Sydney', 'au'),
  region('tor', 'Toronto', 'ca'),
]

// A visitor on the US east coast: locale.get() and the browser agree.
test.use({ timezoneId: 'America/New_York', locale: 'en-US' })

/** Signs up a new Cloud account. Every request is answered here; none leave the browser. */
async function mockApi(page: Page) {
  await page.addInitScript(() => {
    localStorage.setItem('screenshot:modeOpen', 'false')
    localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: {} }),
    )
  })

  const baseURL = String(test.info().project.use.baseURL)
  const origin = new URL(baseURL).origin
  let account: Models.User<Models.Preferences> | null = null
  const organizations: Record<string, unknown>[] = []
  const projects: Record<string, unknown>[] = []
  const createdProjects: { name: string; region?: string }[] = []

  await page.route('**/*', async (route) => {
    const request = route.request()
    const method = request.method()
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

    if (!path || method === 'OPTIONS') {
      return route.fulfill({ status: 204, headers })
    }

    if (path === '/account' && method === 'POST') {
      const body = request.postDataJSON()
      account = {
        $id: 'newdeveloper00000001',
        $createdAt: NOW,
        $updatedAt: NOW,
        name: body.name,
        email: body.email,
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
      return json(201, account)
    }
    if (path === '/account/sessions/email') {
      // The root route reads this hint before account.get.
      await page.context().addCookies([
        {
          name: CONSOLE_SESSION_COOKIE_NAME,
          value: 'mock-session',
          url: baseURL,
        },
      ])
      return json(201, { $id: 'session0000000000001', userId: account?.$id })
    }
    if (path === '/account') {
      return account
        ? json(200, account)
        : failure(401, 'general_unauthorized_scope')
    }
    if (path === '/account/prefs' && account) {
      if (method === 'PATCH') {
        account = { ...account, prefs: request.postDataJSON().prefs }
        return json(200, account)
      }
      return json(200, account.prefs)
    }
    if (path === '/account/sessions') {
      return json(200, { total: 0, sessions: [] })
    }
    if (path === '/locale') {
      return json(200, {
        ip: '203.0.113.10',
        countryCode: 'US',
        country: 'United States',
        continentCode: 'NA',
        continent: 'North America',
        eu: false,
        currency: 'USD',
      })
    }
    if (path === '/console/regions') {
      return json(200, { total: REGIONS.length, regions: REGIONS })
    }
    if (path === '/organizations' || path === '/teams') {
      if (method === 'POST') {
        const organization = {
          $id: ORGANIZATION_ID,
          $createdAt: NOW,
          $updatedAt: NOW,
          name: request.postDataJSON().name,
          total: 1,
          prefs: {},
          billingPlan: 'tier-0',
          billingPlanId: 'tier-0',
          status: 'active',
        }
        organizations.push(organization)
        return json(201, organization)
      }
      return json(200, { total: organizations.length, teams: organizations })
    }
    if (
      path === `/organizations/${ORGANIZATION_ID}` ||
      path === `/teams/${ORGANIZATION_ID}`
    ) {
      return organizations[0]
        ? json(200, organizations[0])
        : failure(404, 'team_not_found')
    }
    if (path.endsWith('/projects')) {
      if (method === 'POST') {
        const body = request.postDataJSON()
        createdProjects.push({ name: body.name, region: body.region })
        const project = {
          $id: PROJECT_ID,
          $createdAt: NOW,
          $updatedAt: NOW,
          name: body.name,
          teamId: ORGANIZATION_ID,
          region: body.region,
        }
        projects.push(project)
        return json(201, project)
      }
      return json(200, { total: projects.length, projects })
    }
    if (
      (path === '/project' || path.endsWith(`/projects/${PROJECT_ID}`)) &&
      projects[0]
    ) {
      return json(200, projects[0])
    }
    return failure(404, 'general_route_not_found')
  })

  return { createdProjects }
}

test('a new account picks its first project region in a dialog', async ({
  page,
}) => {
  const api = await mockApi(page)

  await page.goto('/sign-up')
  await page.getByPlaceholder('Your name').fill('Ada Lovelace')
  await page.getByPlaceholder('Your email').fill('ada@example.com')
  await page.getByPlaceholder('Your password').fill('correct-horse-battery')
  await page.getByRole('button', { name: 'Sign up', exact: true }).click()

  // Signup only provisions the organization; the dialog opens on its own.
  await expect(page).toHaveURL(
    new RegExp(`/organizations/${ORGANIZATION_ID}/?$`),
  )
  const dialog = page.getByRole('dialog', { name: 'Create your first project' })
  await expect(dialog).toBeVisible()
  await expect(dialog.locator('#name')).toHaveValue('My first project')
  await expect(dialog.getByRole('combobox')).toContainText('New York')
  expect(api.createdProjects).toHaveLength(0)

  await dialog.getByRole('button', { name: 'Create', exact: true }).click()

  await expect(page).toHaveURL(new RegExp(`/projects/${PROJECT_ID}`))
  expect(api.createdProjects).toEqual([
    { name: 'My first project', region: 'nyc' },
  ])
})

test('dismissing the first project dialog leaves the empty state', async ({
  page,
}) => {
  const api = await mockApi(page)

  await page.goto('/sign-up')
  await page.getByPlaceholder('Your name').fill('Ada Lovelace')
  await page.getByPlaceholder('Your email').fill('ada@example.com')
  await page.getByPlaceholder('Your password').fill('correct-horse-battery')
  await page.getByRole('button', { name: 'Sign up', exact: true }).click()

  const dialog = page.getByRole('dialog', { name: 'Create your first project' })
  await expect(dialog).toBeVisible()
  await dialog.getByRole('button', { name: 'Cancel' }).click()
  await expect(dialog).toHaveCount(0)

  await page
    .getByRole('button', { name: 'Create project', exact: true })
    .click()
  await expect(dialog).toBeVisible()
  expect(api.createdProjects).toHaveLength(0)
})
