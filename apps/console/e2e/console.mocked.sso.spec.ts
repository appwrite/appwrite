import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Organization settings → Single sign-on, behind the `sso` experiment.
 *
 * Its Domains section lists the organization's team rules (`proxy.listRules`
 * filtered to the organization), claims a domain with `proxy.createTeamRule`,
 * checks the TXT record with `proxy.updateRuleStatus`, changes the roles with
 * `proxy.updateTeamRule` and deletes with `proxy.deleteRule`. The API is mocked
 * at the network layer, so the real pages run; what the page asks the server
 * to do is asserted on the requests it sends.
 */

const NOW = '2026-11-18T09:30:00.000+00:00'

const ACCOUNT = {
  $id: 'user000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Demo Owner',
  registration: NOW,
  status: true,
  labels: [],
  passwordUpdate: NOW,
  email: 'owner@example.com',
  phone: '',
  emailVerification: true,
  phoneVerification: false,
  mfa: false,
  prefs: {},
  targets: [],
  accessedAt: NOW,
} satisfies Partial<Models.User<Models.Preferences>>

const ORGANIZATION = {
  $id: 'acme',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme',
  total: 1,
  billingPlan: 'tier-1',
  billingEmail: ACCOUNT.email,
  status: 'active',
} satisfies Partial<Models.Organization<Models.Preferences>>

const SETTINGS = `/organizations/${ORGANIZATION.$id}/settings`
const SSO = `${SETTINGS}/sso`

type Rule = {
  $id: string
  $createdAt: string
  $updatedAt: string
  domain: string
  type: string
  trigger: string
  redirectUrl: string
  redirectStatusCode: number
  deploymentId: string
  deploymentResourceType: string
  deploymentResourceId: string
  deploymentVcsProviderBranch: string
  status: 'unverified' | 'verified'
  logs: string
  renewAt: string
  roles: string[]
}

function rule(domain: string, roles: string[], status: Rule['status']): Rule {
  return {
    $id: `rule-${domain}`,
    $createdAt: NOW,
    $updatedAt: NOW,
    domain,
    type: 'team',
    trigger: 'manual',
    redirectUrl: '',
    redirectStatusCode: 301,
    deploymentId: '',
    deploymentResourceType: 'team',
    deploymentResourceId: ORGANIZATION.$id,
    deploymentVcsProviderBranch: '',
    status,
    logs: '',
    renewAt: '',
    roles,
  }
}

interface Mock {
  /** The organization's team rules, as the list answers */
  rules: Rule[]
  /** Domains whose TXT record the "DNS" holds */
  published: Set<string>
  /** The organization role of the signed-in account */
  role: 'owner' | 'developer'
  sent: {
    creates: Array<{ domain: string; teamId: string; roles: string[] }>
    verifies: string[]
    updates: Array<{ ruleId: string; roles: string[] }>
    deletes: string[]
    /** Query strings the list was asked with */
    listQueries: string[]
  }
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
  options: Partial<Pick<Mock, 'role' | 'rules'>> = {},
): Promise<Mock> {
  const mock: Mock = {
    rules: options.rules ?? [rule('acme.com', ['developer'], 'verified')],
    published: new Set(),
    role: options.role ?? 'owner',
    sent: {
      creates: [],
      verifies: [],
      updates: [],
      deletes: [],
      listQueries: [],
    },
  }
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
    const method = request.method()

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') {
      return method === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, {})
    }
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/health/version') return json(200, { version: '2.0.0' })
    if (apiPath === '/console/variables') return json(200, {})
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, {
        roles: [mock.role],
        scopes: ['teams.read', 'teams.write', 'projects.read'],
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
      return json(200, { total: 0, projects: [] })

    if (apiPath === '/proxy/rules' && method === 'GET') {
      mock.sent.listQueries.push(decodeURIComponent(url.search))
      return json(200, { total: mock.rules.length, rules: mock.rules })
    }
    if (apiPath === '/proxy/rules/team' && method === 'POST') {
      const body = request.postDataJSON()
      mock.sent.creates.push(body)
      const created = rule(body.domain, body.roles, 'unverified')
      mock.rules.push(created)
      return json(201, created)
    }
    const status = apiPath.match(/^\/proxy\/rules\/([^/]+)\/status$/)
    if (status && method === 'PATCH') {
      mock.sent.verifies.push(status[1])
      const found = mock.rules.find((item) => item.$id === status[1])
      if (!found || !mock.published.has(found.domain)) {
        // The server's detail names its resolver; the dialog shows its own copy
        return json(400, {
          message:
            'DNS TXT query to 172.16.238.100 failed. (region: Frankfurt)',
          code: 400,
          type: 'rule_verification_failed',
          version: '1.0',
        })
      }
      found.status = 'verified'
      return json(200, found)
    }
    const team = apiPath.match(/^\/proxy\/rules\/([^/]+)\/team$/)
    if (team && method === 'PATCH') {
      const roles = request.postDataJSON().roles
      mock.sent.updates.push({ ruleId: team[1], roles })
      const found = mock.rules.find((item) => item.$id === team[1])
      if (found) found.roles = roles
      return json(200, found)
    }
    const one = apiPath.match(/^\/proxy\/rules\/([^/]+)$/)
    if (one && method === 'DELETE') {
      mock.sent.deletes.push(one[1])
      mock.rules = mock.rules.filter((item) => item.$id !== one[1])
      return route.fulfill({ status: 204, headers })
    }

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return mock
}

/** Turn the experiment on, as the quality team's Previews toggle does. */
async function enableSso(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: { sso: true } }),
    )
  })
}

async function openSso(page: Page) {
  await page.goto(SSO, { waitUntil: 'domcontentloaded' })
  await expect(page.getByRole('heading', { name: 'Domains' })).toBeVisible({
    timeout: 45_000,
  })
}

const settingsNav = (page: Page) =>
  page.getByRole('link', { name: 'Single sign-on' })

test.describe('organization single sign-on', () => {
  test('stays hidden while the experiment is off', async ({ page }) => {
    await mockAppwriteApi(page)

    await page.goto(SSO, { waitUntil: 'domcontentloaded' })
    await expect(page).not.toHaveURL(/\/settings\/sso/, { timeout: 45_000 })
    await expect(settingsNav(page)).toHaveCount(0)
  })

  test('is for owners only', async ({ page }) => {
    const mock = await mockAppwriteApi(page, { role: 'developer' })
    await enableSso(page)

    await page.goto(SSO, { waitUntil: 'domcontentloaded' })
    await expect(page).not.toHaveURL(/\/settings\/sso/, { timeout: 45_000 })
    await expect(settingsNav(page)).toHaveCount(0)
    expect(mock.sent.creates).toHaveLength(0)
  })

  test("lists the organization's domains with the roles new members get", async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, {
      rules: [
        rule('acme.com', ['developer'], 'verified'),
        rule('acme.dev', ['developer', 'analyst'], 'unverified'),
      ],
    })
    await enableSso(page)
    await openSso(page)

    const verified = page.getByRole('row', { name: /acme\.com/ })
    await expect(verified).toContainText('Developer')
    await expect(verified).toContainText('Verified')
    await expect(verified.getByRole('button', { name: 'Verify' })).toHaveCount(
      0,
    )

    const pending = page.getByRole('row', { name: /acme\.dev/ })
    await expect(pending).toContainText('Analyst')
    await expect(pending).toContainText('Unverified')
    await expect(pending.getByRole('button', { name: 'Verify' })).toBeVisible()

    // Only this organization's team rules: every organization's share the console project
    const queries = mock.sent.listQueries.at(-1) ?? ''
    expect(queries).toContain('deploymentResourceType')
    expect(queries).toContain('"team"')
    expect(queries).toContain(`"${ORGANIZATION.$id}"`)
  })

  test('claims a domain, shows its record, and verifies once the record is out', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    await enableSso(page)
    await openSso(page)

    await page.getByRole('button', { name: 'Add domain' }).click()
    const add = page.getByRole('dialog', { name: 'Add email domain' })
    await add.getByLabel('Domain').fill('devs.acme.io')
    await add.getByRole('button', { name: 'Add role' }).click()
    await page.getByRole('menuitem', { name: /Analyst/ }).click()
    await add.getByRole('button', { name: 'Add', exact: true }).click()

    await expect
      .poll(() => mock.sent.creates)
      .toEqual([
        {
          domain: 'devs.acme.io',
          teamId: ORGANIZATION.$id,
          roles: ['developer', 'analyst'],
        },
      ])

    // The record is named the way DNS providers expect it: relative to the root domain
    const verify = page.getByRole('dialog', { name: 'Verify domain' })
    await expect(verify).toContainText('devs.acme.io')
    await expect(verify).toContainText('_appwrite.devs')
    await expect(verify).not.toContainText('_appwrite.devs.acme.io')
    await expect(verify).toContainText(`team:${ORGANIZATION.$id}`)

    // Not out yet: a plain message, not the resolver's details
    await verify.getByRole('button', { name: 'Verify', exact: true }).click()
    await expect(verify).toContainText('The TXT record was not found yet.')
    await expect(verify).not.toContainText('172.16.238.100')

    mock.published.add('devs.acme.io')
    await verify.getByRole('button', { name: 'Verify', exact: true }).click()
    await expect(verify).toBeHidden()
    await expect(
      page.getByRole('row', { name: /devs\.acme\.io/ }),
    ).toContainText('Verified')
    await expect
      .poll(() => mock.sent.verifies)
      .toEqual(['rule-devs.acme.io', 'rule-devs.acme.io'])
  })

  test('changes the roles new members get, and deletes a domain', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page)
    await enableSso(page)
    await openSso(page)

    await page.getByRole('button', { name: 'Actions for acme.com' }).click()
    await page.getByRole('menuitem', { name: 'Update' }).click()
    const update = page.getByRole('dialog', { name: 'Update roles' })
    await update.getByRole('button', { name: 'Add role' }).click()
    await page.getByRole('menuitem', { name: /Billing/ }).click()
    await update.getByRole('button', { name: 'Remove Developer' }).click()
    await update.getByRole('button', { name: 'Update', exact: true }).click()

    await expect(update).toBeHidden()
    await expect
      .poll(() => mock.sent.updates)
      .toEqual([{ ruleId: 'rule-acme.com', roles: ['billing'] }])
    await expect(page.getByRole('row', { name: /acme\.com/ })).toContainText(
      'Billing',
    )

    await page.getByRole('button', { name: 'Actions for acme.com' }).click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await page
      .getByRole('dialog', { name: 'Delete email domain' })
      .getByRole('button', { name: 'Delete', exact: true })
      .click()

    await expect.poll(() => mock.sent.deletes).toEqual(['rule-acme.com'])
    await expect(page.getByRole('row', { name: /acme\.com/ })).toHaveCount(0)
    await expect(page.getByText('No email domains yet')).toBeVisible()
  })
})
