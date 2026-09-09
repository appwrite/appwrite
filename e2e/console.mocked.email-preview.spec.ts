import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Email body: rendered preview next to the source.
 *
 * Sent messages cannot be created on demand, so the Appwrite API is mocked at
 * the network layer and the real page is asserted: which view a message opens
 * on, what the sandboxed frame renders, and that draft edits reach the preview.
 */

const NOW = '2026-09-09T09:30:00.000+00:00'

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

const RECIPIENT = {
  ...ACCOUNT,
  $id: 'user000000000000000002',
  name: 'Jordan Reader',
  email: 'jordan@example.com',
}

const ORGANIZATION = {
  $id: 'org00000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme Docs',
  billingPlan: 'tier-0',
  billingEmail: ACCOUNT.email,
  status: 'active',
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT = {
  $id: 'proj0000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme Docs',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
  services: [
    { $id: 'messaging', enabled: true },
    { $id: 'storage', enabled: true },
    { $id: 'users', enabled: true },
  ] as Models.Project['services'],
} satisfies Partial<Models.Project>

const TARGET = {
  $id: 'target00000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: '',
  userId: RECIPIENT.$id,
  providerId: '',
  providerType: 'email',
  identifier: RECIPIENT.email,
  expired: false,
} satisfies Models.Target

const MESSAGE_ID = 'msg00000000000000000001'

const EMAIL_HTML = `<!doctype html>
<html>
  <body style="margin:0;background:#f6f7fb;font-family:Arial,sans-serif;">
    <p>Hi Jordan,</p>
    <p>These files in your workspace will be deleted in less than two days.</p>
    <p><a href="https://example.com/workspace/">Open my workspace</a></p>
  </body>
</html>`

function emailMessage(status: 'draft' | 'sent'): Models.Message {
  return {
    $id: MESSAGE_ID,
    $createdAt: NOW,
    $updatedAt: NOW,
    providerType: 'email',
    topics: [],
    users: [],
    targets: [TARGET.$id],
    deliveredAt: status === 'sent' ? NOW : undefined,
    deliveryErrors: [],
    deliveredTotal: status === 'sent' ? 1 : 0,
    data: {
      subject: 'Your Acme Docs files expire in two days',
      content: EMAIL_HTML,
      html: true,
    },
    status,
  } as Models.Message
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

/** Every `/v1` call, on any region host, is answered here; the app itself is served live. */
async function mockAppwriteApi(page: Page, message: Models.Message) {
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

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') return json(200, {})
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/health/version') return json(200, { version: '1.8.0' })
    if (apiPath === '/locale')
      return json(200, {
        ip: '203.0.113.10',
        countryCode: 'DE',
        country: 'Germany',
        continentCode: 'EU',
        continent: 'Europe',
        eu: true,
        currency: 'EUR',
      })
    if (apiPath === '/console/variables') return json(200, {})
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, { roles: ['owner'], scopes: ['messages.write'] })
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
    if (apiPath === `/messaging/messages/${MESSAGE_ID}`)
      return json(200, message)
    if (apiPath === `/messaging/messages/${MESSAGE_ID}/targets`)
      return json(200, { total: 1, targets: [TARGET] })
    if (apiPath === '/messaging/topics')
      return json(200, { total: 0, topics: [] })
    if (apiPath === '/storage/buckets')
      return json(200, { total: 0, buckets: [] })
    if (apiPath === '/users') return json(200, { total: 1, users: [RECIPIENT] })
    if (apiPath === `/users/${RECIPIENT.$id}`) return json(200, RECIPIENT)

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })
}

async function openMessage(page: Page) {
  await page.goto(`/projects/${PROJECT.$id}/messaging/${MESSAGE_ID}`, {
    waitUntil: 'domcontentloaded',
  })
  await expect(
    page.getByRole('heading', { name: 'Content', exact: true }),
  ).toBeVisible({ timeout: 30_000 })
}

function previewFrame(page: Page) {
  return page.frameLocator('iframe[title="Email preview"]')
}

function viewOption(page: Page, name: 'Source' | 'Preview') {
  return page.getByRole('radio', { name })
}

test.describe('email body preview (mocked API)', () => {
  test('a sent HTML email opens on its rendered preview', async ({ page }) => {
    await mockAppwriteApi(page, emailMessage('sent'))
    await openMessage(page)

    await expect(viewOption(page, 'Preview')).toHaveAttribute(
      'data-state',
      'on',
    )
    await expect(
      previewFrame(page).getByRole('link', { name: 'Open my workspace' }),
    ).toBeVisible()
  })

  test('the preview frame is fully sandboxed', async ({ page }) => {
    await mockAppwriteApi(page, emailMessage('sent'))
    await openMessage(page)

    await expect(page.locator('iframe[title="Email preview"]')).toHaveAttribute(
      'sandbox',
      '',
    )
  })

  test('source shows the HTML that was sent, read-only', async ({ page }) => {
    await mockAppwriteApi(page, emailMessage('sent'))
    await openMessage(page)

    await viewOption(page, 'Source').click()

    const source = page.locator('#email-content')
    await expect(source).toHaveValue(EMAIL_HTML)
    await expect(source).toBeDisabled()
  })

  test('switching views does not move the rest of the card', async ({
    page,
  }) => {
    await mockAppwriteApi(page, emailMessage('sent'))
    await openMessage(page)

    // Document-absolute so the click's scroll-into-view cannot be read as a shift.
    const offsetFromTop = () =>
      page
        .locator('#email-html')
        .evaluate((el) => el.getBoundingClientRect().top + window.scrollY)

    const before = await offsetFromTop()
    await viewOption(page, 'Source').click()
    await expect(page.locator('#email-content')).toBeVisible()

    expect(await offsetFromTop()).toBe(before)
  })

  test('a draft opens on the source and previews unsaved edits', async ({
    page,
  }) => {
    await mockAppwriteApi(page, emailMessage('draft'))
    await openMessage(page)

    await expect(viewOption(page, 'Source')).toHaveAttribute('data-state', 'on')

    await page.locator('#email-content').fill('<h1>Hello from the draft</h1>')
    await viewOption(page, 'Preview').click()

    await expect(
      previewFrame(page).getByRole('heading', { name: 'Hello from the draft' }),
    ).toBeVisible()
  })
})
