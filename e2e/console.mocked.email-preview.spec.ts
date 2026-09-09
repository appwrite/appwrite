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

// The scenario is an owner with every console permission and every service
// on, so nothing about navigation or gating stands between the specs and the
// two pages they open. Spelled out here rather than imported, so a change to
// the console's defaults shows up as a failure instead of rewriting the mock.
const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'databases.read',
  'databases.write',
  'tables.write',
  'collections.write',
  'rows.write',
  'documents.write',
  'functions.read',
  'functions.write',
  'buckets.read',
  'buckets.write',
  'keys.write',
  'platforms.write',
  'webhooks.write',
  'users.write',
  'teams.read',
  'teams.write',
  'messages.read',
  'messages.write',
  'topics.write',
  'providers.write',
  'subscribers.write',
  'sites.read',
  'sites.write',
  'domains.write',
  'executions.write',
  'migrations.write',
  'vcs.write',
  'rules.write',
  'events.read',
  'billing.read',
] satisfies Models.Roles['scopes']
const ALL_SERVICES = [
  'account',
  'avatars',
  'databases',
  'tablesdb',
  'locale',
  'health',
  'project',
  'storage',
  'teams',
  'users',
  'vcs',
  'sites',
  'functions',
  'proxy',
  'graphql',
  'migrations',
  'messaging',
  'advisor',
  'oauth2',
] as const

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
  // The SDK types service ids as an enum, so the literals need the cast.
  services: ALL_SERVICES.map(($id) => ({
    $id,
    enabled: true,
  })) as Models.Project['services'],
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

const TEMPLATE_HTML = `<!doctype html>
<html>
  <body style="font-family:Arial,sans-serif;">
    <p>Hello {{user}},</p>
    <p>Sign in to your {{b}}{{project}}{{/b}} account.</p>
    <p><a href="{{redirect}}">Verify email</a></p>
  </body>
</html>`

const EMAIL_HTML = `<!doctype html>
<html>
  <body style="margin:0;background:#f6f7fb;font-family:Arial,sans-serif;">
    <p>Hi Jordan,</p>
    <p>These files in your workspace will be deleted in less than two days.</p>
    <p><a href="https://example.com/workspace/">Open my workspace</a></p>
  </body>
</html>`

const HOSTILE_HTML = `<!doctype html>
<html>
  <body>
    <script>
      window.parent.document.body.setAttribute('data-escaped', 'script')
    </script>
    <img src="broken.png" onerror="window.top.document.body.setAttribute('data-escaped', 'onerror')">
    <a id="popup" href="https://example.com/popup" target="_blank">popup</a>
    <a id="top" href="https://example.com/top" target="_top">top</a>
    <form id="form" action="https://example.com/steal" method="post">
      <button id="submit" type="submit">submit</button>
    </form>
    <p id="marker">hostile body rendered</p>
  </body>
</html>`

function emailMessage(
  status: 'draft' | 'sent',
  content: string = EMAIL_HTML,
): Models.Message {
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
      content,
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
async function mockAppwriteApi(
  page: Page,
  message: Models.Message,
  options: { smtpEnabled?: boolean } = {},
) {
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
      return json(200, { roles: OWNER_ROLES, scopes: OWNER_SCOPES })
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
      return json(200, {
        ...PROJECT,
        smtpEnabled: options.smtpEnabled ?? false,
      })
    if (apiPath === '/locale/codes')
      return json(200, { total: 0, localeCodes: [] })
    if (apiPath.startsWith('/project/templates/email/'))
      return json(200, {
        type: apiPath.split('/').pop(),
        locale: 'en',
        message: TEMPLATE_HTML,
        senderName: 'Acme Docs',
        senderEmail: 'noreply@acme.example',
        replyTo: '',
        subject: 'Verify your email',
      })
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

function sourceEditor(page: Page, name: 'Body' | 'Message') {
  return page.getByRole('group', { name })
}

async function openTemplates(page: Page) {
  await page.goto(`/projects/${PROJECT.$id}/auth/templates`, {
    waitUntil: 'domcontentloaded',
  })
  await expect(viewOption(page, 'Preview')).toBeVisible({ timeout: 30_000 })
}

test.describe('email body preview (mocked API)', () => {
  test('a sent HTML email opens on its rendered preview', async ({ page }) => {
    await mockAppwriteApi(page, emailMessage('sent'))
    await openMessage(page)

    await expect(viewOption(page, 'Preview')).toBeChecked()
    await expect(
      previewFrame(page).getByRole('link', { name: 'Open my workspace' }),
    ).toBeVisible()
  })

  test('a hostile body renders but cannot reach the console', async ({
    page,
  }) => {
    await mockAppwriteApi(page, emailMessage('sent', HOSTILE_HTML))
    await openMessage(page)

    const consoleUrl = page.url()
    const popups: string[] = []
    page.on('popup', (popup) => popups.push(popup.url()))
    const smuggled = page
      .waitForRequest((r) => r.url().startsWith('https://example.com/'), {
        timeout: 4_000,
      })
      .catch(() => null)

    // The body is on screen, so anything below fails on containment, not on rendering.
    await expect(previewFrame(page).locator('#marker')).toBeVisible()

    for (const id of ['popup', 'top', 'submit']) {
      await previewFrame(page)
        .locator(`#${id}`)
        .click({ timeout: 5_000 })
        .catch(() => undefined)
    }

    expect(await page.locator('body').getAttribute('data-escaped')).toBeNull()
    expect(page.url()).toBe(consoleUrl)
    expect(popups).toEqual([])
    expect(await smuggled).toBeNull()
  })

  test('source shows the HTML that was sent, read-only', async ({ page }) => {
    await mockAppwriteApi(page, emailMessage('sent'))
    await openMessage(page)

    await viewOption(page, 'Source').click()

    const source = sourceEditor(page, 'Body')
    await expect(source).toContainText('<!doctype html>')

    // Read-only is asserted by behaviour: typing into it changes nothing.
    await source.click()
    await page.keyboard.type('BREAK')
    await expect(source).not.toContainText('BREAK')
    await expect(page.getByText('Cannot edit in read-only editor')).toBeHidden()
  })

  test('switching views does not move the rest of the card', async ({
    page,
  }) => {
    await mockAppwriteApi(page, emailMessage('sent'))
    await openMessage(page)

    // Gap between the subject field and the row under the body, so neither the
    // page nor an inner container scrolling can be read as a layout shift.
    const gapUnderSubject = () =>
      page.evaluate(() => {
        const subject = document.querySelector('#email-subject')
        const row = document.querySelector('#email-html')
        if (!subject || !row) throw new Error('layout probes missing')
        return Math.round(
          row.getBoundingClientRect().top -
            subject.getBoundingClientRect().bottom,
        )
      })

    const before = await gapUnderSubject()
    await viewOption(page, 'Source').click()
    await expect(sourceEditor(page, 'Body')).toBeVisible()

    expect(await gapUnderSubject()).toBe(before)
  })

  test('a draft opens on the source and previews unsaved edits', async ({
    page,
  }) => {
    await mockAppwriteApi(page, emailMessage('draft'))
    await openMessage(page)

    await expect(viewOption(page, 'Source')).toBeChecked()

    const source = sourceEditor(page, 'Body')
    await expect(source).toContainText('<!doctype html>')
    await source.click()
    await page.keyboard.press('ControlOrMeta+A')
    await page.keyboard.type('Hello from the draft')

    await viewOption(page, 'Preview').click()

    await expect(
      previewFrame(page).getByText('Hello from the draft'),
    ).toBeVisible()
  })
})

test.describe('email template preview (mocked API)', () => {
  test('an editable template opens on the source', async ({ page }) => {
    await mockAppwriteApi(page, emailMessage('sent'), { smtpEnabled: true })
    await openTemplates(page)

    await expect(viewOption(page, 'Source')).toBeChecked()
    await expect(sourceEditor(page, 'Message')).toContainText('{{user}}')
  })

  test('a read-only template opens on the rendered mail', async ({ page }) => {
    await mockAppwriteApi(page, emailMessage('sent'), { smtpEnabled: false })
    await openTemplates(page)

    await expect(viewOption(page, 'Preview')).toBeChecked()
    await expect(
      previewFrame(page).getByRole('link', { name: 'Verify email' }),
    ).toBeVisible()
  })

  test('the preview renders bold tokens the way the mail worker does', async ({
    page,
  }) => {
    await mockAppwriteApi(page, emailMessage('sent'), { smtpEnabled: false })
    await openTemplates(page)

    const project = previewFrame(page).getByText('{{project}}', { exact: true })
    await expect(project).toBeVisible()
    // Any bold weight counts; the exact number is the stylesheet's business.
    await expect(project).toHaveCSS('font-weight', /^(bold|[6-9]00)$/)
    await expect(previewFrame(page).getByText('{{b}}')).toHaveCount(0)
  })
})
