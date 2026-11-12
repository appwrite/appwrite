import type { Page } from '@playwright/test'
import { expect, test } from './fixtures'
import { env } from './config/env'
import { appwriteApiPath } from './helpers/appwrite-url'
import { CONSOLE_SESSION_COOKIE_NAME } from '../src/lib/console-session-cookie'

const orgId = 'recorded-lines'
const invoiceId = 'current-invoice'
const invoice = {
  $id: invoiceId,
  $updatedAt: '2026-11-01T00:00:00.000+00:00',
  grossAmount: 0,
  creditsUsed: 0,
  currency: 'USD',
  status: 'pending',
  dueAt: '2026-12-01T00:00:00.000+00:00',
  items: [
    {
      type: 'plan',
      description: 'Historical plan price',
      amount: 2500,
      from: '2026-11-01T00:00:00.000+00:00',
      to: '2026-12-01T00:00:00.000+00:00',
    },
    {
      type: 'usage',
      description: 'Recorded project bandwidth',
      amount: 1001,
      projectId: 'project-a',
      resourceId: 'bandwidth',
      quantity: 1500000000,
      from: '2026-10-01T00:00:00.000+00:00',
      to: '2026-11-01T00:00:00.000+00:00',
    },
    { type: 'discount', description: 'Usage budget cap', amount: -1000 },
    { type: 'rounding', description: 'Recorded rounding', amount: -1 },
    { type: 'credit', description: 'Trial offset', amount: -2500 },
  ],
}

// Deliberately conflicting amounts: aggregation is usage context, not a price source.
const aggregation = {
  $id: 'mutable-aggregation',
  $updatedAt: invoice.$updatedAt,
  amount: 6543,
  resources: [],
  breakdown: [
    {
      $id: 'project-a',
      name: 'Production API',
      amount: 888,
      resources: [
        { resourceId: 'bandwidth', value: 1500000000, amount: 777 },
        { resourceId: 'storage', value: 600000000, amount: 666 },
      ],
    },
  ],
}

async function mockBilling(
  page: Page,
  currentInvoice: unknown,
  historyInvoice?: object,
  usage: object | null | number = aggregation,
) {
  await page.context().addCookies([
    {
      name: CONSOLE_SESSION_COOKIE_NAME,
      value: 'mock-session',
      url: 'http://localhost:4173',
    },
  ])
  await page.route(`${env.VITE_APPWRITE_ENDPOINT}/**`, async (route) => {
    const request = route.request()
    const headers = {
      'access-control-allow-origin':
        request.headers().origin ?? 'http://localhost:4173',
      'access-control-allow-credentials': 'true',
      'access-control-allow-methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
      'access-control-allow-headers':
        request.headers()['access-control-request-headers'] ?? '*',
    }
    if (request.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers })
    }
    const path = appwriteApiPath(request.url())
    const organization = {
      $id: orgId,
      name: 'Recorded org',
      billingPlan: 'tier-1',
      billingInvoiceId: invoiceId,
      billingAggregationId: 'mutable-aggregation',
    }
    const responses: Record<string, unknown> = {
      '/account': {
        $id: 'owner',
        name: 'Owner',
        email: 'owner@example.com',
        emailVerification: true,
        status: true,
        $createdAt: '2026-01-01T00:00:00.000+00:00',
        prefs: {},
        labels: [],
        targets: [],
      },
      '/account/prefs': {},
      '/health/version': { version: '1.9.0' },
      '/locale': { countryCode: 'US', eu: false },
      '/console/variables': {},
      '/organization/projects': { total: 0, projects: [] },
      '/account/billing-addresses': { total: 0, billingAddresses: [] },
      [`/organizations/${orgId}/credits`]: { total: 0, credits: [] },
      [`/teams/${orgId}`]: organization,
      '/teams': { total: 1, teams: [organization] },
      '/account/sessions': { total: 0, sessions: [] },
      '/account/payment-methods': { total: 0, paymentMethods: [] },
      '/organizations': { total: 1, teams: [organization] },
      [`/organizations/${orgId}`]: organization,
      [`/organizations/${orgId}/plan`]: {
        $id: 'tier-1',
        name: 'Pro',
        price: 999,
        selfService: true,
        usagePerProject: true,
        bandwidth: 1,
        storage: 2,
        users: 0, // A zero-cap resource remains visible; it is not an unset limit.
      },
      [`/organizations/${orgId}/aggregations/mutable-aggregation`]: usage,
      [`/organizations/${orgId}/invoices`]: {
        total: historyInvoice ? 1 : 0,
        invoices: historyInvoice ? [historyInvoice] : [],
      },
      [`/organizations/${orgId}/invoices/${invoiceId}`]: currentInvoice,
    }
    if (path === '/account/prefs' && request.method() === 'PATCH')
      responses[path] = {
        ...(responses['/account'] as object),
        prefs: request.postDataJSON().prefs ?? {},
      }
    if (
      path === `/organizations/${orgId}/aggregations/mutable-aggregation` &&
      usage
    ) {
      if (typeof usage === 'number') {
        return route.fulfill({
          status: usage,
          headers,
          contentType: 'application/json',
          body: JSON.stringify({
            message: 'Usage temporarily unavailable',
            code: usage,
          }),
        })
      }
      const query = new URL(request.url()).searchParams
      const limit = Number(query.get('limit') ?? 10)
      if (limit > 10) {
        return route.fulfill({
          status: 400,
          headers,
          contentType: 'application/json',
          body: JSON.stringify({
            message: 'Invalid limit: maximum is 10',
            code: 400,
          }),
        })
      }
      const offset = Number(query.get('offset') ?? 0)
      const context = usage as typeof aggregation
      responses[path] = {
        ...context,
        breakdown: context.breakdown.slice(offset, offset + limit),
      }
    }
    const found = path in responses && responses[path] !== null
    return route.fulfill({
      status: found ? 200 : 404,
      headers,
      contentType: 'application/json',
      body: JSON.stringify(
        found ? responses[path] : { message: 'Not found', code: 404 },
      ),
    })
  })
  await page.goto(`/organizations/${orgId}/settings/billing`)
  await expect(page.locator('[aria-label="Loading"]')).toBeHidden({
    timeout: 30_000,
  })
  return page.locator('#current-cycle-usage')
}

test('keeps project expanders and usage bars while showing only saved charges', async ({
  page,
}) => {
  const summary = await mockBilling(page, invoice)
  await expect(summary.getByText('Historical plan price')).toBeVisible()
  const projectButton = summary.getByRole('button', { name: /Production API/ })
  await expect(projectButton).toHaveAttribute('aria-expanded', 'false')
  await projectButton.click()
  await expect(projectButton).toHaveAttribute('aria-expanded', 'true')
  const project = projectButton.locator('..')
  await expect(project.getByRole('progressbar').first()).toBeVisible()
  await expect(project.getByText('1.5 GB / 1 GB')).toBeVisible()
  await expect(project.getByText('MAU', { exact: true })).toBeVisible()
  await expect(summary.getByText('Recorded project bandwidth')).toBeVisible()
  await expect(
    summary
      .getByText('Recorded project bandwidth')
      .locator('..')
      .locator('..')
      .locator('..')
      .getByText(/Oct 1, 2026/),
  ).toBeVisible()
  await expect(
    summary
      .getByText('Historical plan price')
      .locator('..')
      .locator('..')
      .getByText(/Dec 1, 2026/),
  ).toBeVisible()
  await expect(summary.getByText('Usage budget cap')).toBeVisible()
  await expect(summary.getByText('Recorded rounding')).toBeVisible()
  await expect(summary.getByText('Trial offset')).toBeVisible()
  await expect(summary.getByText('-$10.00', { exact: true })).toBeVisible()
  await expect(summary.getByText('-$0.01', { exact: true })).toBeVisible()
  await expect(
    project.getByRole('link', { name: 'Usage details' }),
  ).toHaveAttribute('href', '/projects/project-a/usage')
  await expect(summary.getByText('$0.00', { exact: true })).toBeVisible()
  await expect(summary.getByText('$999.00')).toHaveCount(0)
  await expect(summary.getByText('$777.00', { exact: true })).toHaveCount(0)
  await expect(summary.getByText('$888.00', { exact: true })).toHaveCount(0)
  await expect(summary.getByText('$6,543.00', { exact: true })).toHaveCount(0)
  await summary.screenshot({
    path: test.info().outputPath('recorded-lines.png'),
  })
})

test('applies only recorded account credits once', async ({ page }) => {
  const summary = await mockBilling(page, {
    ...invoice,
    items: invoice.items.slice(0, 4),
    creditsUsed: 5,
  })
  await expect(summary.getByText('Credits applied')).toBeVisible()
  await expect(summary.getByText('-$5.00', { exact: true })).toBeVisible()
  await expect(
    summary
      .getByText('Total', { exact: true })
      .locator('..')
      .getByText('$20.00', { exact: true }),
  ).toBeVisible()
})

test('shows a recorded-total fallback for a legacy invoice', async ({
  page,
}) => {
  const summary = await mockBilling(page, {
    ...invoice,
    items: [],
    status: 'succeeded',
    grossAmount: 49.5,
    creditsUsed: 5,
  })
  await expect(
    summary.getByText('Recorded charges; detailed pricing unavailable.'),
  ).toBeVisible()
  await expect(summary.getByText('$54.50', { exact: true })).toBeVisible()
  await expect(summary.getByText('$49.50', { exact: true })).toBeVisible()
  await expect(summary.getByText('Historical plan price')).toHaveCount(0)
})

test('does not invent charges when no invoice exists yet', async ({ page }) => {
  const summary = await mockBilling(page, null)
  await expect(summary.getByText('No invoice yet.')).toBeVisible()
  await expect(summary.getByText('Total', { exact: true })).toHaveCount(0)
})

test('does not quote an unpriced legacy draft as a zero charge', async ({
  page,
}) => {
  const summary = await mockBilling(page, { ...invoice, items: [], amount: 25 })
  await expect(
    summary.getByText('Invoice breakdown unavailable.'),
  ).toBeVisible()
  await expect(summary.getByText('Total', { exact: true })).toHaveCount(0)
})

test('preserves a paid zero invoice total in payment history', async ({
  page,
}) => {
  await mockBilling(page, invoice, {
    ...invoice,
    $id: 'paid-invoice',
    amount: 999,
    grossAmount: 0,
    status: 'succeeded',
    $createdAt: '2026-11-01T00:00:00.000+00:00',
  })
  const history = page.locator('#payment-history')
  await expect(history.getByText('$0.00', { exact: true })).toBeVisible()
  await expect(history.getByText('$999.00')).toHaveCount(0)
})

test('keeps saved project charges and usage quantities when aggregation is unavailable', async ({
  page,
}) => {
  const summary = await mockBilling(page, invoice, undefined, null)
  await summary.getByRole('button', { name: /project-a/ }).click()
  await expect(summary.getByText('Recorded project bandwidth')).toBeVisible()
  await expect(summary.getByText('1.5 GB / 1 GB')).toBeVisible()
  await expect(summary.getByText('$10.01', { exact: true })).toHaveCount(2)
})

test('keeps saved charges visible through a usage outage and retries only usage', async ({
  page,
}) => {
  let invoiceRequests = 0
  page.on('request', (request) => {
    if (
      appwriteApiPath(request.url()) ===
      `/organizations/${orgId}/invoices/${invoiceId}`
    )
      invoiceRequests++
  })
  const summary = await mockBilling(page, invoice, undefined, 503)
  await expect(
    summary.getByText('Usage unavailable', { exact: true }),
  ).toBeVisible()
  await expect(summary.getByText('Historical plan price')).toBeVisible()
  await expect(summary.getByText('Usage budget cap')).toBeVisible()
  await expect(
    summary.getByText('Total', { exact: true }).locator('..'),
  ).toContainText('$0.00')
  const project = summary.getByRole('button', { name: /project-a/ })
  await expect(project).toContainText('$10.01')
  await project.click()
  await expect(summary.getByText('Recorded project bandwidth')).toBeVisible()
  const initialInvoiceRequests = invoiceRequests
  await page.route(
    `${env.VITE_APPWRITE_ENDPOINT}/organizations/${orgId}/aggregations/mutable-aggregation**`,
    async (route) => {
      await route.fulfill({
        status: 200,
        headers: {
          'access-control-allow-origin': 'http://localhost:4173',
          'access-control-allow-credentials': 'true',
          'access-control-allow-methods': 'GET,OPTIONS',
          'access-control-allow-headers':
            route.request().headers()['access-control-request-headers'] ?? '*',
        },
        contentType: 'application/json',
        body: JSON.stringify(aggregation),
      })
    },
  )
  await summary.getByRole('button', { name: 'Try again', exact: true }).click()
  await expect(
    summary.getByText('Usage unavailable', { exact: true }),
  ).toBeHidden()
  await expect(
    summary.getByRole('button', { name: /Production API/ }),
  ).toHaveAttribute('aria-expanded', 'true')
  await expect(summary.getByRole('progressbar').first()).toBeVisible()
  await expect(summary.getByText('Recorded project bandwidth')).toBeVisible()
  await expect(
    summary.getByText('Total', { exact: true }).locator('..'),
  ).toContainText('$0.00')
  expect(invoiceRequests).toBe(initialInvoiceRequests)
})

test('keeps unmapped project line items in the project subtotal and details', async ({
  page,
}) => {
  const summary = await mockBilling(page, {
    ...invoice,
    items: [
      ...invoice.items,
      {
        type: 'addon',
        projectId: 'project-a',
        description: 'Recorded custom charge',
        amount: 123,
      },
    ],
  })
  const button = summary.getByRole('button', { name: /Production API/ })
  await expect(button).toContainText('$11.24')
  await button.click()
  await expect(summary.getByText('Recorded custom charge')).toBeVisible()
  await expect(
    summary
      .getByText('Recorded custom charge')
      .locator('..')
      .getByText('$1.23'),
  ).toBeVisible()
})

test('keeps named projects and saved charges beyond the first aggregation page', async ({
  page,
}) => {
  const projects = Array.from({ length: 11 }, (_, index) => ({
    ...aggregation.breakdown[0],
    $id: `project-${index + 1}`,
    name: `Project ${index + 1}`,
  }))
  const summary = await mockBilling(
    page,
    {
      ...invoice,
      items: invoice.items.map((item) =>
        item.projectId ? { ...item, projectId: 'project-11' } : item,
      ),
    },
    undefined,
    { ...aggregation, breakdown: projects },
  )
  await expect(
    summary.getByRole('button', { name: 'Project 1 -', exact: true }),
  ).toBeVisible()
  await summary
    .getByRole('button', { name: 'Go to next page', exact: true })
    .click()
  const project = summary.getByRole('button', {
    name: 'Project 11 $10.01',
    exact: true,
  })
  await expect(project).toBeVisible()
  await project.click()
  await expect(summary.getByText('Recorded project bandwidth')).toBeVisible()
  await expect(summary.getByText('$10.01', { exact: true })).toHaveCount(2)
})
