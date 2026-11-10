import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Domain logs on project settings and on a site's domains page.
 *
 * A verified rule used to hide its logs everywhere, so a warning from a
 * certificate check, for example that the certificate expires soon and has
 * not been renewed yet, could not be read. A verified rule offers its logs
 * once a certificate check has written any, which every check does, healthy
 * or not. A verified subdomain of the instance's own sites domain on a local
 * host gets no certificate of its own, so it has no logs and offers none. A
 * rule that is still verifying keeps its inline View logs link even before it
 * has any logs.
 *
 * The API is mocked at the network layer.
 */

const NOW = '2026-10-24T10:49:31.000+00:00'

const ISSUING = '[10:49:31] Processing SSL certificate issuance.'
const ISSUED = '[10:49:33] SSL certificate successfully issued.'
const WARNING =
  "[10:49:32] SSL certificate expires soon, and the certificate provider has not renewed it yet. We'll check again tomorrow."

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

const SITE = {
  $id: 'site0000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Landing',
  enabled: true,
  live: true,
  framework: 'nextjs',
  deploymentId: '',
  latestDeploymentId: '',
  latestDeploymentStatus: '',
  vars: [],
} satisfies Partial<Models.Site>

const DOMAINS = {
  warning: 'warning.example.com',
  healthy: 'healthy.example.com',
  owned: 'landing.sites.localhost',
  pending: 'pending.example.com',
}

function rule(domain: string, status: string, logs: string, site: boolean) {
  return {
    $id: domain.replaceAll('.', '-'),
    $createdAt: NOW,
    $updatedAt: NOW,
    domain,
    type: site ? 'deployment' : 'api',
    trigger: 'manual',
    redirectUrl: '',
    redirectStatusCode: 301,
    deploymentId: '',
    deploymentResourceType: site ? 'site' : '',
    deploymentResourceId: site ? SITE.$id : '',
    deploymentVcsProviderBranch: '',
    status,
    logs,
    renewAt: '',
  }
}

function rules(site: boolean) {
  return [
    rule(DOMAINS.warning, 'verified', `${ISSUING}\n${WARNING}\n`, site),
    rule(DOMAINS.healthy, 'verified', `${ISSUING}\n${ISSUED}\n`, site),
    rule(DOMAINS.owned, 'verified', '', site),
    rule(DOMAINS.pending, 'verifying', '', site),
  ]
}

function corsHeaders(route: Route): Record<string, string> {
  return {
    'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': '*',
    'access-control-allow-methods': '*',
  }
}

async function mockAppwriteApi(page: Page) {
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin

  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const apiPath = url.pathname.startsWith('/v1/')
      ? url.pathname.slice('/v1'.length)
      : undefined
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
    if (apiPath === '/proxy/rules') {
      const site = decodeURIComponent(url.search).includes(
        'deploymentResourceType',
      )
      return json(200, { total: rules(site).length, rules: rules(site) })
    }
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, {
        roles: ['owner'],
        scopes: [
          'projects.read',
          'projects.write',
          'rules.read',
          'rules.write',
          'sites.read',
          'sites.write',
        ],
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
    if (apiPath === '/domains') return json(200, { total: 0, domains: [] })
    if (apiPath === `/sites/${SITE.$id}`) return json(200, SITE)
    if (apiPath === `/sites/${SITE.$id}/deployments`)
      return json(200, { total: 0, deployments: [] })

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })
}

async function expectDomainLogs(page: Page) {
  const row = (domain: string) =>
    page.getByRole('row').filter({ hasText: domain })
  const warning = row(DOMAINS.warning)
  const healthy = row(DOMAINS.healthy)
  const owned = row(DOMAINS.owned)
  const pending = row(DOMAINS.pending)

  await expect(warning).toContainText('Verified', { timeout: 30_000 })
  await expect(healthy).toContainText('Verified')
  await expect(owned).toContainText('Verified')
  await expect(pending).toContainText('Generating certificate')

  // Only a rule that is not verified links its logs inline
  await expect(pending.getByRole('button', { name: 'View logs' })).toBeVisible()
  for (const verified of [warning, healthy, owned]) {
    await expect(
      verified.getByRole('button', { name: 'View logs' }),
    ).toHaveCount(0)
  }

  const menu = page.getByRole('menu')
  const logs = menu.getByRole('menuitem', { name: 'Logs' })

  await owned.getByRole('button', { name: 'Actions' }).click()
  await expect(menu.getByRole('menuitem', { name: 'Delete' })).toBeVisible()
  await expect(logs).toHaveCount(0)
  await page.keyboard.press('Escape')
  await expect(menu).toHaveCount(0)

  for (const withLogs of [healthy, pending]) {
    await withLogs.getByRole('button', { name: 'Actions' }).click()
    await expect(logs).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(menu).toHaveCount(0)
  }

  await warning.getByRole('button', { name: 'Actions' }).click()
  await expect(logs).toBeVisible()
  await logs.click()

  const dialog = page.getByRole('dialog', { name: 'View logs' })
  await expect(dialog).toBeVisible()
  await expect(dialog).toContainText(WARNING)
}

test.describe('domain logs', () => {
  test('project settings offer logs on verified rules that have them', async ({
    page,
  }) => {
    await mockAppwriteApi(page)

    await page.goto(`/projects/${PROJECT.$id}/settings/domains`, {
      waitUntil: 'domcontentloaded',
    })

    await expectDomainLogs(page)
  })

  test('site domains offer logs on verified rules that have them', async ({
    page,
  }) => {
    await mockAppwriteApi(page)

    await page.goto(`/projects/${PROJECT.$id}/sites/${SITE.$id}/domains`, {
      waitUntil: 'domcontentloaded',
    })

    await expectDomainLogs(page)
  })
})
