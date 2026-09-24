import type { Page, Route } from '@playwright/test'
import { expect, test } from './fixtures'
import { env } from './config/env'
import { appwriteApiPath } from './helpers/appwrite-url'

/**
 * Paying an invoice that is waiting on card authentication (mocked API).
 *
 * The invoice's stored PaymentIntent was created off-session against the org's
 * default card months earlier. By the time the owner acts on it the bank may
 * have declined that card, which leaves the intent needing a new payment
 * method. "Authorize payment" must therefore start a fresh payment with a card
 * the owner picks, not confirm the stored client secret.
 */

const ORG_ID = 'org-authorize'
const INVOICE_ID = 'invoice-august'

const ACCOUNT = {
  $id: 'user-owner',
  name: 'Ola Owner',
  email: 'ola.owner@example.com',
}

const OLD_CARD = {
  $id: 'card-old',
  providerMethodId: 'pm_old',
  brand: 'mastercard',
  last4: '1111',
  expiryMonth: 12,
  expiryYear: 2030,
  expired: false,
  failed: false,
  userId: ACCOUNT.$id,
}

const NEW_CARD = {
  ...OLD_CARD,
  $id: 'card-new',
  providerMethodId: 'pm_new',
  brand: 'visa',
  last4: '4242',
}

const ORGANIZATION = {
  $id: ORG_ID,
  name: 'Authorize Org',
  billingPlan: 'tier-1',
  paymentMethodId: OLD_CARD.$id,
  backupPaymentMethodId: '',
}

const INVOICE = {
  $id: INVOICE_ID,
  $createdAt: '2026-07-09T00:04:16.276+00:00',
  $updatedAt: '2026-08-09T01:03:05.296+00:00',
  teamId: ORG_ID,
  aggregationId: 'aggregation-august',
  amount: 195,
  grossAmount: 195,
  currency: 'USD',
  dueAt: '2026-08-08T00:00:00.000+00:00',
  status: 'requires_authentication',
  clientSecret: 'pi_stale_secret_declined',
  lastError: '',
}

const NOT_FOUND = {
  message: 'Not found',
  code: 404,
  type: 'general_route_not_found',
  version: '1.0',
}

type MockState = {
  payments: { path: string; paymentMethodId: string }[]
  stripeRequests: string[]
}

function corsHeaders(route: Route): Record<string, string> {
  const request = route.request()
  const origin = request.headers()['origin'] ?? 'http://localhost:4173'
  const requested = request.headers()['access-control-request-headers']
  return {
    'access-control-allow-origin': origin,
    'access-control-allow-credentials': 'true',
    'access-control-allow-methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
    'access-control-allow-headers':
      requested ?? 'content-type,x-appwrite-project,x-appwrite-response-format',
    'access-control-expose-headers': 'x-appwrite-session',
  }
}

async function mockApis(page: Page, state: MockState) {
  await page.route('https://api.stripe.com/**', async (route) => {
    state.stripeRequests.push(route.request().url())
    await route.abort()
  })

  await page.route(`${env.VITE_APPWRITE_ENDPOINT}/**`, async (route) => {
    const request = route.request()
    const method = request.method()
    const headers = corsHeaders(route)

    if (method === 'OPTIONS') {
      await route.fulfill({ status: 204, headers })
      return
    }

    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })

    const path = appwriteApiPath(request.url())
    const now = new Date().toISOString()

    if (
      method === 'POST' &&
      path === `/organizations/${ORG_ID}/invoices/${INVOICE_ID}/payments`
    ) {
      const body = request.postDataJSON() as { paymentMethodId: string }
      state.payments.push({ path, paymentMethodId: body.paymentMethodId })
      // The new card needs no challenge, so the payment settles immediately.
      return json(200, { ...INVOICE, status: 'succeeded', clientSecret: '' })
    }

    if (method !== 'GET') return json(404, NOT_FOUND)

    if (path === '/account') {
      return json(200, {
        ...ACCOUNT,
        $createdAt: now,
        $updatedAt: now,
        registration: now,
        status: true,
        labels: [],
        passwordUpdate: now,
        phone: '',
        emailVerification: true,
        phoneVerification: false,
        mfa: false,
        prefs: {},
        targets: [],
        accessedAt: now,
      })
    }
    if (path === '/account/prefs') return json(200, {})
    if (path === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (path === '/account/payment-methods') {
      return json(200, { total: 2, paymentMethods: [OLD_CARD, NEW_CARD] })
    }
    if (path === '/organizations') {
      return json(200, { total: 1, teams: [ORGANIZATION] })
    }
    if (path === `/organizations/${ORG_ID}`) return json(200, ORGANIZATION)
    if (path === `/organizations/${ORG_ID}/invoices`) {
      return json(200, { total: 1, invoices: [INVOICE] })
    }
    if (path === `/organizations/${ORG_ID}/payment-methods/${OLD_CARD.$id}`) {
      return json(200, OLD_CARD)
    }
    if (path === `/organizations/${ORG_ID}/payment-methods/${NEW_CARD.$id}`) {
      return json(200, NEW_CARD)
    }

    return json(404, NOT_FOUND)
  })
}

test.describe('invoice awaiting authentication (mocked API)', () => {
  test('authorizing pays with the card the owner picks, not the stored intent', async ({
    page,
  }) => {
    const state: MockState = { payments: [], stripeRequests: [] }
    await mockApis(page, state)
    await page.goto(
      `/organizations/${ORG_ID}/settings/billing#payment-history`,
      {
        waitUntil: 'domcontentloaded',
      },
    )

    await page
      .getByRole('button', { name: /^Authorize/ })
      .click({ timeout: 30_000 })

    const dialog = page.getByRole('dialog')
    await expect(
      dialog.getByRole('heading', { name: 'Retry payment' }),
    ).toBeVisible()

    // The org default is the card the bank declined; switch to the new one.
    await dialog.getByRole('combobox').click()
    await page.getByRole('option').filter({ hasText: NEW_CARD.last4 }).click()
    await dialog.getByRole('button', { name: 'Retry', exact: true }).click()

    await expect
      .poll(() => state.payments)
      .toEqual([
        {
          path: `/organizations/${ORG_ID}/invoices/${INVOICE_ID}/payments`,
          paymentMethodId: NEW_CARD.$id,
        },
      ])
    await expect(dialog).toBeHidden()
    // Nothing tried to confirm the stored, already-declined intent.
    expect(state.stripeRequests).toEqual([])
  })
})
