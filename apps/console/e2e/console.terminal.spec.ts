import { test, expect } from './fixtures'
import { E2E_VIEWPORT } from './config/viewport'
import { acceptCookieBannerIfPresent, newE2ePage } from './helpers/cookie-banner'
import { discoverConsoleTargets } from './helpers/discovery'
import { ensureProjectActive } from './helpers/ensure-project-active'
import {
  openProjectTerminal,
  runTerminalCommandAndWait,
} from './helpers/cli-terminal'
import { CLI_WASM_VERSION } from '../src/lib/cli-shell/wasm/constants'

/**
 * Authenticated console CLI terminal. Read-only commands only.
 * Uses E2E_TEST_EMAIL / E2E_TEST_PASSWORD (or session secret) via auth.setup.
 */

test.describe('console terminal', () => {
  test.describe.configure({ timeout: 120_000 })

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
      test.skip(
        !projectId,
        'No live project found for this account. Set E2E_PROJECT_ID or create a project.',
      )
      await ensureProjectActive(page, projectId!)
    } finally {
      await context.close()
    }
  })

  test('runs version, help, client, and rejects shell commands', async ({
    page,
  }) => {
    test.skip(
      !projectId,
      'No live project found for this account. Set E2E_PROJECT_ID or create a project.',
    )

    await page.goto(`/projects/${projectId}`, {
      waitUntil: 'domcontentloaded',
    })
    await acceptCookieBannerIfPresent(page)
    await expect(page).toHaveURL(
      new RegExp(`/projects/${projectId}(?:/|$|\\?)`),
      { timeout: 45_000 },
    )
    await expect(page.locator('#main-content')).toBeVisible({
      timeout: 45_000,
    })

    await openProjectTerminal(page)

    await test.step('appwrite -v reports the bundled CLI version', async () => {
      await runTerminalCommandAndWait(
        page,
        'appwrite -v',
        `appwrite version ${CLI_WASM_VERSION}`,
      )
    })

    await test.step('appwrite help prints command groups', async () => {
      await runTerminalCommandAndWait(page, 'appwrite help', /OPTIONS/i)
    })

    await test.step('appwrite client --debug shows the configured endpoint', async () => {
      await runTerminalCommandAndWait(
        page,
        'appwrite client --debug',
        /endpoint/i,
      )
    })

    await test.step('plain shell commands are rejected', async () => {
      await runTerminalCommandAndWait(
        page,
        'ls',
        'not available in this terminal',
      )
    })
  })
})
