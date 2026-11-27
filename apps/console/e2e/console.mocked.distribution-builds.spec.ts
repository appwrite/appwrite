import type { Page, Request, Route } from '@playwright/test'
import type { DistributionBuild } from '@/lib/appwrite/distribution'
import {
  DISTRIBUTION_IDS,
  NOW,
  appsFixtures,
  buildsFixtures,
  distributionPath,
  expect,
  mockDistributionApi,
  screenshot,
  test,
  type DistributionMock,
} from './fixtures/distribution'

const [SHOP, REWARDS, FIELD_NOTES] = appsFixtures()
const { shopActive, shopReady, fieldNotesFailed } = DISTRIBUTION_IDS.builds
const DAY = 24 * 60 * 60 * 1000

type Query = { method: string; attribute?: string; values?: unknown[] }

function buildsPath(appId: string): string {
  return distributionPath(`/${appId}/builds`)
}

function buildPath(appId: string, buildId: string): string {
  return `${buildsPath(appId)}/${buildId}`
}

function logsPath(appId: string, buildId: string): string {
  return `/distribution/apps/${appId}/builds/${buildId}/logs`
}

function queries(request: Request): Query[] {
  return [...new URL(request.url()).searchParams]
    .filter(([key]) => key.startsWith('queries['))
    .map(([, value]) => JSON.parse(value) as Query)
}

function logRequests(mock: DistributionMock, appId: string, buildId: string) {
  return mock.requests.filter(
    (request) =>
      request.method === 'GET' && request.path === logsPath(appId, buildId),
  ).length
}

function olderBuilds(count: number): DistributionBuild[] {
  const template = buildsFixtures().find((build) => build.$id === shopReady)!
  const statuses: DistributionBuild['status'][] = [
    'ready',
    'failed',
    'canceled',
  ]
  return Array.from({ length: count }, (_, index) => ({
    ...template,
    $id: `build${String(100 + index).padStart(19, '0')}`,
    $createdAt: new Date(Date.parse(NOW) - (index + 2) * DAY).toISOString(),
    status: statuses[index % statuses.length],
    versionName: `1.${index}.0`,
    buildNumber: String(index + 1),
    artifactId: '',
    releaseId: '',
  }))
}

function failBuildsList(route: Route) {
  const request = route.request()
  if (request.method() !== 'GET') return route.fallback()
  return route.fulfill({
    status: 500,
    headers: {
      'access-control-allow-origin': request.headers()['origin'] ?? '*',
      'access-control-allow-credentials': 'true',
      'content-type': 'application/json',
    },
    body: JSON.stringify({
      message: 'The distribution service is unavailable.',
      code: 500,
      type: 'general_server_error',
      version: '2.0.0',
    }),
  })
}

async function screenshotWholePage(page: Page, name: string) {
  const viewport = page.viewportSize()
  await page.setViewportSize({ width: viewport?.width ?? 1920, height: 2000 })
  await screenshot(page, name)
  if (viewport) await page.setViewportSize(viewport)
}

test.describe('distribution builds', () => {
  test('lists builds with their badges and pages through them', async ({
    page,
  }) => {
    await mockDistributionApi(page, {
      seed: { builds: [...buildsFixtures(), ...olderBuilds(12)] },
    })

    await page.goto(buildsPath(SHOP.$id))

    const rows = page.locator('[data-testid^="build-row-"]')
    await expect(rows).toHaveCount(10)
    const active = page.getByTestId(`build-row-${shopActive}`)
    await expect(
      active.getByText('1.5.0 (18)', { exact: true }),
    ).toHaveAttribute('dir', 'ltr')
    await expect(active).toContainText('Android')
    await expect(active).toContainText('Building')
    await expect(active).toContainText('main')
    await expect(active).toContainText('9f1c2e7')
    const ready = page.getByTestId(`build-row-${shopReady}`)
    await expect(ready).toContainText('1.4.0 (42)')
    await expect(ready).toContainText('iOS')
    await expect(ready).toContainText('Ready')
    await expect(ready).toContainText('10m 12s')
    await expect(rows.nth(3)).toContainText('Failed')
    await expect(rows.nth(4)).toContainText('Canceled')
    await expect(
      page.getByRole('button', { name: 'Create build' }),
    ).toBeEnabled()

    await screenshot(page, 'builds-list.png')

    const second = page.waitForRequest(
      (request) =>
        request.url().includes(`/apps/${SHOP.$id}/builds?`) &&
        queries(request).some(
          (query) => query.method === 'offset' && query.values?.[0] === 10,
        ),
    )
    await page.getByRole('button', { name: 'Go to next page' }).click()
    await second
    await expect(rows).toHaveCount(4)
    await expect(page.getByTestId(`build-row-${shopActive}`)).toHaveCount(0)

    await rows.first().click()
    await expect(page).toHaveURL(
      new RegExp(`${buildPath(SHOP.$id, 'build0000000000000000108')}$`),
    )
  })

  test('falls back to the last page when the current page empties', async ({
    page,
  }) => {
    const older = olderBuilds(9)
    const last = older[older.length - 1]
    await mockDistributionApi(page, {
      seed: { builds: [...buildsFixtures(), ...older] },
    })
    await page.goto(buildsPath(SHOP.$id))
    const rows = page.locator('[data-testid^="build-row-"]')
    await expect(rows).toHaveCount(10)
    await page.getByRole('button', { name: 'Go to next page' }).click()
    await expect(rows).toHaveCount(1)
    const row = page.getByTestId(`build-row-${last.$id}`)
    await expect(row).toBeVisible()

    await row.getByRole('button', { name: 'Actions' }).click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Delete', exact: true })
      .click()

    await expect(rows).toHaveCount(10)
    await expect(page.getByTestId(`build-row-${shopActive}`)).toBeVisible()
    await expect(page.getByTestId(`build-row-${last.$id}`)).toHaveCount(0)
    await expect(page.getByText('No builds yet')).toHaveCount(0)
  })

  test('shows a load error and recovers with try again', async ({ page }) => {
    await mockDistributionApi(page)
    const list = (url: URL) =>
      url.pathname.endsWith(`/distribution/apps/${SHOP.$id}/builds`)
    await page.route(list, failBuildsList)

    await page.goto(buildsPath(SHOP.$id))

    await expect(page.getByText('Failed to load builds')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible()
    await expect(page.getByText('No builds yet')).toHaveCount(0)
    await screenshot(page, 'builds-error.png')

    await page.unroute(list, failBuildsList)
    await page.getByRole('button', { name: 'Try again' }).click()

    await expect(page.getByTestId(`build-row-${shopActive}`)).toBeVisible()
    await expect(page.getByText('Failed to load builds')).toHaveCount(0)
  })

  test('shows a logs load error and recovers with try again', async ({
    page,
  }) => {
    await mockDistributionApi(page)
    const logs = (url: URL) =>
      url.pathname.endsWith(logsPath(FIELD_NOTES.$id, fieldNotesFailed))
    await page.route(logs, failBuildsList)

    await page.goto(buildPath(FIELD_NOTES.$id, fieldNotesFailed))

    const card = page.getByTestId('build-logs')
    await expect(card.getByTestId('build-logs-error')).toContainText(
      'Failed to load logs',
    )
    await expect(card).not.toContainText('This build has no logs.')
    await screenshotWholePage(page, 'build-logs-error.png')

    await page.unroute(logs, failBuildsList)
    await card.getByRole('button', { name: 'Try again' }).click()

    await expect(card).toContainText('[012]')
    await expect(card.getByTestId('build-logs-error')).toHaveCount(0)
  })

  test('updates a list badge from realtime', async ({ page }) => {
    const mock = await mockDistributionApi(page)
    await page.goto(buildsPath(SHOP.$id))
    const active = page.getByTestId(`build-row-${shopActive}`)
    await expect(active).toContainText('Building')
    await mock.realtime.connected()

    mock.change('builds', shopActive, { status: 'signing' })

    await expect(active).toContainText('Signing')
  })

  test('shows the tail of the logs and loads earlier pages', async ({
    page,
  }) => {
    await mockDistributionApi(page)

    await page.goto(buildPath(SHOP.$id, shopActive))

    const summary = page.getByTestId('build-summary')
    await expect(summary).toContainText('Building')
    await expect(summary).toContainText('com.acme.shop')
    await expect(summary).toContainText('Add saved carts')
    const logs = page.getByTestId('build-logs')
    await expect(logs).toContainText('[150]')
    await expect(logs).toContainText('[051]')
    await expect(logs).not.toContainText('[050]')

    await screenshotWholePage(page, 'build-detail.png')

    const earlier = page.waitForRequest(
      (request) =>
        request.url().includes(`/builds/${shopActive}/logs?`) &&
        queries(request).some((query) => query.method === 'cursorAfter'),
    )
    await page.getByRole('button', { name: 'Load earlier logs' }).click()
    const request = await earlier
    expect(queries(request)).toEqual(
      expect.arrayContaining([
        { method: 'orderDesc', attribute: 'sequence' },
        { method: 'orderDesc', attribute: 'chunk' },
        { method: 'limit', values: [100] },
        { method: 'cursorAfter', values: ['0003log000051'] },
      ]),
    )
    await expect(logs).toContainText('[001]')
    await expect(logs).toContainText('[050]')
    await expect(
      page.getByRole('button', { name: 'Load earlier logs' }),
    ).toHaveCount(0)
  })

  test('appends live logs and refetches the gap of a split event', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    await page.goto(buildPath(SHOP.$id, shopActive))
    const logs = page.getByTestId('build-logs')
    await expect(logs).toContainText('[150]')
    await mock.realtime.connected()
    const before = logRequests(mock, SHOP.$id, shopActive)

    mock.appendLog(shopActive, 'Live line one\n')

    await expect(logs).toContainText('Live line one')
    mock.appendLog(shopActive, 'Live line two\n')
    await expect(logs).toContainText('Live line two')
    expect(logRequests(mock, SHOP.$id, shopActive)).toBe(before)

    const rows = mock.appendLog(shopActive, 'GAP-ONE+GAP-TWO+GAP-SIX\n', {
      chunks: 3,
    })

    expect(rows.map((row) => row.content)).toEqual([
      'GAP-ONE+',
      'GAP-TWO+',
      'GAP-SIX\n',
    ])
    await expect
      .poll(() => logRequests(mock, SHOP.$id, shopActive))
      .toBeGreaterThan(before)
    await expect(logs).toContainText('GAP-ONE+GAP-TWO+GAP-SIX')
  })

  test('follows the build status from realtime and refetches the logs once it ends', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    await page.goto(buildPath(SHOP.$id, shopActive))
    const summary = page.getByTestId('build-summary')
    await expect(summary).toContainText('Building')
    await mock.realtime.connected()
    const before = logRequests(mock, SHOP.$id, shopActive)

    mock.change('builds', shopActive, {
      status: 'ready',
      endedAt: new Date().toISOString(),
      duration: 312,
    })

    await expect(summary).toContainText('Ready')
    await expect(summary).toContainText('5m 12s')
    await expect(
      page.getByRole('button', { name: 'Cancel build' }),
    ).toHaveCount(0)
    await expect
      .poll(() => logRequests(mock, SHOP.$id, shopActive))
      .toBe(before + 1)
  })

  test('creates a build with the version code of a manual target', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    await page.goto(buildsPath(SHOP.$id))

    await page.getByRole('button', { name: 'Create build' }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.locator('#create-build-target')).toContainText('iOS')
    await expect(dialog.locator('#create-build-reference')).toHaveValue('main')
    await expect(dialog.locator('#create-build-version-code')).toHaveCount(0)

    await dialog.locator('#create-build-target').click()
    await page.getByRole('option', { name: /Android/ }).click()
    const versionCode = dialog.locator('#create-build-version-code')
    await expect(versionCode).toHaveValue('18')
    const invalid = dialog.getByText(
      'Enter a whole number from 1 to 2,100,000,000.',
    )
    await versionCode.fill('')
    await expect(
      dialog.getByRole('button', { name: 'Create', exact: true }),
    ).toBeDisabled()
    await expect(invalid).toHaveCount(0)
    await versionCode.fill('2100000001')
    await expect(invalid).toBeVisible()
    await expect(versionCode).toHaveAttribute('aria-invalid', 'true')
    await expect(
      dialog.getByRole('button', { name: 'Create', exact: true }),
    ).toBeDisabled()
    await versionCode.fill('25')
    await expect(invalid).toHaveCount(0)
    await dialog.locator('#create-build-version-name').fill('1.6.0')

    await screenshot(page, 'build-create.png')

    await dialog.getByRole('button', { name: 'Create', exact: true }).click()

    await expect(page).toHaveURL(
      new RegExp(`${buildsPath(SHOP.$id)}/[0-9a-f]{20}$`),
    )
    const created = mock.requests.find(
      (request) =>
        request.method === 'POST' &&
        request.path === `/distribution/apps/${SHOP.$id}/builds`,
    )
    expect(created?.body).toEqual({
      targetId: DISTRIBUTION_IDS.targets.shopAndroid,
      type: 'branch',
      reference: 'main',
      versionName: '1.6.0',
      versionCode: 25,
    })
    await expect(page.getByTestId('build-summary')).toContainText('Waiting')
  })

  test('builds a tag reference', async ({ page }) => {
    const mock = await mockDistributionApi(page)
    await page.goto(buildsPath(SHOP.$id))

    await page.getByRole('button', { name: 'Create build' }).click()
    const dialog = page.getByRole('dialog')
    await dialog.locator('#create-build-reference-type').click()
    await page.getByRole('option', { name: 'Tag', exact: true }).click()
    await expect(dialog.locator('#create-build-reference')).toHaveValue('')
    await dialog.locator('#create-build-reference').fill('v1.4.0')
    await dialog.getByRole('button', { name: 'Create', exact: true }).click()

    await expect(page).toHaveURL(
      new RegExp(`${buildsPath(SHOP.$id)}/[0-9a-f]{20}$`),
    )
    expect(
      mock.requests.find((request) => request.method === 'POST')?.body,
    ).toEqual({
      targetId: DISTRIBUTION_IDS.targets.shopIos,
      type: 'tag',
      reference: 'v1.4.0',
    })
  })

  test('retries a build from its commit', async ({ page }) => {
    const mock = await mockDistributionApi(page)
    await page.goto(buildsPath(SHOP.$id))

    await page
      .getByTestId(`build-row-${shopReady}`)
      .getByRole('button', { name: 'Actions' })
      .click()
    await expect(
      page.getByRole('menuitem', { name: 'Cancel build' }),
    ).toHaveCount(0)
    await page.getByRole('menuitem', { name: 'Retry' }).click()

    await expect(page).toHaveURL(
      new RegExp(`${buildsPath(SHOP.$id)}/[0-9a-f]{20}$`),
    )
    expect(
      mock.requests.find((request) => request.method === 'POST')?.body,
    ).toEqual({
      targetId: DISTRIBUTION_IDS.targets.shopIos,
      type: 'commit',
      reference: 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
      releaseId: DISTRIBUTION_IDS.releases.active,
    })
  })

  test('cancels an active build', async ({ page }) => {
    const mock = await mockDistributionApi(page)
    await page.goto(buildPath(SHOP.$id, shopActive))

    await page.getByRole('button', { name: 'Cancel build' }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toContainText('The build stops and its logs are kept.')
    await dialog.getByRole('button', { name: 'Cancel build' }).click()

    await expect(page.getByTestId('build-summary')).toContainText('Canceled')
    expect(
      mock.requests.find((request) => request.method === 'PATCH'),
    ).toMatchObject({
      path: `/distribution/apps/${SHOP.$id}/builds/${shopActive}/status`,
      body: { status: 'canceled' },
    })
  })

  test('shows why a build failed and deletes it', async ({ page }) => {
    const mock = await mockDistributionApi(page)
    await page.goto(buildPath(FIELD_NOTES.$id, fieldNotesFailed))

    const error = page.getByTestId('build-error')
    await expect(error).toContainText('Build failed')
    await expect(error).toContainText(
      "Gradle task 'bundleProductionRelease' failed with exit code 1.",
    )
    await expect(error).toContainText('build_failed')
    await expect(page.getByTestId('build-logs')).toContainText('[012]')

    await page.getByRole('button', { name: 'Delete', exact: true }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toContainText('Delete build')
    await dialog.getByRole('button', { name: 'Delete', exact: true }).click()

    await expect(page).toHaveURL(new RegExp(`${buildsPath(FIELD_NOTES.$id)}$`))
    expect(
      mock.requests.some(
        (request) =>
          request.method === 'DELETE' &&
          request.path ===
            `/distribution/apps/${FIELD_NOTES.$id}/builds/${fieldNotesFailed}`,
      ),
    ).toBe(true)
    await expect(page.getByText('No builds yet')).toBeVisible()
  })

  test('disables writes for a read-only role', async ({ page }) => {
    await mockDistributionApi(page, { role: 'readonly' })
    await page.goto(buildsPath(SHOP.$id))

    await expect(
      page.getByRole('button', { name: 'Create build' }),
    ).toBeDisabled()
    await page
      .getByTestId(`build-row-${shopActive}`)
      .getByRole('button', { name: 'Actions' })
      .click()
    for (const name of ['Cancel build', 'Retry', 'Delete']) {
      await expect(page.getByRole('menuitem', { name })).toHaveAttribute(
        'data-disabled',
        '',
      )
    }
    await expect(
      page.getByRole('menuitem', { name: 'Logs' }),
    ).not.toHaveAttribute('data-disabled')
    await page.keyboard.press('Escape')

    await page.goto(buildPath(SHOP.$id, shopActive))
    for (const name of ['Cancel build', 'Retry', 'Delete']) {
      await expect(
        page.getByRole('button', { name, exact: true }),
      ).toBeDisabled()
    }
    await page
      .getByRole('button', { name: 'Retry', exact: true })
      .locator('..')
      .hover()
    await expect(page.getByRole('tooltip')).toContainText(
      "You don't have permission to perform this action.",
    )
  })

  test('disables creating builds for an app without a repository', async ({
    page,
  }) => {
    await mockDistributionApi(page)
    await page.goto(buildsPath(REWARDS.$id))

    await expect(page.getByText('No builds yet')).toBeVisible()
    const create = page.getByRole('button', { name: 'Create build' })
    await expect(create).toBeDisabled()
    await create.locator('..').focus()
    await expect(page.getByRole('tooltip')).toContainText(
      'Connect a repository to the app to start builds.',
    )
  })

  test('renders the build detail in Hebrew', async ({ page }) => {
    await mockDistributionApi(page, { language: 'he' })
    await page.goto(buildPath(SHOP.$id, shopActive))

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    await expect(
      page
        .getByRole('heading', { level: 2, name: '1.5.0 (18)' })
        .locator('[dir="ltr"]'),
    ).toHaveText('1.5.0 (18)')
    await expect(page.getByTestId('build-summary')).toContainText('סיכום')
    await expect(
      page.getByRole('button', { name: 'טעינת לוגים קודמים' }),
    ).toBeVisible()
    await expect(page.getByTestId('build-logs')).toContainText('[150]')

    await screenshotWholePage(page, 'build-detail-he.png')
  })
})
