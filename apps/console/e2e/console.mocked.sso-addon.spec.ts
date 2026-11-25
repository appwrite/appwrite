import type { Locator, Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * The project's Single sign-on add-on (`sso`), on Settings next to Premium Geo
 * DB. With it the app's users sign in through the identity providers of the
 * project's teams, and each team verifies domains, connects identity providers
 * and turns on Require SSO. A plan sells it when its `supportedAddons` say `sso`; the add-on is
 * listed, priced, enabled (`projects.createSSOAddon`), confirmed and deleted
 * like every project add-on. An environment has the add-on of its project.
 *
 * The API is mocked at the network layer so the real page runs. What the page
 * asks the server to do is asserted on the requests it sends.
 */

const NOW = '2026-11-24T09:30:00.000+00:00'

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
  name: 'Acme',
  billingPlan: 'tier-1',
  billingEmail: ACCOUNT.email,
  paymentMethodId: 'pm-doc-1',
  status: 'active',
} satisfies Partial<Models.Organization<Models.Preferences>>

const SERVICES = [
  'account',
  'databases',
  'tablesdb',
  'storage',
  'teams',
  'users',
  'sites',
  'functions',
  'migrations',
  'messaging',
].map(($id) => ({ $id, enabled: true })) as Models.Project['services']

const PROJECT = {
  $id: 'storefront',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Storefront',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
  services: SERVICES,
} satisfies Partial<Models.Project>

const ENVIRONMENT = {
  $id: 'staging',
  $createdAt: NOW,
  $updatedAt: NOW,
  projectId: PROJECT.$id,
  name: 'Staging',
}

/** The project as it answers inside the environment: its ID, the environment's name. */
const PROJECT_AS_ENVIRONMENT = {
  ...PROJECT,
  name: ENVIRONMENT.name,
  parentId: PROJECT.$id,
  environmentId: ENVIRONMENT.$id,
}

const ADDONS = {
  baa: false,
  premiumGeoDB: false,
  premiumGeoDBOrg: false,
  sso: false,
}
const FREE = {
  $id: 'tier-0',
  name: 'Free',
  order: 0,
  supportedAddons: ADDONS,
  environments: 0,
}
const PRO = {
  $id: 'tier-1',
  name: 'Pro',
  order: 1,
  supportedAddons: {
    ...ADDONS,
    baa: true,
    premiumGeoDB: true,
    premiumGeoDBOrg: true,
    sso: true,
  },
  environments: 3,
}

/** The Single sign-on add-on's price, as the API answers it */
const SSO_PRICE = {
  addonKey: 'sso',
  name: 'Single sign-on',
  monthlyPrice: 150,
  proratedAmount: 60,
  remainingDays: 12,
  totalCycleDays: 30,
  currency: 'USD',
  billingCycleEnd: '2026-11-30T00:00:00.000+00:00',
}

type Addon = {
  $id: string
  $createdAt: string
  $updatedAt: string
  $permissions: string[]
  key: string
  resourceType: string
  resourceId: string
  status: 'active' | 'pending'
  currentValue: number
  nextValue: number | null
}

function ssoAddon(
  status: Addon['status'],
  nextValue: number | null = null,
): Addon {
  return {
    $id: 'addon-sso',
    $createdAt: NOW,
    $updatedAt: NOW,
    $permissions: [],
    key: 'sso',
    resourceType: 'project',
    resourceId: PROJECT.$id,
    status,
    currentValue: 1,
    nextValue,
  }
}

interface Mock {
  plan: typeof PRO | typeof FREE
  /** The project's Single sign-on add-on, or none */
  addon: Addon | null
  /** Status the add-on list answers with; 200 lists `addon` */
  listStatus: number
  sent: {
    /** POST /projects/:id/addons/sso */
    creates: number
    /** POST /projects/:id/addons/:addonId/confirmations */
    confirmations: string[]
    /** DELETE /projects/:id/addons/:addonId */
    deletes: string[]
    /** Environment headers the add-on requests carried */
    environments: Array<string | null>
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
  options: Partial<Pick<Mock, 'plan' | 'addon' | 'listStatus'>> = {},
): Promise<Mock> {
  const mock: Mock = {
    plan: options.plan ?? PRO,
    addon: options.addon === undefined ? null : options.addon,
    listStatus: options.listStatus ?? 200,
    sent: { creates: 0, confirmations: [], deletes: [], environments: [] },
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
    const inside = request.headers()['x-appwrite-environment'] ?? null

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
        roles: ['owner'],
        scopes: [
          'projects.read',
          'projects.write',
          'project.read',
          'project.write',
        ],
      })
    if (apiPath === '/organizations' || apiPath === '/teams')
      return json(200, { total: 1, teams: [ORGANIZATION] })
    if (
      apiPath === `/organizations/${ORGANIZATION.$id}` ||
      apiPath === `/teams/${ORGANIZATION.$id}`
    )
      return json(200, { ...ORGANIZATION, billingPlan: mock.plan.$id })
    if (apiPath === `/organizations/${ORGANIZATION.$id}/plan`)
      return json(200, mock.plan)
    if (apiPath === '/console/plans')
      return json(200, { total: 2, plans: [FREE, PRO] })
    if (apiPath.endsWith('/memberships'))
      return json(200, { total: 0, memberships: [] })

    if (apiPath === '/projects' || apiPath === '/organization/projects')
      return json(200, { total: 1, projects: [PROJECT] })
    if (apiPath === `/organization/projects/${PROJECT.$id}`)
      return json(200, PROJECT)
    if (apiPath === '/project') {
      return json(200, inside ? PROJECT_AS_ENVIRONMENT : PROJECT)
    }
    if (apiPath === '/project/environments')
      return json(200, { total: 1, environments: [ENVIRONMENT] })
    if (apiPath === `/project/environments/${ENVIRONMENT.$id}`)
      return json(200, ENVIRONMENT)
    if (apiPath === '/project/prefs') return json(200, {})
    if (apiPath === `/projects/${PROJECT.$id}/console-access`)
      return json(200, PROJECT)

    // The project's add-ons
    const addons = `/projects/${PROJECT.$id}/addons`
    if (apiPath.startsWith(addons)) mock.sent.environments.push(inside)
    if (apiPath === addons && method === 'GET') {
      if (mock.listStatus !== 200) {
        return json(mock.listStatus, {
          message:
            mock.listStatus === 401
              ? 'User (role: developer) missing scopes (["projects.read"])'
              : 'Server Error',
          code: mock.listStatus,
          type:
            mock.listStatus === 401
              ? 'general_unauthorized_scope'
              : 'general_unknown',
          version: '1.0',
        })
      }
      const listed = mock.addon ? [mock.addon] : []
      return json(200, { total: listed.length, addons: listed })
    }
    if (apiPath === `${addons}/sso/price`) return json(200, SSO_PRICE)
    if (apiPath === `${addons}/sso` && method === 'POST') {
      mock.sent.creates += 1
      // Scheduled for removal: enabling it again keeps it
      mock.addon = mock.addon
        ? { ...mock.addon, nextValue: null }
        : ssoAddon('active')
      return json(201, mock.addon)
    }
    const confirm = apiPath.match(
      /^\/projects\/[^/]+\/addons\/([^/]+)\/confirmations$/,
    )
    if (confirm && method === 'POST') {
      mock.sent.confirmations.push(confirm[1])
      mock.addon = ssoAddon('active')
      return json(200, mock.addon)
    }
    const one = apiPath.match(/^\/projects\/[^/]+\/addons\/([^/]+)$/)
    if (one && method === 'DELETE') {
      mock.sent.deletes.push(one[1])
      if (mock.addon) mock.addon = { ...mock.addon, nextValue: 0 }
      return route.fulfill({ status: 204, headers })
    }

    // Lists the page around the card reads; empty is enough for them
    if (
      method === 'GET' &&
      /^\/(project\/(keys|platforms|variables|webhooks)|vcs\/installations|proxy\/rules)$/.test(
        apiPath,
      )
    ) {
      const key = apiPath.split('/').pop() as string
      return json(200, { total: 0, [key]: [] })
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

/** Open the Console already inside the environment, as a reload of a tab that was in it does. */
async function enterEnvironment(page: Page) {
  await page.addInitScript(
    ([projectId, environmentId]) => {
      try {
        window.sessionStorage.setItem(
          `console:environment:${projectId}`,
          environmentId,
        )
      } catch {
        /* storage disabled */
      }
    },
    [PROJECT.$id, ENVIRONMENT.$id],
  )
}

async function openSettings(page: Page) {
  await page.goto(`/projects/${PROJECT.$id}/settings`, {
    waitUntil: 'domcontentloaded',
  })
  await expect(
    page.getByRole('heading', { name: 'API credentials' }),
  ).toBeVisible({ timeout: 45_000 })
}

/** The Single sign-on card on the project's settings */
function ssoCard(page: Page): Locator {
  return page
    .getByRole('heading', { name: 'Single sign-on', exact: true })
    .locator('xpath=ancestor::div[contains(@class, "rounded-xl")][1]')
}

/**
 * Single sign-on off, as on a self-hosted Console, which has no API for it. On
 * Cloud, where these specs run, it is on for everyone.
 */
async function withoutSso(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'cloud', features: { sso: false } }),
    )
  })
}

test.describe('the Single sign-on project add-on', () => {
  test('stays hidden where the Console has no single sign-on', async ({
    page,
  }) => {
    await mockAppwriteApi(page)
    await withoutSso(page)
    await openSettings(page)

    await expect(
      page.getByRole('heading', { name: 'Premium Geo DB' }),
    ).toBeVisible()
    await expect(
      page.getByRole('heading', { name: 'Single sign-on', exact: true }),
    ).toHaveCount(0)
  })

  test('says what it is with its price, and enables it', async ({ page }) => {
    const mock = await mockAppwriteApi(page)
    await openSettings(page)

    const card = ssoCard(page)
    await expect(card).toContainText('Not enabled')
    await expect(card).toContainText(
      "With this add-on, your app's users sign in through their company's identity provider. Each team in this project can verify domains, connect identity providers and turn on Require SSO.",
    )
    await expect(card).toContainText(
      '$150.00/month, prorated for the current billing cycle.',
    )
    // It sells what the API does: the app's users sign in through it
    await expect(card).toContainText("your app's users sign in")

    await card.getByRole('button', { name: 'Enable add-on' }).click()
    const dialog = page.getByRole('dialog', {
      name: 'Enable the Single sign-on add-on',
    })
    await expect(dialog).toContainText(
      "your app's users sign in through their company's identity provider",
    )
    await expect(dialog).toContainText('$150.00 / month')
    await expect(dialog).toContainText('$60.00')
    await dialog.getByRole('button', { name: 'Enable', exact: true }).click()

    await expect.poll(() => mock.sent.creates).toBe(1)
    await expect(dialog).toHaveCount(0)
    await expect(card).toContainText('Active')
    await expect(card).toContainText('$150.00/month')
    await expect(card.getByRole('button', { name: 'Disable' })).toBeVisible()
  })

  test('disables it until the end of the cycle, and keeps it', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, { addon: ssoAddon('active') })
    await openSettings(page)

    const card = ssoCard(page)
    await expect(card).toContainText('Active')
    await card.getByRole('button', { name: 'Disable' }).click()
    const dialog = page.getByRole('dialog', {
      name: 'Disable the Single sign-on add-on',
    })
    await expect(dialog).toContainText(
      "Then nobody signs in to your app through the identity providers of this project's teams, and the teams can no longer verify domains, connect identity providers or turn on Require SSO.",
    )
    await dialog.getByRole('button', { name: 'Disable add-on' }).click()
    await expect.poll(() => mock.sent.deletes).toEqual(['addon-sso'])

    await expect(card).toContainText('Scheduled for removal')
    await expect(card).toContainText(
      'The add-on will be removed at the end of your current billing cycle.',
    )
    await card.getByRole('button', { name: 'Keep add-on' }).click()
    await expect.poll(() => mock.sent.creates).toBe(1)
    await expect(card).toContainText('Active')
    await expect(card).not.toContainText('Scheduled for removal')
  })

  test('checks a payment that waits for confirmation', async ({ page }) => {
    const mock = await mockAppwriteApi(page, { addon: ssoAddon('pending') })
    await openSettings(page)

    const card = ssoCard(page)
    await expect(card).toContainText('Payment pending')
    await expect(card).toContainText('A payment is awaiting confirmation.')
    await card.getByRole('button', { name: 'Refresh' }).click()
    await expect.poll(() => mock.sent.confirmations).toEqual(['addon-sso'])
    await expect(card).toContainText('Active')
  })

  test('says when the add-on starts with the next cycle, with no payment to check', async ({
    page,
  }) => {
    // Enabled with nothing left to pay in this cycle: active, counting from the next
    const mock = await mockAppwriteApi(page, {
      addon: { ...ssoAddon('active', 1), currentValue: 0 },
    })
    await openSettings(page)

    const card = ssoCard(page)
    await expect(card).toContainText('Starts next cycle')
    await expect(card).toContainText(
      'The add-on starts with your next billing cycle. Single sign-on can be set up from then.',
    )
    await expect(card).not.toContainText('Payment pending')
    await expect(card.getByRole('button', { name: 'Refresh' })).toHaveCount(0)
    await expect(
      card.getByRole('button', { name: 'Enable add-on' }),
    ).toHaveCount(0)
    expect(mock.sent.confirmations).toEqual([])
    expect(mock.sent.creates).toBe(0)
  })

  test('offers the upgrade on a plan that does not sell it', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, { plan: FREE })
    await openSettings(page)

    const card = ssoCard(page)
    await expect(card).toContainText('Unavailable')
    await expect(card).toContainText(
      'This add-on is available on Pro. Upgrade your plan to enable it.',
    )
    await expect(
      card.getByRole('button', { name: 'Upgrade plan' }),
    ).toBeVisible()
    await expect(
      card.getByRole('button', { name: 'Enable add-on' }),
    ).toHaveCount(0)
    expect(mock.sent.creates).toBe(0)
  })

  test("inside an environment, shows its project's add-on and changes nothing", async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, { addon: ssoAddon('active') })
    await enterEnvironment(page)
    await openSettings(page)

    await expect(
      page.getByRole('heading', { name: 'Delete environment' }),
    ).toBeVisible()
    const card = ssoCard(page)
    await expect(card).toContainText('Active')
    await expect(card).toContainText(
      "This environment has the add-on of its project. Change it in the project's settings.",
    )
    await expect(card.getByRole('button')).toHaveCount(0)
    // Read from the project itself, not as the environment
    expect(mock.sent.environments.length).toBeGreaterThan(0)
    expect(mock.sent.environments.every((inside) => inside === null)).toBe(true)
  })

  test("says so when the add-ons can't be read for lack of permission", async ({
    page,
  }) => {
    await mockAppwriteApi(page, { listStatus: 401 })
    await openSettings(page)

    const card = ssoCard(page)
    await expect(card).toContainText(
      "You don't have permission to manage this project's add-ons.",
    )
    await expect(card.getByRole('button')).toHaveCount(0)
  })

  test('says when the add-ons failed to load, and tries again', async ({
    page,
  }) => {
    const mock = await mockAppwriteApi(page, { listStatus: 500 })
    await openSettings(page)

    const card = ssoCard(page)
    await expect(card).toContainText("Couldn't load the add-on's status.")
    mock.listStatus = 200
    await card.getByRole('button', { name: 'Try again' }).click()
    await expect(card).toContainText('Not enabled')
    await expect(
      card.getByRole('button', { name: 'Enable add-on' }),
    ).toBeVisible()
  })
})
