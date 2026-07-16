/**
 * OAuth2 consent screen journeys, backed by network mocks.
 *
 * The local OSS Appwrite backend (1.9.x) does not expose the console OAuth2
 * authorization-server endpoints, so these journeys mock the console API at
 * the network layer and exercise the real UI end to end: the generic consent
 * card, the MCP scope-narrowing editor, consent-skip approve semantics, and
 * page scrolling for tall cards.
 *
 * Run with: bunx playwright test -c e2e/oauth2-mock.config.ts
 */
import { fileURLToPath } from 'node:url'
import { test, expect, type Page, type Route } from '@playwright/test'

// Screenshots land inside the repo (e2e/screenshots/oauth2) so they're easy
// to browse after a run, regardless of the launch directory.
const SCREENSHOT_DIR =
  process.env.OAUTH2_SHOT_DIR ??
  fileURLToPath(new URL('../screenshots/oauth2', import.meta.url))

const USER = {
  $id: 'user-e2e',
  $createdAt: '2026-01-01T00:00:00.000+00:00',
  $updatedAt: '2026-01-01T00:00:00.000+00:00',
  name: 'E2E Tester',
  email: 'e2e@appwrite.io',
  phone: '',
  emailVerification: true,
  phoneVerification: false,
  status: true,
  labels: [],
  passwordUpdate: '2026-01-01T00:00:00.000+00:00',
  registration: '2026-01-01T00:00:00.000+00:00',
  prefs: {},
  accessedAt: '2026-01-01T00:00:00.000+00:00',
  mfa: false,
  targets: [],
}

const APP = {
  $id: 'cursor-mcp-client',
  $createdAt: '2026-01-01T00:00:00.000+00:00',
  $updatedAt: '2026-01-01T00:00:00.000+00:00',
  name: 'Cursor',
  description: '',
  logoUri: '',
  homepageUri: 'https://cursor.com',
  privacyPolicyUrl: 'https://cursor.com/privacy',
  termsUrl: 'https://cursor.com/terms',
  redirectUris: ['https://cursor.com/api/auth/callback'],
  type: 'public',
  status: true,
}

/** The full catalog an MCP client mechanically requests. */
const MCP_SCOPES = [
  'openid',
  'profile',
  'email',
  'project:all',
  'project:users.read',
  'project:users.write',
  'project:teams.read',
  'project:teams.write',
  'project:databases.read',
  'project:databases.write',
  'project:tables.read',
  'project:tables.write',
  'project:rows.read',
  'project:rows.write',
  'project:buckets.read',
  'project:buckets.write',
  'project:files.read',
  'project:files.write',
  'project:functions.read',
  'project:functions.write',
  'project:executions.read',
  'project:executions.write',
  'project:sites.read',
  'project:sites.write',
  'project:messages.read',
  'project:messages.write',
  'project:topics.read',
  'project:topics.write',
  'project:webhooks.read',
  'project:webhooks.write',
  'project:keys.read',
  'project:keys.write',
  'project:platforms.read',
  'project:platforms.write',
  'organization:all',
  'organization:projects.read',
  'organization:projects.write',
  'organization:organization.memberships.read',
  'organization:organization.memberships.write',
]

function grantFixture(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    $id: 'grant-1',
    $createdAt: '2026-07-16T00:00:00.000+00:00',
    $updatedAt: '2026-07-16T00:00:00.000+00:00',
    userId: USER.$id,
    appId: APP.$id,
    scopes: MCP_SCOPES,
    resources: ['https://mcp.appwrite.io'],
    authorizationDetails: JSON.stringify([
      { type: 'project', identifiers: ['*'] },
      { type: 'organization', identifiers: ['*'] },
    ]),
    prompt: '',
    redirectUri: 'https://cursor.com/api/auth/callback',
    authTime: 1752600000,
    expire: '2026-07-16T01:00:00.000+00:00',
    ...overrides,
  }
}

const NON_MCP_GRANT = grantFixture({
  $id: 'grant-2',
  scopes: ['openid', 'profile', 'email', 'all'],
  resources: [],
  authorizationDetails: '',
})

const json = (body: unknown) => ({
  status: 200,
  contentType: 'application/json',
  body: JSON.stringify(body),
})

interface ApproveCapture {
  body: Record<string, unknown> | null
}

/** Wire up every console API endpoint the consent screen touches. */
async function mockConsoleApi(
  page: Page,
  grant: Record<string, unknown>,
  approveCapture: ApproveCapture,
) {
  const api = (path: string) => `**/v1${path}`

  await page.route(api('/account'), (route: Route) => route.fulfill(json(USER)))
  await page.route(api('/account/sessions'), (route: Route) =>
    route.fulfill(json({ total: 0, sessions: [] })),
  )
  await page.route(api('/oauth2/console/grants/*'), (route: Route) =>
    route.fulfill(json(grant)),
  )
  await page.route(api('/apps/*'), (route: Route) => route.fulfill(json(APP)))
  await page.route(api('/organizations*'), (route: Route) =>
    route.fulfill(
      json({
        total: 1,
        teams: [
          {
            $id: 'org-1',
            name: 'Acme Inc',
            total: 1,
            prefs: {},
            billingPlan: 'tier-0',
          },
        ],
      }),
    ),
  )
  await page.route(api('/teams*'), (route: Route) =>
    route.fulfill(
      json({ total: 1, teams: [{ $id: 'org-1', name: 'Acme Inc', total: 1 }] }),
    ),
  )
  await page.route(api('/projects*'), (route: Route) =>
    route.fulfill(
      json({
        total: 1,
        projects: [
          { $id: 'proj-1', name: 'My Project', region: 'fra', teamId: 'org-1' },
        ],
      }),
    ),
  )
  await page.route(api('/oauth2/console/approve'), async (route: Route) => {
    approveCapture.body = route.request().postDataJSON()
    await route.fulfill(
      json({ redirectUrl: 'https://cursor.com/api/auth/callback?code=e2e' }),
    )
  })
  await page.route(api('/oauth2/console/reject'), (route: Route) =>
    route.fulfill(
      json({
        redirectUrl: 'https://cursor.com/api/auth/callback?error=access_denied',
      }),
    ),
  )
  // The approve redirect target — keep the browser on a stub page.
  await page.route('https://cursor.com/**', (route: Route) =>
    route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: '<h1>client callback</h1>',
    }),
  )
  // Silence non-API noise the console shell produces.
  await page.route('**/v1/console/**', (route: Route) =>
    route.fulfill(json({})),
  )
}

test.describe('OAuth2 consent screen (mocked API)', () => {
  test('non-MCP grant renders read-only permissions without the narrowing editor', async ({
    page,
  }) => {
    const capture: ApproveCapture = { body: null }
    await mockConsoleApi(page, NON_MCP_GRANT, capture)

    await page.goto('/oauth2/consent?grant_id=grant-2')
    await expect(
      page.getByRole('heading', { name: 'Authorize Cursor' }),
    ).toBeVisible()

    // Read-only permission list, no scope narrowing offered.
    await expect(page.getByText('Permissions', { exact: true })).toBeVisible()
    await expect(page.getByText('Full access to your account')).toBeVisible()
    await expect(page.getByText('Customize access')).toHaveCount(0)

    await page.screenshot({
      path: `${SCREENSHOT_DIR}/01-non-mcp-consent.png`,
      fullPage: true,
    })

    // Approve never downscoped: no `scope` in the payload.
    await page.getByRole('button', { name: 'Authorize', exact: true }).click()
    await expect.poll(() => capture.body, { timeout: 10_000 }).not.toBeNull()
    expect(capture.body).not.toHaveProperty('scope')
  })

  test('MCP grant shows the narrowing editor and defaults to full access (consent-skip)', async ({
    page,
  }) => {
    const capture: ApproveCapture = { body: null }
    await mockConsoleApi(page, grantFixture(), capture)

    await page.goto('/oauth2/consent?grant_id=grant-1')
    await expect(
      page.getByRole('heading', { name: 'Authorize Cursor' }),
    ).toBeVisible()

    // MCP-specific consent: Full access summary + Customize access entry.
    await expect(page.getByText('Full access', { exact: true })).toBeVisible()
    await expect(page.getByText('Customize access')).toBeVisible()
    await expect(page.getByText('Project access')).toBeVisible()
    await expect(page.getByText('Organization access')).toBeVisible()

    await page.screenshot({
      path: `${SCREENSHOT_DIR}/02-mcp-consent-collapsed.png`,
      fullPage: true,
    })

    // Untouched selection → approve omits both scope and authorizationDetails
    // so re-authorizations skip consent.
    await page.getByRole('button', { name: 'Authorize', exact: true }).click()
    await expect.poll(() => capture.body, { timeout: 10_000 }).not.toBeNull()
    expect(capture.body).not.toHaveProperty('scope')
    expect(capture.body).not.toHaveProperty('authorizationDetails')
  })

  test('narrowing scopes in the editor downscopes the approved grant', async ({
    page,
  }) => {
    const capture: ApproveCapture = { body: null }
    await mockConsoleApi(page, grantFixture(), capture)

    await page.goto('/oauth2/consent?grant_id=grant-1')
    await page.getByText('Customize access').click()

    // The tall expanded card must scroll — this is the regression the old
    // console fixed in its oauth2 layout.
    await expect(page.getByText('Read-only', { exact: true })).toBeVisible()
    await page.screenshot({
      path: `${SCREENSHOT_DIR}/03-mcp-editor-expanded.png`,
      fullPage: true,
    })

    // Deselect "Users" → project:users.* must disappear from the grant.
    await page.getByRole('checkbox', { name: 'Allow access to Users' }).click()

    // Summary flips from Full access to Custom access.
    await expect(page.getByText('Custom access', { exact: true })).toBeVisible()

    await page.screenshot({
      path: `${SCREENSHOT_DIR}/04-mcp-editor-narrowed.png`,
      fullPage: true,
    })

    const authorize = page.getByRole('button', {
      name: 'Authorize',
      exact: true,
    })
    await authorize.scrollIntoViewIfNeeded()
    await authorize.click()
    await expect.poll(() => capture.body, { timeout: 10_000 }).not.toBeNull()

    const scope = String(capture.body?.scope ?? '')
    expect(scope).toContain('project:tables.read')
    expect(scope).toContain('project:tables.write')
    expect(scope).not.toContain('project:users.read')
    expect(scope).not.toContain('project:users.write')
    // Narrowed grants never include the console-wide umbrella scopes.
    expect(scope.split(' ')).not.toContain('all')
    expect(scope.split(' ')).not.toContain('project:all')
    // Identity scopes are always retained.
    expect(scope).toContain('openid')
  })

  test('read-only toggle limits every permission to read scopes', async ({
    page,
  }) => {
    const capture: ApproveCapture = { body: null }
    await mockConsoleApi(page, grantFixture(), capture)

    await page.goto('/oauth2/consent?grant_id=grant-1')
    await page.getByText('Customize access').click()
    await page.getByText('Read-only', { exact: true }).click()
    await expect(page.getByText('Custom access', { exact: true })).toBeVisible()

    await page.screenshot({
      path: `${SCREENSHOT_DIR}/05-mcp-read-only.png`,
      fullPage: true,
    })

    const authorize = page.getByRole('button', {
      name: 'Authorize',
      exact: true,
    })
    await authorize.scrollIntoViewIfNeeded()
    await authorize.click()
    await expect.poll(() => capture.body, { timeout: 10_000 }).not.toBeNull()

    const tokens = String(capture.body?.scope ?? '').split(' ')
    const tierTokens = tokens.filter(
      (token) =>
        token.startsWith('project:') || token.startsWith('organization:'),
    )
    expect(tierTokens.length).toBeGreaterThan(0)
    for (const token of tierTokens) {
      expect(token.endsWith('.read')).toBe(true)
    }
  })

  test('deselecting every permission blocks Authorize', async ({ page }) => {
    const capture: ApproveCapture = { body: null }
    await mockConsoleApi(page, grantFixture(), capture)

    await page.goto('/oauth2/consent?grant_id=grant-1')
    await page.getByText('Customize access').click()

    // Uncheck both tier "select all" checkboxes.
    await page
      .getByRole('checkbox', { name: 'Allow all projects permissions' })
      .click()
    await page
      .getByRole('checkbox', { name: 'Allow all organizations permissions' })
      .click()

    await expect(
      page.getByText('Select at least one permission', { exact: false }),
    ).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Authorize', exact: true }),
    ).toBeDisabled()

    await page.screenshot({
      path: `${SCREENSHOT_DIR}/06-mcp-nothing-selected.png`,
      fullPage: true,
    })
  })

  test('client_id entry forwards resource params to authorize and offers account switching', async ({
    page,
  }) => {
    const capture: ApproveCapture = { body: null }
    await mockConsoleApi(page, grantFixture(), capture)

    let authorizeUrl: string | null = null
    await page.route('**/v1/oauth2/console/authorize*', (route: Route) => {
      authorizeUrl = route.request().url()
      return route.fulfill(json({ grantId: 'grant-1', redirectUrl: '' }))
    })

    const params = new URLSearchParams({
      client_id: APP.$id,
      redirect_uri: 'https://cursor.com/api/auth/callback',
      response_type: 'code',
      scope: MCP_SCOPES.join(' '),
      state: 'e2e-state',
      code_challenge: 'abc',
      code_challenge_method: 'S256',
    })
    // `resource` may legally repeat — both values must be forwarded (RFC 8707).
    params.append('resource', 'https://mcp.appwrite.io')
    params.append('resource', 'https://mcp.appwrite.io/mcp')

    await page.goto(`/oauth2/consent?${params.toString()}`)
    await expect(
      page.getByRole('heading', { name: 'Authorize Cursor' }),
    ).toBeVisible()

    // The authorize call carried both resource indicators — without them the
    // server can never mark the grant as MCP. The SDK serializes the array as
    // indexed params (resource[0], resource[1]).
    expect(authorizeUrl).not.toBeNull()
    const forwardedParams = new URL(authorizeUrl!).searchParams
    expect([
      forwardedParams.get('resource[0]'),
      forwardedParams.get('resource[1]'),
    ]).toEqual(['https://mcp.appwrite.io', 'https://mcp.appwrite.io/mcp'])

    // Entering via client_id enables "Use a different account".
    await page.getByRole('button', { name: /e2e@appwrite\.io/ }).click()
    await expect(
      page.getByRole('menuitem', { name: 'Use a different account' }),
    ).toBeVisible()
    await page.screenshot({
      path: `${SCREENSHOT_DIR}/08-account-switch-menu.png`,
    })
  })

  test('tall consent card scrolls to reach the Authorize button', async ({
    page,
  }) => {
    const capture: ApproveCapture = { body: null }
    await mockConsoleApi(page, grantFixture(), capture)

    await page.setViewportSize({ width: 1280, height: 500 })
    await page.goto('/oauth2/consent?grant_id=grant-1')
    await page.getByText('Customize access').click()
    await expect(page.getByText('Read-only', { exact: true })).toBeVisible()

    // The page-level scroll container must actually overflow…
    const overflows = await page.evaluate(() => {
      const scroller = document.querySelector(
        '.overflow-y-auto',
      ) as HTMLElement | null
      return scroller ? scroller.scrollHeight > scroller.clientHeight : false
    })
    expect(overflows).toBe(true)

    // …and the Authorize button must be reachable and clickable by scrolling.
    const authorize = page.getByRole('button', {
      name: 'Authorize',
      exact: true,
    })
    await authorize.scrollIntoViewIfNeeded()
    await expect(authorize).toBeVisible()
    await page.screenshot({
      path: `${SCREENSHOT_DIR}/07-short-viewport-scrolled.png`,
    })
    await authorize.click()
    await expect.poll(() => capture.body, { timeout: 10_000 }).not.toBeNull()
  })
})
