import type { Page } from '@playwright/test'
import { test, expect } from './fixtures'
import { E2E_VIEWPORT } from './config/viewport'
import { discoverConsoleTargets } from './helpers/discovery'
import { ensureProjectActive } from './helpers/ensure-project-active'
import { newE2ePage } from './helpers/cookie-banner'
import { installUsageApiMock } from './fixtures/usage'
import { mapToQueryParam, type FilterMap } from '@/lib/table-filters'

async function mockUsageCapability(page: Page, value: 'enabled' | 'disabled') {
  await page.route(/\/v1\/console\/variables(?:\?|$)/, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ _APP_USAGE_STATS: value }),
    }),
  )
}

test.describe('self-hosted Usage hard navigation', () => {
  let projectId: string | null

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext({
      storageState: 'e2e/.auth/auth.json',
      viewport: E2E_VIEWPORT,
      screen: E2E_VIEWPORT,
    })
    const page = await newE2ePage(context)
    try {
      const targets = await discoverConsoleTargets(page)
      projectId = targets.projectId
      if (projectId) await ensureProjectActive(page, projectId)
    } finally {
      await context.close()
    }
  })

  test.beforeEach(() => {
    test.skip(!projectId, 'No project is available for self-hosted Usage.')
  })

  test('enabled backend survives direct navigation and reload', async ({
    page,
  }, testInfo) => {
    await mockUsageCapability(page, 'enabled')
    const requests = await installUsageApiMock(page)

    await page.goto(`/projects/${projectId}/usage/requests`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page).toHaveURL(
      new RegExp(`/projects/${projectId}/usage/requests`),
    )
    await expect(page.getByRole('heading', { name: 'Usage' })).toBeVisible()

    await page.reload({ waitUntil: 'domcontentloaded' })
    await expect(page).toHaveURL(
      new RegExp(`/projects/${projectId}/usage/requests`),
    )
    await expect(
      page.getByRole('heading', { name: 'Requests over time' }),
    ).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Cities' })).toHaveCount(0)
    expect(
      requests.every((request) => !request.params.dimensions?.includes('city')),
    ).toBeTruthy()

    const cityFilters: FilterMap = new Map([
      [{ c: 'city', o: 'equal', v: 'Paris' }, 'equal("city", ["Paris"])'],
    ])
    const cityQuery = mapToQueryParam(cityFilters)
    await page.goto(
      `/projects/${projectId}/usage/requests?${new URLSearchParams({ query: cityQuery })}`,
      { waitUntil: 'domcontentloaded' },
    )
    await expect
      .poll(() => new URL(page.url()).searchParams.get('query'))
      .toBeNull()
    expect(
      requests.every((request) =>
        (request.params.queries ?? []).every(
          (query) => !query.includes('city') && !query.includes('Paris'),
        ),
      ),
    ).toBeTruthy()

    await page.goto(`/projects/${projectId}/usage/bandwidth`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(
      page.getByRole('heading', { name: 'Bandwidth over time' }),
    ).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Cities' })).toHaveCount(0)
    expect(
      requests.every((request) => !request.params.dimensions?.includes('city')),
    ).toBeTruthy()

    await testInfo.attach('self-hosted-enabled-hard-navigation.png', {
      body: await page.screenshot({ fullPage: true, animations: 'disabled' }),
      contentType: 'image/png',
    })
    expect(requests.length).toBeGreaterThan(0)
  })

  test('explicitly disabled backend redirects after capability resolution', async ({
    page,
  }) => {
    await mockUsageCapability(page, 'disabled')
    await page.goto(`/projects/${projectId}/usage/requests`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page).toHaveURL(
      new RegExp(`/projects/${projectId}/overview/?$`),
    )
  })
})
