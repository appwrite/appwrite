import { test, expect } from './fixtures'
import { E2E_VIEWPORT } from './config/viewport'
import {
  discoverConsoleTargets,
} from './helpers/discovery'
import { ensureProjectActive } from './helpers/ensure-project-active'
import { newE2ePage } from './helpers/cookie-banner'
import {
  assertUsageRequestContract,
  attachUsageEvidence,
  installUsageApiMock,
  type UsageEndpoint,
} from './fixtures/usage'

const categories = [
  {
    id: 'requests',
    label: 'Requests',
    cardTitle: 'Requests over time',
    endpoint: 'events',
    metric: 'network.requests',
  },
  {
    id: 'bandwidth',
    label: 'Bandwidth',
    cardTitle: 'Bandwidth over time',
    endpoint: 'events',
    metric: 'network.inbound',
  },
  {
    id: 'compute',
    label: 'Compute',
    cardTitle: 'Executions',
    endpoint: 'events',
    metric: 'executions',
  },
  {
    id: 'realtime',
    label: 'Realtime',
    cardTitle: 'Concurrent connections',
    endpoint: 'gauges',
    metric: 'realtime.connections',
  },
  {
    id: 'webhooks',
    label: 'Webhooks',
    cardTitle: 'Events sent',
    endpoint: 'events',
    metric: 'webhooks.events.sent',
  },
  {
    id: 'auth',
    label: 'Auth',
    cardTitle: 'Monthly active users',
    endpoint: 'gauges',
    metric: 'users.mau',
  },
  {
    id: 'databases',
    label: 'Databases',
    cardTitle: 'Database reads',
    endpoint: 'events',
    metric: 'databases.operations.reads',
  },
  {
    id: 'storage',
    label: 'Storage',
    cardTitle: 'Buckets',
    endpoint: 'gauges',
    metric: 'storage',
  },
  {
    id: 'functions',
    label: 'Functions',
    cardTitle: 'Function executions',
    endpoint: 'events',
    metric: 'functions.executions',
  },
  {
    id: 'messaging',
    label: 'Messaging',
    cardTitle: 'Messages sent',
    endpoint: 'events',
    metric: 'messages.sent',
  },
  {
    id: 'sites',
    label: 'Sites',
    cardTitle: 'Site executions',
    endpoint: 'events',
    metric: 'sites.executions',
  },
  {
    id: 'avatars',
    label: 'Avatars',
    cardTitle: 'Screenshots generated',
    endpoint: 'events',
    metric: 'avatars.screenshotsGenerated',
  },
] as const satisfies ReadonlyArray<{
  id: string
  label: string
  cardTitle: string
  endpoint: UsageEndpoint
  metric: string
}>

test.describe('project usage contract (mocked)', () => {
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
    test.skip(
      !projectId,
      'No project is available for usage contract coverage.',
    )
  })

  for (const category of categories) {
    test(`${category.id} sends the expected usage contract`, async ({
      page,
    }, testInfo) => {
      const requests = await installUsageApiMock(page)
      await page.goto(`/projects/${projectId}/usage/${category.id}`, {
        waitUntil: 'domcontentloaded',
      })

      await expect(page).toHaveURL(
        new RegExp(`/projects/${projectId}/usage/${category.id}`),
      )
      await expect(page.locator('#main-content')).toBeVisible()
      await expect(
        page
          .getByRole('heading', { name: category.label, exact: true })
          .first(),
      ).toBeVisible()
      await expect(
        page
          .getByRole('heading', { name: category.cardTitle, exact: true })
          .first(),
      ).toBeVisible()
      await expect
        .poll(() => requests.length, { message: 'usage request count' })
        .toBeGreaterThan(0)

      for (const request of requests) {
        assertUsageRequestContract(request, projectId!)
      }
      expect(
        requests.some(
          (request) =>
            request.endpoint === category.endpoint &&
            request.params.metrics?.includes(category.metric),
        ),
      ).toBeTruthy()

      if (category.id === 'realtime') {
        const connectionRequest = requests.find((request) =>
          request.params.metrics?.includes('realtime.connections'),
        )
        expect(connectionRequest?.params.aggregate).toEqual(['max'])
      }

      await attachUsageEvidence(
        page,
        testInfo,
        requests,
        `usage-${category.id}`,
      )
    })
  }

  test('enables self-hosted usage from Console variables', async ({
    page,
  }, testInfo) => {
    await page.addInitScript(() => {
      localStorage.setItem(
        'debug:consoleProfile',
        JSON.stringify({
          id: 'self-hosted',
          label: 'Self-hosted',
          description: 'Self-hosted Appwrite',
          features: {},
        }),
      )
    })
    await page.route(/\/v1\/console\/variables(?:\?|$)/, (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ _APP_USAGE_STATS: 'enabled' }),
      }),
    )
    const requests = await installUsageApiMock(page)

    await page.goto(`/projects/${projectId}/usage/requests`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(page).toHaveURL(
      new RegExp(`/projects/${projectId}/usage/requests`),
    )
    await expect.poll(() => requests.length).toBeGreaterThan(0)
    await attachUsageEvidence(
      page,
      testInfo,
      requests,
      'usage-self-hosted-enabled',
    )
  })

  test('preserves the explicit self-hosted disabled state', async ({
    page,
  }) => {
    await page.addInitScript(() => {
      localStorage.setItem(
        'debug:consoleProfile',
        JSON.stringify({
          id: 'self-hosted',
          label: 'Self-hosted',
          description: 'Self-hosted Appwrite',
          features: {},
        }),
      )
    })
    await page.route(/\/v1\/console\/variables(?:\?|$)/, (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ _APP_USAGE_STATS: 'disabled' }),
      }),
    )

    await page.goto(`/projects/${projectId}/usage/requests`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(page).toHaveURL(
      new RegExp(`/projects/${projectId}/overview/?$`),
    )
  })

  test('shows loading cards while the usage response is pending', async ({
    page,
  }, testInfo) => {
    let releaseResponse!: () => void
    const responseGate = new Promise<void>((resolve) => {
      releaseResponse = resolve
    })
    const requests = await installUsageApiMock(page, { responseGate })
    await page.goto(`/projects/${projectId}/usage/requests`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(
      page.getByRole('status', { name: 'Loading usage data' }).first(),
    ).toBeVisible()
    await attachUsageEvidence(page, testInfo, requests, 'usage-loading')
    releaseResponse()
    await expect.poll(() => requests.length).toBeGreaterThan(0)
  })

  test('renders empty, zero, fractional, and missing metric series', async ({
    page,
  }, testInfo) => {
    // Both usage endpoints echo an entry for every requested metric, so the
    // real "nothing recorded" signal is an empty points array, never a missing
    // metric. `topics` is a gauge: the collector snapshots it every interval
    // and writes an explicit zero when the level is zero, so no samples means
    // the level is unknown. `messages.sms.sent` is an event: a row exists only
    // when a message is sent, so no samples is a genuine zero.
    const requests = await installUsageApiMock(page, {
      values: [0, 0.375],
      emptyMetrics: ['topics', 'messages.sms.sent'],
    })
    await page.goto(`/projects/${projectId}/usage/messaging`, {
      waitUntil: 'domcontentloaded',
    })

    const messagesCard = page
      .getByRole('heading', { name: 'Messages sent', exact: true })
      .locator('xpath=ancestor::div[contains(@class,"rounded-lg")][1]')
    const topicsCard = page
      .getByRole('heading', { name: 'Topics', exact: true })
      .locator('xpath=ancestor::div[contains(@class,"rounded-lg")][1]')
    const smsCard = page
      .getByRole('heading', { name: 'SMS messages', exact: true })
      .locator('xpath=ancestor::div[contains(@class,"rounded-lg")][1]')

    await expect(messagesCard).not.toContainText('No data for this date range')
    await expect(messagesCard).toContainText('0')
    await expect(topicsCard).toContainText('No data for this date range')
    await expect(smsCard).not.toContainText('No data for this date range')
    await expect(smsCard).toContainText('0')
    await expect.poll(() => requests.length).toBeGreaterThan(0)
    await attachUsageEvidence(page, testInfo, requests, 'usage-series-shapes')
  })

  test('updates date ranges and intervals without losing the chart', async ({
    page,
  }, testInfo) => {
    const requests = await installUsageApiMock(page)
    await page.goto(`/projects/${projectId}/usage/requests`, {
      waitUntil: 'domcontentloaded',
    })
    await expect.poll(() => requests.length).toBeGreaterThan(0)

    const initialCount = requests.length
    const dateRangeTrigger = page
      .getByRole('button', { name: /^(Last |Today|Yesterday|Custom)/i })
      .first()
    await expect(dateRangeTrigger).toBeVisible()
    const activePreset = (await dateRangeTrigger.textContent())?.trim() ?? ''
    await dateRangeTrigger.click()
    const targetPreset = /7 days/i.test(activePreset)
      ? 'Last 30 days'
      : 'Last 7 days'
    await page.getByRole('button', { name: targetPreset, exact: true }).click()
    await expect.poll(() => requests.length).toBeGreaterThan(initialCount)

    const latest = requests.at(-1)!
    const start = Date.parse(latest.params.startAt[0]!)
    const end = Date.parse(latest.params.endAt[0]!)
    expect(end - start).toBeGreaterThanOrEqual(6 * 24 * 60 * 60 * 1000)

    // Switching interval only refetches when the value actually changes, and
    // which options are selectable depends on the date range chosen above.
    const intervalGroup = page.getByRole('group', { name: 'Chart interval' })
    const switchable = intervalGroup.locator(
      '[role="radio"]:not([disabled]):not([aria-checked="true"])',
    )
    if ((await switchable.count()) > 0) {
      const target = switchable.first()
      const interval = (await target.textContent())?.trim()
      const countBeforeInterval = requests.length
      await target.click()
      await expect
        .poll(() => requests.length)
        .toBeGreaterThan(countBeforeInterval)
      expect(requests.at(-1)?.params.interval).toEqual([interval])
    }

    await attachUsageEvidence(page, testInfo, requests, 'usage-date-interval')
  })

  test('opens a breakdown and exports CSV', async ({ page }, testInfo) => {
    const requests = await installUsageApiMock(page, {
      values: Array.from({ length: 10 }, (_, index) => index + 1),
    })
    await page.goto(`/projects/${projectId}/usage/requests`, {
      waitUntil: 'domcontentloaded',
    })

    const showMore = page.getByRole('button', { name: 'Show more' }).first()
    await expect(showMore).toBeVisible()
    await showMore.click()
    const exportButton = page.getByRole('button', { name: 'Export' })
    await expect(exportButton).toBeEnabled()
    await exportButton.click()
    const downloadPromise = page.waitForEvent('download')
    await page.getByRole('menuitem', { name: 'Export as CSV' }).click()
    const download = await downloadPromise
    await testInfo.attach('usage-breakdown-download.txt', {
      body: Buffer.from(download.suggestedFilename()),
      contentType: 'text/plain',
    })
    await attachUsageEvidence(page, testInfo, requests, 'usage-breakdown')
  })

  for (const status of [401, 403, 404, 422, 429] as const) {
    test(`renders the ${status} error state`, async ({ page }, testInfo) => {
      const requests = await installUsageApiMock(page, { status })
      await page.goto(`/projects/${projectId}/usage/requests`, {
        waitUntil: 'domcontentloaded',
      })

      await expect(
        page.getByText(/couldn't load|could not load|unavailable/i).first(),
      ).toBeVisible()
      await expect.poll(() => requests.length).toBeGreaterThan(0)
      await attachUsageEvidence(
        page,
        testInfo,
        requests,
        `usage-error-${status}`,
      )
    })
  }

  test('shows an error and retries with one new request', async ({
    page,
  }, testInfo) => {
    const requests = await installUsageApiMock(page, {
      status: 500,
      failuresBeforeSuccess: 1,
    })

    await page.goto(`/projects/${projectId}/usage/requests`, {
      waitUntil: 'domcontentloaded',
    })
    const retry = page.getByRole('button', { name: 'Try again' }).first()
    await expect(retry).toBeVisible()
    const beforeRetry = requests.length
    await retry.click()
    await expect.poll(() => requests.length).toBeGreaterThan(beforeRetry)
    await attachUsageEvidence(page, testInfo, requests, 'usage-error-retry')
  })

  test('retains responsive controls without horizontal overflow', async ({
    page,
  }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 })
    const requests = await installUsageApiMock(page)
    await page.goto(`/projects/${projectId}/usage/requests`, {
      waitUntil: 'domcontentloaded',
    })

    await expect(
      page.getByRole('heading', { name: 'Usage', exact: true, level: 1 }),
    ).toBeVisible()
    const overflow = await page.evaluate(
      () =>
        document.documentElement.scrollWidth >
        document.documentElement.clientWidth,
    )
    expect(overflow).toBeFalsy()
    await attachUsageEvidence(page, testInfo, requests, 'usage-mobile')
  })

  test('keeps date and interval controls keyboard accessible', async ({
    page,
  }, testInfo) => {
    const requests = await installUsageApiMock(page)
    await page.goto(`/projects/${projectId}/usage/requests`, {
      waitUntil: 'domcontentloaded',
    })

    const interval = page.getByRole('group', { name: 'Chart interval' })
    await expect(interval).toBeVisible()
    const selectableInterval = interval
      .locator('[role="radio"]:not([disabled])')
      .first()
    await selectableInterval.focus()
    await expect(selectableInterval).toBeFocused()
    await expect(page.locator('body')).not.toHaveCSS('overflow-x', 'scroll')
    await attachUsageEvidence(page, testInfo, requests, 'usage-accessibility')
  })
})
