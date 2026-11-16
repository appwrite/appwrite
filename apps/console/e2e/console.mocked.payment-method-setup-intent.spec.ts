import type { Page, Route } from '@playwright/test'
import { expect, test } from './fixtures'
import { env } from './config/env'
import { appwriteApiPath } from './helpers/appwrite-url'

/**
 * Opening "Add payment method" with a leftover incomplete payment method
 * (mocked API and Stripe.js).
 *
 * The form reuses that row's SetupIntent. A canceled one still mounts the card
 * form but can never be confirmed, so the form must drop the row and create a
 * new one; a live one must be reused.
 */

const ORG_ID = 'org-setup-intent'
const DEAD_SECRET = 'seti_dead_secret_x'
const NEW_SECRET = 'seti_new_secret_y'

const ACCOUNT = {
  $id: 'user-owner',
  name: 'Ola Owner',
  email: 'ola.owner@example.com',
}

const ORGANIZATION = {
  $id: ORG_ID,
  name: 'Setup Intent Org',
  billingPlan: 'tier-1',
  paymentMethodId: '',
  backupPaymentMethodId: '',
}

const INCOMPLETE = {
  $id: 'method-incomplete',
  providerMethodId: '',
  clientSecret: DEAD_SECRET,
  brand: '',
  last4: '',
  expiryMonth: 0,
  expiryYear: 0,
  expired: false,
  failed: false,
  userId: ACCOUNT.$id,
}

const CREATED = { ...INCOMPLETE, $id: 'method-new', clientSecret: NEW_SECRET }

const NOT_FOUND = {
  message: 'Not found',
  code: 404,
  type: 'general_route_not_found',
  version: '1.0',
}

type MockState = {
  methods: (typeof INCOMPLETE)[]
  deleted: string[]
  created: number
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

    if (method === 'POST' && path === '/account/payment-methods') {
      state.created += 1
      state.methods.push(CREATED)
      return json(201, CREATED)
    }
    if (method === 'DELETE' && path.startsWith('/account/payment-methods/')) {
      const id = path.split('/').pop()!
      state.deleted.push(id)
      state.methods = state.methods.filter((m) => m.$id !== id)
      return route.fulfill({ status: 204, headers })
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
      return json(200, {
        total: state.methods.length,
        paymentMethods: state.methods,
      })
    }
    if (path === '/organizations') {
      return json(200, { total: 1, teams: [ORGANIZATION] })
    }
    if (path === `/organizations/${ORG_ID}`) return json(200, ORGANIZATION)

    return json(404, NOT_FOUND)
  })
}

/** Fake Stripe.js: the dead secret reports `deadStatus`, any other is live. */
async function mockStripe(page: Page, deadStatus: string) {
  const script = `
    window.__STRIPE_PUBLISHABLE_KEY__ = 'pk_test_mocked'
    window.Stripe = function () {
      return {
        retrieveSetupIntent: async (secret) => ({
          setupIntent: {
            id: 'seti_mocked',
            client_secret: secret,
            status: secret === ${JSON.stringify(DEAD_SECRET)}
              ? ${JSON.stringify(deadStatus)}
              : 'requires_payment_method',
          },
        }),
        elements: (options) => ({
          create: () => ({
            mount: (container) => {
              container.setAttribute('data-mocked-secret', options.clientSecret)
            },
            unmount: () => {},
            on: () => {},
          }),
          submit: async () => ({}),
        }),
      }
    }
  `
  await page.addInitScript(script)
  // window.Stripe is already set, so loadStripe should not inject this; stub it anyway.
  await page.route('https://js.stripe.com/**', (route) =>
    route.fulfill({ contentType: 'application/javascript', body: script }),
  )
}

async function openAddPaymentMethod(page: Page) {
  await page.goto(`/organizations/${ORG_ID}/settings/billing`, {
    waitUntil: 'domcontentloaded',
  })
  await expect(page.getByText('No payment method on file')).toBeVisible({
    timeout: 30_000,
  })
  await page.getByRole('button', { name: 'Add payment method' }).first().click()
  const dialog = page.getByRole('dialog')
  await expect(
    dialog.getByRole('heading', { name: 'Add payment method' }),
  ).toBeVisible()
  return dialog
}

test.describe('add payment method with an incomplete one on file (mocked API)', () => {
  test('a canceled setup intent is replaced with a new payment method', async ({
    page,
  }) => {
    const state: MockState = { methods: [INCOMPLETE], deleted: [], created: 0 }
    await mockApis(page, state)
    await mockStripe(page, 'canceled')

    const dialog = await openAddPaymentMethod(page)

    await expect(
      dialog.locator(`[data-mocked-secret="${NEW_SECRET}"]`),
    ).toBeAttached()
    expect(state.deleted).toEqual([INCOMPLETE.$id])
    expect(state.created).toBe(1)
  })

  test('a live setup intent is reused', async ({ page }) => {
    const state: MockState = { methods: [INCOMPLETE], deleted: [], created: 0 }
    await mockApis(page, state)
    await mockStripe(page, 'requires_payment_method')

    const dialog = await openAddPaymentMethod(page)

    await expect(
      dialog.locator(`[data-mocked-secret="${DEAD_SECRET}"]`),
    ).toBeAttached()
    expect(state.deleted).toEqual([])
    expect(state.created).toBe(0)
  })
})
