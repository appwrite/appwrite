import type { Page } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'
import { env } from './config/env'
import { appwriteApiPath } from './helpers/appwrite-url'

// Real transfer wizard and SDK, with deterministic API responses. No registrar,
// payment provider, or live transfer is contacted by this suite.
const ORG_ID = 'transfer-preview'
const now = '2026-01-01T00:00:00.000Z'
const organization = {
  $id: ORG_ID,
  $createdAt: now,
  $updatedAt: now,
  name: 'Transfer preview',
  total: 1,
  prefs: {},
  paymentMethodId: 'test-card',
  billingPlan: 'pro',
} satisfies Pick<
  Models.Organization<Models.Preferences>,
  | '$id'
  | '$createdAt'
  | '$updatedAt'
  | 'name'
  | 'total'
  | 'prefs'
  | 'paymentMethodId'
  | 'billingPlan'
>

// SDK types omit null even though the endpoint returns it on the wire.
type QuoteResponse = Omit<Models.DomainPrice, 'price'> & {
  price?: number | null
}
type Scenario = {
  price?: number | null
  error?: boolean
  delay?: Promise<void>
  quoteDomain?: string
  premium?: boolean
}

async function mockApi(page: Page, scenario: Scenario, language = 'en') {
  const transferRequests: unknown[] = []
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
    if (request.method() === 'OPTIONS')
      return route.fulfill({ status: 204, headers })
    const json = (body: unknown, status = 200) =>
      route.fulfill({ status, headers, json: body })
    const path = appwriteApiPath(request.url())
    if (path === '/domains/transfers/in' && request.method() === 'POST') {
      transferRequests.push(request.postDataJSON())
      // Stop at the payment boundary, without fabricating a completed transfer.
      return json(
        {
          message: 'Test stopped before payment',
          code: 400,
          type: 'general_argument_invalid',
        },
        400,
      )
    }
    if (request.method() !== 'GET')
      return json({ message: 'Unexpected write', code: 400 }, 400)
    if (path === '/domains/prices') {
      expect(new URL(request.url()).searchParams.get('registrationType')).toBe(
        'transfer',
      )
      await scenario.delay
      if (scenario.error)
        return json(
          { message: 'Pricing temporarily unavailable', code: 503 },
          503,
        )
      const domain =
        new URL(request.url()).searchParams.get('domains[0]') ??
        new URL(request.url()).searchParams.get('domains[]')!
      const quote: QuoteResponse = {
        domain: scenario.quoteDomain ?? domain,
        tld: domain.split('.').at(-1)!,
        available: false,
        price: scenario.price,
        periodYears: 1,
        premium: scenario.premium ?? false,
        renewalPrice: 52.79,
        renewalPeriodYears: 1,
      }
      return json({ prices: [quote], total: 1 })
    }
    if (path === '/account')
      return json({
        $id: 'preview-user',
        $createdAt: now,
        $updatedAt: now,
        registration: now,
        name: 'Preview User',
        email: 'preview@example.com',
        emailVerification: true,
        phoneVerification: false,
        phone: '',
        mfa: false,
        status: true,
        labels: [],
        passwordUpdate: now,
        accessedAt: now,
        targets: [],
        prefs: { organization: ORG_ID },
      } satisfies Models.User<Models.Preferences>)
    if (path === '/account/prefs') return json({ organization: ORG_ID })
    if (path === '/account/payment-methods')
      return json({
        total: 1,
        paymentMethods: [
          {
            $id: 'test-card',
            brand: 'visa',
            last4: '4242',
            expiryMonth: 12,
            expiryYear: 2030,
            name: 'Preview User',
          } satisfies Pick<
            Models.PaymentMethod,
            '$id' | 'brand' | 'last4' | 'expiryMonth' | 'expiryYear' | 'name'
          >,
        ],
      })
    if (path === '/organizations' || path === '/teams')
      return json({ total: 1, teams: [organization] })
    if (path === `/teams/${ORG_ID}`) return json(organization)
    if (path.endsWith('/scopes'))
      return json({
        roles: ['owner'],
        scopes: ['domains.read', 'domains.write'],
      })
    if (path.endsWith('/plan'))
      return json({ $id: 'pro', name: 'Pro', domains: 100 })
    if (path === '/domains') return json({ total: 0, domains: [] })
    if (path === '/projects') return json({ total: 0, projects: [] })
    if (path.endsWith('/memberships'))
      return json({ total: 0, memberships: [] })
    if (path.endsWith('/invoices')) return json({ total: 0, invoices: [] })
    if (path === '/account/sessions') return json({ total: 0, sessions: [] })
    return json(
      { message: 'Not found', code: 404, type: 'general_route_not_found' },
      404,
    )
  })
  await page.addInitScript((language) => {
    localStorage.setItem('debug:language', language)
    localStorage.setItem('theme', 'dark')
  }, language)
  return transferRequests
}

async function openWizard(page: Page, domain = 'ashkelon-news.co.il') {
  await page.goto(`/organizations/${ORG_ID}/domains/transfer-in`)
  await page.getByLabel('Domain name', { exact: true }).fill(domain)
  await page
    .getByLabel('Authorization code', { exact: true })
    .fill('test-only-code')
  await expect(page.getByRole('combobox')).toContainText('4242')
  return page.getByRole('button', { name: 'Start transfer', exact: true })
}

const unavailable =
  'Transfer pricing is unavailable for this domain. Try another domain or contact support.'

test.describe('domain transfer pricing (mocked API)', () => {
  test('incomplete domains keep the neutral summary without requesting prices', async ({
    page,
  }, testInfo) => {
    const requests = await mockApi(page, { price: 52.79 })
    const priceRequests: string[] = []
    page.on('request', (request) => {
      if (appwriteApiPath(request.url()) === '/domains/prices') {
        priceRequests.push(request.url())
      }
    })
    const start = await openWizard(page, '')
    await expect(page.locator('[data-fullscreen-loader]')).toBeHidden()
    await page.clock.install()
    await page.clock.pauseAt(new Date())
    for (const domain of ['', 'example', '.example', 'example.']) {
      await page.getByLabel('Domain name', { exact: true }).fill(domain)
      // Check both the immediate state and after the pricing debounce settles.
      for (const elapsed of [0, 600]) {
        await page.clock.runFor(elapsed)
        await expect(
          page.getByText('Enter your full domain name to load a price quote.'),
        ).toBeVisible()
        await expect(page.getByText(unavailable)).toBeHidden()
        await expect(page.getByRole('button', { name: 'Try again' })).toBeHidden()
        await expect(page.getByText('Total due today')).toBeHidden()
        await expect(start).toBeDisabled()
      }
    }
    expect(priceRequests).toHaveLength(0)
    expect(requests).toHaveLength(0)
    const path = testInfo.outputPath('transfer-incomplete.png')
    await page.screenshot({ path, fullPage: true })
    await testInfo.attach('Incomplete domain neutral summary', {
      path,
      contentType: 'image/png',
    })
    await page.clock.resume()
    await page.getByLabel('Domain name', { exact: true }).fill('example.com')
    await expect(start).toBeEnabled()
    await page.getByLabel('Domain name', { exact: true }).fill('example')
    await expect(
      page.getByText('Enter your full domain name to load a price quote.'),
    ).toBeVisible()
    await expect(page.getByText('Total due today')).toBeHidden()
    await expect(start).toBeDisabled()
  })

  test('initial quote must finish loading before transfer starts', async ({
    page,
  }) => {
    let resolve!: () => void
    const delay = new Promise<void>((done) => {
      resolve = done
    })
    const requests = await mockApi(page, { price: 52.79, delay })
    const pricingRequest = page.waitForRequest(
      (request) => appwriteApiPath(request.url()) === '/domains/prices',
    )
    const start = await openWizard(page)
    await pricingRequest
    await expect(start).toBeDisabled()
    await expect(page.getByText('Total due today')).toBeHidden()
    await start.dispatchEvent('click')
    expect(requests).toHaveLength(0)
    resolve()
    await expect(start).toBeEnabled()
  })

  for (const [language, message] of [
    [
      'he',
      'מחיר ההעברה אינו זמין עבור הדומיין הזה. נסו דומיין אחר או פנו לתמיכה.',
    ],
    [
      'ja',
      'このドメインの移管料金は取得できません。別のドメインを試すか、サポートにお問い合わせください。',
    ],
  ]) {
    test(`unavailable message is translated into ${language}`, async ({
      page,
    }) => {
      await mockApi(page, { price: null }, language)
      await page.goto(`/organizations/${ORG_ID}/domains/transfer-in`)
      await page.locator('#td-domain').fill('ashkelon-news.co.il')
      await expect(page.getByText(message)).toBeVisible()
      await expect(page.getByText(unavailable)).toBeHidden()
    })
  }

  for (const [name, price] of [
    ['null', null],
    ['zero', 0],
    ['missing', undefined],
    ['negative', -1],
  ] as const) {
    test(`${name} quote blocks transfer`, async ({ page }, testInfo) => {
      const requests = await mockApi(page, { price, premium: name === 'zero' })
      const start = await openWizard(page)
      await expect(page.getByText(unavailable)).toBeVisible()
      await expect(start).toBeDisabled()
      await expect(page.getByText('Total due today')).toBeHidden()
      await start.dispatchEvent('click')
      await expect(start).toBeDisabled()
      expect(requests).toHaveLength(0)
      if (name === 'null') {
        const path = testInfo.outputPath('transfer-unavailable.png')
        await page.screenshot({ path, fullPage: true })
        await testInfo.attach('Transfer pricing unavailable', {
          path,
          contentType: 'image/png',
        })
      }
    })
  }

  test('failed quote can be retried before continuing', async ({ page }) => {
    const scenario: Scenario = { error: true, price: 52.79 }
    const requests = await mockApi(page, scenario)
    const start = await openWizard(page)
    await expect(
      page.getByText(
        "We couldn't load a transfer price. Try again to continue.",
      ),
    ).toBeVisible()
    await expect(start).toBeDisabled()
    expect(requests).toHaveLength(0)
    scenario.error = false
    await page.getByRole('button', { name: 'Try again' }).click()
    await expect(start).toBeEnabled()
  })

  test('loading and edited domains cannot reuse a previous quote', async ({
    page,
  }) => {
    const scenario: Scenario = { price: 52.79 }
    const requests = await mockApi(page, scenario)
    const start = await openWizard(page, 'ashkelon.news')
    await expect(start).toBeEnabled()
    let resolve!: () => void
    scenario.delay = new Promise<void>((done) => {
      resolve = done
    })
    scenario.price = null
    await page.clock.install()
    await page.clock.pauseAt(new Date())
    await page
      .getByLabel('Domain name', { exact: true })
      .fill('ashkelon-news.co.il')
    // Freeze inside the 500ms debounce window: the old positive quote exists.
    await expect(start).toBeDisabled()
    await expect(page.getByText('Total due today')).toBeHidden()
    await page.clock.runFor(600)
    await expect(start).toBeDisabled()
    expect(requests).toHaveLength(0)
    resolve()
    await page.clock.resume()
    await expect(page.getByText(unavailable)).toBeVisible()
    await expect(start).toBeDisabled()
  })

  test('a quote for another domain cannot authorize the current domain', async ({
    page,
  }) => {
    const requests = await mockApi(page, {
      price: 52.79,
      quoteDomain: 'other.example',
    })
    const start = await openWizard(page)
    await expect(
      page.getByText(
        "We couldn't load a transfer price. Try again to continue.",
      ),
    ).toBeVisible()
    await expect(start).toBeDisabled()
    expect(requests).toHaveLength(0)
  })

  test('positive current quote reaches the mocked transfer boundary', async ({
    page,
  }, testInfo) => {
    const requests = await mockApi(page, { price: 52.79 })
    const start = await openWizard(page, 'ashkelon.news')
    await expect(start).toBeEnabled()
    await expect(page.getByText('Total due today')).toBeVisible()
    await expect(page.getByText('$52.79', { exact: true })).toHaveCount(2)
    const path = testInfo.outputPath('transfer-priced.png')
    await page.screenshot({ path, fullPage: true })
    await testInfo.attach('Positive transfer quote', {
      path,
      contentType: 'image/png',
    })
    await start.click()
    await expect(page.getByText('Test stopped before payment')).toBeVisible()
    expect(requests).toHaveLength(1)
    expect(requests[0]).toMatchObject({
      domain: 'ashkelon.news',
      organizationId: ORG_ID,
      authCode: 'test-only-code',
      paymentMethodId: 'test-card',
    })
  })
})
