import type { Page, Route } from '@playwright/test'
import { expect, test } from './fixtures'

/**
 * MCP onboarding: the Project Agents flag is on by default, so a new unused
 * project shows the Agents page, sidebar item, and Connect MCP dialog without
 * a debug toggle.
 */

const NOW = '2026-10-09T12:00:00.000+00:00'
const PROJECT_ID = 'proj0000000000000000001'
const ORG_ID = 'org00000000000000000001'

const OWNER_ROLES = ['owner']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'keys.write',
  'platforms.write',
]

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
}

const ORGANIZATION = {
  $id: ORG_ID,
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme Docs',
  billingPlan: 'tier-0',
  billingEmail: ACCOUNT.email,
  status: 'active',
  prefs: {},
}

const PROJECT = {
  $id: PROJECT_ID,
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme Docs',
  teamId: ORG_ID,
  region: 'default',
  status: 'active',
  firstAccessedAt: '',
  services: [],
  authMethods: [],
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

async function mockAppwriteApi(page: Page) {
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin

  await page
    .context()
    .addCookies([
      { name: 'a_session_console', value: 'mock-session', url: localOrigin },
    ])

  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: {} }),
    )
    window.localStorage.setItem('screenshot:modeOpen', 'true')
    window.localStorage.setItem('debug:showConstruction', 'false')
  })

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

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') return json(200, ACCOUNT.prefs)
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
    if (apiPath === '/locale/codes')
      return json(200, { total: 0, localeCodes: [] })
    if (apiPath === '/console/variables') return json(200, {})
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORG_ID}/roles`
    )
      return json(200, { roles: OWNER_ROLES, scopes: OWNER_SCOPES })
    if (apiPath === '/organizations' || apiPath === '/teams')
      return json(200, { total: 1, teams: [ORGANIZATION] })
    if (
      apiPath === `/organizations/${ORG_ID}` ||
      apiPath === `/teams/${ORG_ID}`
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
    if (apiPath === `/projects/${PROJECT_ID}/stages`)
      return json(200, { total: 0, stages: [] })
    if (apiPath === '/project/policies')
      return json(200, { total: 0, policies: [] })
    if (apiPath === '/project/mock-phones')
      return json(200, { total: 0, mockNumbers: [] })

    return json(200, { total: 0 })
  })
}

test.describe('MCP onboarding (mocked API)', () => {
  test('the Agents page and Connect MCP dialog are available by default', async ({
    page,
  }, testInfo) => {
    await mockAppwriteApi(page)

    await page.goto(`/projects/${PROJECT_ID}/agents`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(
      page.getByRole('heading', {
        name: 'Connect Appwrite with your agents',
        exact: true,
      }),
    ).toBeVisible({ timeout: 30_000 })
    await expect(
      page
        .getByRole('navigation', { name: 'Main navigation' })
        .getByRole('link', { name: /Agents/ }),
    ).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Copy prompt', exact: true }),
    ).toBeVisible()

    await page.evaluate(() => document.fonts.ready)
    const agentsShot = testInfo.outputPath('mcp-onboarding-agents-page.png')
    await page.screenshot({ path: agentsShot, fullPage: true })

    await page
      .getByRole('button', { name: 'Or install Appwrite MCP manually' })
      .click()

    await expect(
      page.getByRole('tab', { name: 'MCP', exact: true }),
    ).toBeVisible()
    await expect(page.getByText('1. Install', { exact: true })).toBeVisible()
    await expect(page.getByText('2. Try it', { exact: true })).toBeVisible()

    const dialogShot = testInfo.outputPath('mcp-onboarding-connect-dialog.png')
    await page.screenshot({ path: dialogShot, fullPage: true })
  })
})
