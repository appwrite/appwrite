import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Add domain wizard on an organization at its custom domain limit.
 *
 * Before a project domain is added, the wizard registers its apex domain in the
 * organization. On Cloud, that request answers 402 `domain_limit_exceeded` once
 * the plan's domains are used up, and the wizard used to replace that with a
 * bare "Failed to register domain", leaving users nothing to act on. The Add
 * button also stayed enabled while the request ran, so repeated clicks sent
 * repeated requests and stacked toasts, and after a full page load the toast
 * itself painted underneath the fullscreen wizard.
 *
 * The API is mocked at the network layer with the response Cloud returns.
 */

const NOW = '2026-10-24T10:49:31.000+00:00'

const LIMIT_MESSAGE =
  'You have reached the maximum number of custom domains allowed for your plan. Please upgrade to increase the limit.'

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

function corsHeaders(route: Route): Record<string, string> {
  return {
    'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': '*',
    'access-control-allow-methods': '*',
  }
}

/** Counts the organization domain requests the page sends. */
type Captured = { registrations: number }

async function mockAppwriteApi(page: Page, captured: Captured) {
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

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') {
      return request.method() === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, {})
    }
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/console/variables') return json(200, {})
    if (apiPath === '/health/version') return json(200, { version: '2.0.0' })
    if (apiPath === '/proxy/rules') return json(200, { total: 0, rules: [] })
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, {
        roles: ['owner'],
        scopes: ['projects.read', 'projects.write', 'rules.write'],
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
    if (apiPath === '/domains' && request.method() === 'GET')
      return json(200, { total: 0, domains: [] })
    if (apiPath === '/domains' && request.method() === 'POST') {
      captured.registrations++
      await new Promise((resolve) => setTimeout(resolve, 500))
      return json(402, {
        message: LIMIT_MESSAGE,
        code: 402,
        type: 'domain_limit_exceeded',
        version: '1.0',
      })
    }

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })
}

test.describe('add domain wizard at the organization domain limit', () => {
  test('shows the plan limit error once', async ({ page }) => {
    const captured: Captured = { registrations: 0 }
    await mockAppwriteApi(page, captured)

    await page.goto(`/projects/${PROJECT.$id}/settings/domains/add`, {
      waitUntil: 'domcontentloaded',
    })

    await page
      .getByPlaceholder(/example\.com/)
      .fill('api.example.com', { timeout: 30_000 })

    const add = page.getByRole('button', { name: 'Add', exact: true })
    await add.click()
    await expect(add).toBeDisabled()
    await add.click({ force: true })

    await expect(page.getByText(LIMIT_MESSAGE)).toHaveCount(1)
    await expect
      .poll(() =>
        page.getByText(LIMIT_MESSAGE).evaluate((toast) => {
          const box = toast.getBoundingClientRect()
          const top = document.elementFromPoint(
            box.x + box.width / 2,
            box.y + box.height / 2,
          )
          return toast.contains(top)
        }),
      )
      .toBe(true)
    expect(captured.registrations).toBe(1)
  })
})
