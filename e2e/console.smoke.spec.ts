import { test, expect } from '@playwright/test'
import { discoverConsoleTargets } from './helpers/discovery'
import { ensureProjectActive } from './helpers/ensure-project-active'
import { expectPageRenders } from './helpers/smoke'

/**
 * Authenticated console pages. Read-only: navigate and assert render only.
 * Uses E2E_TEST_EMAIL / E2E_TEST_PASSWORD (or session secret) via auth.setup.
 * If the target project is paused (common on free orgs), restores it once in beforeAll.
 */

test.describe('console smoke (read-only)', () => {
  let orgId: string
  let projectId: string | null

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext({
      storageState: 'e2e/.auth/auth.json',
    })
    const page = await context.newPage()
    try {
      const targets = await discoverConsoleTargets(page)
      orgId = targets.orgId
      projectId = targets.projectId

      if (projectId) {
        await ensureProjectActive(page, projectId)
      }
    } finally {
      await context.close()
    }
  })

  test('session stays signed in', async ({ page }) => {
    // Do not use `/` here: the root loader can treat the request as a guest during
    // SSR (no localStorage cookieFallback yet) and send users to `/home`, even when
    // the Playwright storage state is valid for client-side console routes.
    await page.goto('/account', { waitUntil: 'domcontentloaded' })
    await expect(page).not.toHaveURL(/\/sign-in/)
    await expect(page).toHaveURL(/\/account(?:\/|$|\?)/, { timeout: 45_000 })
    await expect(
      page
        .locator('[data-testid="settings-navigation"]:visible')
        .or(page.getByRole('heading').first()),
    ).toBeVisible({ timeout: 45_000 })
  })

  test('account overview renders', async ({ page }) => {
    await expectPageRenders(page, '/account', {
      url: /\/account(?:\/|$|\?)/,
      ready: () =>
        page
          .locator('[data-testid="settings-navigation"]:visible')
          .or(page.getByRole('heading').first()),
    })
  })

  test('account security renders', async ({ page }) => {
    await expectPageRenders(page, '/account/security', {
      url: /\/account\/security/,
    })
  })

  test('organization overview renders', async ({ page }) => {
    await expectPageRenders(page, `/organizations/${orgId}`, {
      url: new RegExp(`/organizations/${orgId}(?:/|$|\\?)`),
    })
  })

  test('organization members renders', async ({ page }) => {
    await expectPageRenders(page, `/organizations/${orgId}/members`, {
      url: new RegExp(`/organizations/${orgId}/members`),
    })
  })

  test('organization domains renders', async ({ page }) => {
    await expectPageRenders(page, `/organizations/${orgId}/domains`, {
      url: new RegExp(`/organizations/${orgId}/domains`),
    })
  })

  test('organization billing renders', async ({ page }) => {
    await expectPageRenders(page, `/organizations/${orgId}/billing`, {
      url: new RegExp(`/organizations/${orgId}/billing`),
    })
  })

  test.describe('project services', () => {
    test.beforeEach(() => {
      test.skip(
        !projectId,
        'No project found for this account. Set E2E_PROJECT_ID or create a project.',
      )
    })

    const servicePaths = [
      { name: 'overview', suffix: '' },
      { name: 'auth', suffix: '/auth' },
      { name: 'databases', suffix: '/databases' },
      { name: 'storage', suffix: '/storage' },
      { name: 'functions', suffix: '/functions' },
      { name: 'messaging', suffix: '/messaging' },
      { name: 'sites', suffix: '/sites' },
      { name: 'settings', suffix: '/settings' },
    ] as const

    for (const service of servicePaths) {
      test(`${service.name} renders`, async ({ page }) => {
        const path = `/projects/${projectId}${service.suffix}`
        await expectPageRenders(page, path, {
          url: new RegExp(
            `/projects/${projectId}${service.suffix.replace(/\//g, '\\/')}(?:/|$|\\?)`,
          ),
        })
      })
    }
  })
})
