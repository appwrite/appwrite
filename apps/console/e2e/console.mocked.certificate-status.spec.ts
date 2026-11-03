import type { Locator, Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Certificate state on the status badge of a verified rule, on project
 * settings and on a site's and a function's domains page.
 *
 * A verified rule shows when its certificate expired, failed or is blocked
 * from renewing, or expires soon, so the owner sees a problem before the site
 * breaks. Each of those badges explains what to do in a tooltip and links the
 * rule's logs inline. A healthy certificate keeps the Verified badge, with its
 * expiry in the tooltip. A server that does not report certificate state, for
 * example an older self-hosted one, keeps the Verified badge unchanged.
 *
 * The API is mocked at the network layer.
 */

const NOW = '2026-10-24T10:49:31.000+00:00'
const DAY_MS = 24 * 60 * 60 * 1000

const LOGS = '[10:49:31] Processing SSL certificate issuance.\n'

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

const FUNCTION = {
  $id: 'func0000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Webhooks',
  enabled: true,
  live: true,
  runtime: 'node-22',
  deploymentId: '',
  latestDeploymentId: '',
  latestDeploymentStatus: '',
  vars: [],
} satisfies Partial<Models.Function>

const DOMAINS = {
  legacy: 'legacy.example.com',
  healthy: 'healthy.example.com',
  soon: 'soon.example.com',
  expiring: 'expiring.example.com',
  blocked: 'blocked.example.com',
  failed: 'failed.example.com',
  expired: 'expired.example.com',
  pending: 'pending.example.com',
}

/** Expiry relative to the browser clock, which the badge compares against. */
function expiresIn(days: number): string {
  return new Date(Date.now() + days * DAY_MS).toISOString()
}

const HEALTHY_EXPIRE_AT = expiresIn(20)

/** What a rule routes to: '' for an API rule on project settings. */
type ResourceType = '' | 'site' | 'function'

const RESOURCE_IDS: Record<ResourceType, string> = {
  '': '',
  site: SITE.$id,
  function: FUNCTION.$id,
}

function rule(
  domain: string,
  status: string,
  resourceType: ResourceType,
  certificate?: { expireAt: string; certificateStatus: string },
) {
  return {
    $id: domain.replaceAll('.', '-'),
    $createdAt: NOW,
    $updatedAt: NOW,
    domain,
    type: resourceType ? 'deployment' : 'api',
    trigger: 'manual',
    redirectUrl: '',
    redirectStatusCode: 301,
    deploymentId: '',
    deploymentResourceType: resourceType,
    deploymentResourceId: RESOURCE_IDS[resourceType],
    deploymentVcsProviderBranch: '',
    status,
    logs: status === 'verifying' ? '' : LOGS,
    renewAt: '',
    ...certificate,
  }
}

function rules(resourceType: ResourceType) {
  return [
    // A server that does not report certificate state
    rule(DOMAINS.legacy, 'verified', resourceType),
    rule(DOMAINS.healthy, 'verified', resourceType, {
      expireAt: HEALTHY_EXPIRE_AT,
      certificateStatus: 'issued',
    }),
    rule(DOMAINS.soon, 'verified', resourceType, {
      expireAt: expiresIn(3),
      certificateStatus: 'renewing',
    }),
    rule(DOMAINS.expiring, 'verified', resourceType, {
      expireAt: expiresIn(20),
      certificateStatus: 'expiring',
    }),
    rule(DOMAINS.blocked, 'verified', resourceType, {
      expireAt: expiresIn(10),
      certificateStatus: 'blocked',
    }),
    rule(DOMAINS.failed, 'verified', resourceType, {
      expireAt: expiresIn(10),
      certificateStatus: 'failed',
    }),
    rule(DOMAINS.expired, 'verified', resourceType, {
      expireAt: expiresIn(-1),
      certificateStatus: 'expiring',
    }),
    rule(DOMAINS.pending, 'verifying', resourceType, {
      expireAt: '',
      certificateStatus: 'pending',
    }),
  ]
}

/** The `deploymentResourceType` a rules list filters on, '' for none. */
function filteredResourceType(url: URL): ResourceType {
  for (const [key, value] of url.searchParams) {
    if (!key.startsWith('queries[')) continue
    const query = JSON.parse(value) as { attribute?: string; values?: string[] }
    if (query.attribute === 'deploymentResourceType') {
      return (query.values?.[0] ?? '') as ResourceType
    }
  }
  return ''
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
      const list = rules(filteredResourceType(url))
      return json(200, { total: list.length, rules: list })
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
          'functions.read',
          'functions.write',
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
    if (apiPath === `/functions/${FUNCTION.$id}`) return json(200, FUNCTION)
    if (apiPath === `/functions/${FUNCTION.$id}/deployments`)
      return json(200, { total: 0, deployments: [] })

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })
}

async function expectTooltip(page: Page, badge: Locator, text: string) {
  await badge.hover()
  await expect(page.getByRole('tooltip')).toContainText(text)
  // Leave in steps: the tooltip stays open while the pointer could still be
  // on its way to the content
  await page.mouse.move(0, 0, { steps: 10 })
  await expect(page.getByRole('tooltip')).toHaveCount(0)
}

async function expectCertificateStatus(page: Page) {
  const row = (domain: string) =>
    page.getByRole('row').filter({ hasText: domain })
  const badge = (domain: string, label: string) =>
    row(domain).getByText(label, { exact: true })
  const viewLogs = (domain: string) =>
    row(domain).getByRole('button', { name: 'View logs' })

  await expect(row(DOMAINS.legacy)).toContainText('Verified', {
    timeout: 30_000,
  })
  await expect(row(DOMAINS.healthy)).toContainText('Verified')
  await expect(row(DOMAINS.soon)).toContainText('Expires soon')
  await expect(row(DOMAINS.expiring)).toContainText('Expires soon')
  await expect(row(DOMAINS.blocked)).toContainText('Renewal blocked')
  await expect(row(DOMAINS.failed)).toContainText('Renewal failed')
  await expect(row(DOMAINS.expired)).toContainText('Certificate expired')
  await expect(row(DOMAINS.pending)).toContainText('Generating certificate')

  // A healthy rule links no logs inline; one with a problem does
  for (const healthy of [DOMAINS.legacy, DOMAINS.healthy]) {
    await expect(viewLogs(healthy)).toHaveCount(0)
  }
  for (const problem of [
    DOMAINS.soon,
    DOMAINS.expiring,
    DOMAINS.blocked,
    DOMAINS.failed,
    DOMAINS.expired,
    DOMAINS.pending,
  ]) {
    await expect(viewLogs(problem)).toBeVisible()
  }

  await expectTooltip(
    page,
    badge(DOMAINS.blocked, 'Renewal blocked'),
    'Renewal is waiting on a DNS record. View logs to see which record to add.',
  )
  await expectTooltip(
    page,
    badge(DOMAINS.failed, 'Renewal failed'),
    "View logs for details. Check that the domain's DNS still points to Appwrite.",
  )
  await expectTooltip(
    page,
    badge(DOMAINS.expiring, 'Expires soon'),
    "This certificate has not renewed yet. Check that the domain's DNS still points to Appwrite.",
  )
  await expectTooltip(
    page,
    badge(DOMAINS.expired, 'Certificate expired'),
    "This certificate has expired. Check that the domain's DNS still points to Appwrite.",
  )
  // Same short date as formatLocalizedDateShort, which maps `en` to `en-US`
  const healthyExpiry = new Date(HEALTHY_EXPIRE_AT).toLocaleDateString(
    'en-US',
    { day: 'numeric', month: 'short', year: 'numeric' },
  )
  await expectTooltip(
    page,
    badge(DOMAINS.healthy, 'Verified'),
    `Certificate expires on ${healthyExpiry}.`,
  )

  // Without certificate state there is nothing to explain
  await badge(DOMAINS.legacy, 'Verified').hover()
  await expect(page.getByRole('tooltip')).toHaveCount(0)
  await page.mouse.move(0, 0)

  // A hint opens from the keyboard too; a badge without one is no tab stop
  await expect(badge(DOMAINS.legacy, 'Verified')).not.toHaveAttribute(
    'tabindex',
  )
  await badge(DOMAINS.blocked, 'Renewal blocked').focus()
  await expect(page.getByRole('tooltip')).toContainText(
    'Renewal is waiting on a DNS record.',
  )
  await page.keyboard.press('Escape')
  await expect(page.getByRole('tooltip')).toHaveCount(0)

  await viewLogs(DOMAINS.blocked).click()
  const dialog = page.getByRole('dialog', { name: 'View logs' })
  await expect(dialog).toBeVisible()
  await expect(dialog).toContainText(LOGS.trim())
}

test.describe('certificate status', () => {
  test('project settings show certificate state on verified rules', async ({
    page,
  }) => {
    await mockAppwriteApi(page)

    await page.goto(`/projects/${PROJECT.$id}/settings/domains`, {
      waitUntil: 'domcontentloaded',
    })

    await expectCertificateStatus(page)
  })

  test('site domains show certificate state on verified rules', async ({
    page,
  }) => {
    await mockAppwriteApi(page)

    await page.goto(`/projects/${PROJECT.$id}/sites/${SITE.$id}/domains`, {
      waitUntil: 'domcontentloaded',
    })

    await expectCertificateStatus(page)
  })

  test('function domains show certificate state on verified rules', async ({
    page,
  }) => {
    await mockAppwriteApi(page)

    await page.goto(
      `/projects/${PROJECT.$id}/functions/${FUNCTION.$id}/domains`,
      { waitUntil: 'domcontentloaded' },
    )

    await expectCertificateStatus(page)
  })
})
