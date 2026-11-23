import type { Page } from '@playwright/test'
import type { DistributionApp } from '@/lib/appwrite/distribution'
import {
  appsFixtures,
  distributionPath,
  expect,
  mockDistributionApi,
  NOW,
  PROJECT,
  screenshot,
  test,
} from './fixtures/distribution'

const HOUR = 60 * 60 * 1000

function manyApps(count: number): DistributionApp[] {
  const [template] = appsFixtures()
  return Array.from({ length: count }, (_, index) => {
    const number = String(index + 1).padStart(2, '0')
    return {
      ...template,
      $id: `app000000000000000000${number}`,
      $createdAt: new Date(Date.parse(NOW) - index * HOUR).toISOString(),
      name: `App ${number}`,
    }
  })
}

function sidebarLink(page: Page, name: string) {
  return page
    .getByRole('navigation', { name: 'Main navigation' })
    .getByRole('link', { name, exact: true })
}

const REPOSITORY_FIELDS = [
  'installationId',
  'providerRepositoryId',
  'providerBranch',
  'providerRootDirectory',
  'providerBranches',
  'providerPaths',
  'providerTags',
  'providerSilentMode',
  'providerComments',
  'providerDeployments',
  'providerStatuses',
]

async function ignoreRepositoryFields(page: Page) {
  await page.route(
    (url) => {
      const [version, service, resource, ...rest] = url.pathname
        .split('/')
        .filter(Boolean)
      return (
        version === 'v1' &&
        service === 'distribution' &&
        resource === 'apps' &&
        rest.length <= 1
      )
    },
    async (route) => {
      const request = route.request()
      if (request.method() !== 'POST' && request.method() !== 'PATCH') {
        return route.fallback()
      }
      const body = request.postDataJSON() as Record<string, unknown>
      const accepted = Object.fromEntries(
        Object.entries(body).filter(
          ([key]) => !REPOSITORY_FIELDS.includes(key),
        ),
      )
      return route.fallback({ postData: JSON.stringify(accepted) })
    },
  )
}

async function capture(page: Page, name: string) {
  await page.addStyleTag({
    content: '*, *::before, *::after { transition: none !important; }',
  })
  await screenshot(page, name)
}

async function openList(page: Page) {
  await page.goto(distributionPath(), { waitUntil: 'domcontentloaded' })
  await expect(page.getByText('Acme Shop').first()).toBeVisible({
    timeout: 30_000,
  })
}

test.describe('Distribution apps', () => {
  test('lists apps in a grid and a table', async ({ page }) => {
    await mockDistributionApi(page)
    await openList(page)

    await expect(page.getByText('Acme Rewards')).toBeVisible()
    await expect(page.getByText('Field Notes')).toBeVisible()
    await expect(page.getByText('No repository')).toBeVisible()
    await capture(page, 'distribution-apps-grid.png')

    await page.getByRole('button', { name: 'List view' }).click()
    const shopRow = page.getByRole('row').filter({ hasText: 'Acme Shop' })
    await expect(shopRow).toContainText('Flutter')
    await expect(shopRow).toContainText('main')
    await expect(shopRow).toContainText('Ready')
    await expect(
      page.getByRole('row').filter({ hasText: 'Field Notes' }),
    ).toContainText('Failed')
    await capture(page, 'distribution-apps-table.png')

    await page.getByPlaceholder('Search apps...').fill('rewards')
    await expect(
      page.getByRole('row').filter({ hasText: 'Acme Shop' }),
    ).toHaveCount(0)
    await expect(
      page.getByRole('row').filter({ hasText: 'Acme Rewards' }),
    ).toBeVisible()

    await page.getByPlaceholder('Search apps...').fill('nothing matches')
    await expect(page.getByText('No apps found')).toBeVisible()

    await page.getByPlaceholder('Search apps...').fill('')
    await page.getByRole('link', { name: 'Field Notes' }).click()
    await expect(page).toHaveURL(
      new RegExp(`/distribution/app00000000000000000003`),
    )
  })

  test('keeps the current page visible while the next page loads', async ({
    page,
  }) => {
    await mockDistributionApi(page, { seed: { apps: manyApps(15) } })
    let releaseSecondPage: () => void = () => {}
    const secondPageReleased = new Promise<void>((resolve) => {
      releaseSecondPage = resolve
    })
    await page.route(
      (url) => url.pathname === '/v1/distribution/apps',
      async (route) => {
        const queries = [
          ...new URL(route.request().url()).searchParams.values(),
        ]
        if (queries.some((query) => query.includes('"offset","values":[12]'))) {
          await secondPageReleased
        }
        await route.fallback()
      },
    )

    await page.goto(distributionPath(), { waitUntil: 'domcontentloaded' })
    await expect(page.getByText('App 01')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('App 13')).toHaveCount(0)

    await page.getByRole('button', { name: /next/i }).click()
    await expect(page.getByText('App 01')).toBeVisible()
    await expect(page.getByText('Loading apps...')).toHaveCount(0)

    releaseSecondPage()
    await expect(page.getByText('App 13')).toBeVisible()
    await expect(page.getByText('App 01')).toHaveCount(0)
  })

  test('rejects a cursor for an app that no longer exists', async ({
    page,
  }) => {
    await mockDistributionApi(page)
    await page.goto(distributionPath())
    await expect(page.getByText('Acme Shop').first()).toBeVisible()
    const [, second] = appsFixtures()

    const list = (cursor: string) =>
      page.evaluate(
        async ({ cursor, projectId }) => {
          const query = JSON.stringify({
            method: 'cursorAfter',
            values: [cursor],
          })
          const response = await fetch(
            `/v1/distribution/apps?queries[]=${encodeURIComponent(query)}`,
            { headers: { 'x-appwrite-project': projectId } },
          )
          return { status: response.status, body: await response.json() }
        },
        { cursor, projectId: PROJECT.$id },
      )

    const missing = await list('app0000000000000missing')
    expect(missing.status).toBe(400)
    expect(missing.body).toMatchObject({
      code: 400,
      type: 'general_cursor_not_found',
    })

    const after = await list(second.$id)
    expect(after.status).toBe(200)
    expect(
      after.body.apps.map((app: DistributionApp) => app.$id),
    ).not.toContain(second.$id)
  })

  test('shows the product empty state before the first app', async ({
    page,
  }) => {
    await mockDistributionApi(page, { empty: true })
    await page.goto(distributionPath(), { waitUntil: 'domcontentloaded' })

    await expect(
      page.getByRole('heading', { name: 'Ship your apps to the stores' }),
    ).toBeVisible({ timeout: 30_000 })
    await expect(page.getByPlaceholder('Search apps...')).toHaveCount(0)
    await capture(page, 'distribution-apps-empty.png')

    await page.getByRole('button', { name: 'Create app' }).click()
    await expect(page).toHaveURL(/\/distribution\/create$/)
  })

  test('disables create for a read-only role', async ({ page }) => {
    await mockDistributionApi(page, { role: 'readonly' })
    await openList(page)

    await expect(
      page.getByRole('button', { name: 'Create app' }),
    ).toBeDisabled()
    await capture(page, 'distribution-apps-readonly.png')

    await page.goto(distributionPath('/create'), {
      waitUntil: 'domcontentloaded',
    })
    await expect(page).toHaveURL(/\/distribution\/?$/, { timeout: 30_000 })
  })

  test('limits the wizard platforms to the framework', async ({ page }) => {
    await mockDistributionApi(page)
    await page.goto(distributionPath('/create'))
    const next = page.getByRole('button', { name: 'Continue' })

    await page.getByLabel('Name').fill('Acme Watch')
    await next.click()
    await page.getByRole('radio', { name: /A native iOS project/ }).click()
    await next.click()

    await expect(page.locator('#distribution-platform-ios')).toBeChecked()
    await expect(page.locator('#distribution-platform-android')).toBeDisabled()
    await expect(
      page.getByText('Not available for this framework'),
    ).toBeVisible()
    await expect(page.getByLabel('Package name')).toHaveCount(0)
  })

  test('creates an app with both targets and a repository', async ({
    page,
  }) => {
    const { requests } = await mockDistributionApi(page)
    await openList(page)

    await page.getByRole('button', { name: 'Create app' }).click()
    await expect(page).toHaveURL(/\/distribution\/create$/)
    const next = page.getByRole('button', { name: 'Continue' })

    await expect(page.getByLabel('Name')).toBeFocused()
    await expect(next).toBeDisabled()
    await page.getByLabel('Name').fill('Acme Wallet')
    await capture(page, 'distribution-create-name.png')
    await next.click()

    await expect(
      page.getByRole('heading', { name: 'Choose a framework' }),
    ).toBeVisible()
    await expect(page.getByRole('radio', { name: /Flutter/ })).toHaveAttribute(
      'aria-checked',
      'true',
    )
    await page.getByRole('radio', { name: /React Native/ }).click()
    await capture(page, 'distribution-create-framework.png')
    await next.click()

    await expect(
      page.getByRole('heading', { name: 'Choose platforms' }),
    ).toBeVisible()
    await expect(next).toBeDisabled()
    await page.getByLabel('Bundle ID').fill('com.acme.wallet')
    await page.getByLabel('Package name').fill('com..acme')
    await expect(next).toBeDisabled()
    await page.getByLabel('Package name').fill('com.acme.wallet')
    await expect(next).toBeEnabled()
    await capture(page, 'distribution-create-platforms.png')
    await next.click()

    await expect(
      page.getByRole('heading', { name: 'Connect a repository' }),
    ).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Create app' }),
    ).toBeDisabled()
    await capture(page, 'distribution-create-repository.png')

    await page.getByText('Connect existing repository').click()
    await page
      .locator('[data-wizard-layout]')
      .getByRole('button', { name: 'Connect', exact: true })
      .first()
      .click()
    await expect(page.getByText('acme/shop-app')).toBeVisible()
    await capture(page, 'distribution-create-repository-connected.png')

    await page.getByRole('button', { name: 'Create app' }).click()
    await expect(page).toHaveURL(/\/distribution\/[^/]+\/targets/, {
      timeout: 30_000,
    })
    await expect(page.getByText('App created', { exact: true })).toBeVisible()

    const appRequest = requests.find(
      (request) =>
        request.method === 'POST' && request.path === '/distribution/apps',
    )
    expect(appRequest?.body).toMatchObject({
      name: 'Acme Wallet',
      framework: 'react-native',
      installationId: 'installation0001',
      providerRepositoryId: '1001',
      providerRootDirectory: './',
    })
    const appId = String(appRequest?.body.appId)
    expect(appId).not.toBe('unique()')
    await expect(page).toHaveURL(new RegExp(`/distribution/${appId}/`))

    const targetRequests = requests.filter(
      (request) =>
        request.method === 'POST' &&
        request.path === `/distribution/apps/${appId}/targets`,
    )
    expect(
      targetRequests.map(({ body }) => [body.platform, body.identifier]).sort(),
    ).toEqual([
      ['android', 'com.acme.wallet'],
      ['ios', 'com.acme.wallet'],
    ])
  })

  test('opens the app and reports a repository the server did not link', async ({
    page,
  }) => {
    const { requests, store } = await mockDistributionApi(page)
    await ignoreRepositoryFields(page)

    await page.goto(distributionPath('/create'), {
      waitUntil: 'domcontentloaded',
    })
    const next = page.getByRole('button', { name: 'Continue' })
    await page.getByLabel('Name').fill('Acme Ledger')
    await next.click()
    await next.click()
    await page.getByLabel('Bundle ID').fill('com.acme.ledger')
    await page.getByLabel('Package name').fill('com.acme.ledger')
    await next.click()
    await page.getByText('Connect existing repository').click()
    await page
      .locator('[data-wizard-layout]')
      .getByRole('button', { name: 'Connect', exact: true })
      .first()
      .click()
    await page.getByRole('button', { name: 'Create app' }).click()

    await expect(
      page.getByText('App created, but the repository was not linked.'),
    ).toBeVisible({ timeout: 30_000 })
    await expect(
      page.getByText('Connect it from the app settings.'),
    ).toBeVisible()
    await expect(page.getByText('App created', { exact: true })).toHaveCount(0)
    const appRequest = requests.find(
      (request) =>
        request.method === 'POST' && request.path === '/distribution/apps',
    )
    const appId = String(appRequest?.body.appId)
    await expect(page).toHaveURL(new RegExp(`/distribution/${appId}/targets`))
    expect(store.apps.find((app) => app.$id === appId)?.installationId).toBe('')
  })

  test('opens the app and names the target that failed', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)
    await page.route(
      (url) => url.pathname.endsWith('/targets'),
      async (route) => {
        const body = route.request().postDataJSON() as { platform?: string }
        if (route.request().method() === 'POST' && body?.platform === 'ios') {
          await route.fulfill({
            status: 400,
            headers: {
              'access-control-allow-origin':
                route.request().headers()['origin'] ?? '*',
              'access-control-allow-credentials': 'true',
              'content-type': 'application/json',
            },
            body: JSON.stringify({
              message: 'The bundle ID is already used by another app.',
              code: 400,
              type: 'distribution_target_invalid',
              version: '2.0.0',
            }),
          })
          return
        }
        await route.fallback()
      },
    )

    await page.goto(distributionPath('/create'), {
      waitUntil: 'domcontentloaded',
    })
    const next = page.getByRole('button', { name: 'Continue' })
    await page.getByLabel('Name').fill('Acme Scanner')
    await next.click()
    await next.click()
    await page.getByLabel('Bundle ID').fill('com.acme.scanner')
    await page.getByLabel('Package name').fill('com.acme.scanner')
    await next.click()
    await page.getByRole('radio', { name: /Upload builds later/ }).click()
    await page.getByRole('button', { name: 'Create app' }).click()

    await expect(
      page.getByText('App created, but these targets were not added: iOS'),
    ).toBeVisible({ timeout: 30_000 })
    await expect(
      page.getByText('The bundle ID is already used by another app.'),
    ).toBeVisible()
    const appRequest = requests.find(
      (request) =>
        request.method === 'POST' && request.path === '/distribution/apps',
    )
    expect(appRequest?.body).not.toHaveProperty('installationId')
    await expect(page).toHaveURL(
      new RegExp(`/distribution/${String(appRequest?.body.appId)}/`),
    )
  })

  test('shows the Sidebar item when enabled', async ({ page }) => {
    await mockDistributionApi(page)
    await openList(page)

    await expect(sidebarLink(page, 'Distribution')).toBeVisible()
    await capture(page, 'distribution-sidebar.png')
  })

  test('hides the area when the server disables it', async ({ page }) => {
    await mockDistributionApi(page, { enabled: false })
    await page.goto(distributionPath(), { waitUntil: 'domcontentloaded' })

    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT.$id}(/overview)?/?$`),
      {
        timeout: 30_000,
      },
    )
    await expect(sidebarLink(page, 'Auth')).toBeVisible()
    await expect(sidebarLink(page, 'Distribution')).toHaveCount(0)
    await capture(page, 'distribution-flag-off-sidebar.png')
  })

  test('hides the area when the profile disables it', async ({ page }) => {
    await mockDistributionApi(page, { features: { distribution: false } })
    await page.goto(distributionPath(), { waitUntil: 'domcontentloaded' })

    await expect(page).toHaveURL(
      new RegExp(`/projects/${PROJECT.$id}(/overview)?/?$`),
      {
        timeout: 30_000,
      },
    )
    await expect(sidebarLink(page, 'Auth')).toBeVisible()
    await expect(sidebarLink(page, 'Distribution')).toHaveCount(0)
  })

  test('lists apps right to left in Hebrew', async ({ page }) => {
    await mockDistributionApi(page, { language: 'he' })
    await openList(page)

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    await expect(page.getByText('אין מאגר')).toBeVisible()
    await capture(page, 'distribution-apps-he.png')
  })
})
