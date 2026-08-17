import { expect, type Locator, type Page, type Response } from '@playwright/test'

/**
 * Click via the DOM. Playwright's pointer hit-test often loses to fullscreen
 * wizard chrome, sliding drawers, and cmdk lists that re-filter mid-click.
 */
export async function clickInPage(locator: Locator): Promise<void> {
  await expect(locator).toBeVisible({ timeout: 15_000 })
  await locator.evaluate((el: HTMLElement) => {
    el.scrollIntoView({ block: 'center', inline: 'nearest' })
    el.click()
  })
}

/**
 * Open a Radix Select and choose an option. Force-clicks skip animation
 * stability checks that otherwise detach the trigger mid-action.
 */
export async function openSelectAndChoose(
  page: Page,
  trigger: Locator,
  optionName: string,
): Promise<void> {
  await expect(trigger).toBeVisible({ timeout: 15_000 })
  let lastError: unknown
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      await trigger.evaluate((el: HTMLElement) => {
        el.scrollIntoView({ block: 'center', inline: 'nearest' })
      })
      await trigger.click({ force: true })
      const option = page.getByRole('option', { name: optionName }).first()
      await expect(option).toBeVisible({ timeout: 10_000 })
      await option.click({ force: true })
      await expect(page.getByRole('listbox')).toHaveCount(0, { timeout: 5_000 })
      return
    } catch (error) {
      lastError = error
      const listbox = page.getByRole('listbox')
      if (await listbox.isVisible().catch(() => false)) {
        await page.keyboard.press('Escape').catch(() => undefined)
        await expect(listbox)
          .toHaveCount(0, { timeout: 2_000 })
          .catch(() => undefined)
      }
    }
  }
  throw lastError
}

/**
 * Open a cmdk combobox, filter, and choose the first matching item.
 * Click the item (do not press Enter): Enter inside a dialog form submits
 * the parent form and leaves the primary button disabled/loading.
 */
export async function chooseCommandItem(
  page: Page,
  trigger: Locator,
  searchPlaceholder: string,
  searchText: string,
  optionPattern: RegExp,
): Promise<void> {
  await expect(trigger).toBeVisible({ timeout: 15_000 })
  let lastError: unknown
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      await trigger.click({ force: true })
      const search = page.getByPlaceholder(searchPlaceholder).last()
      await expect(search).toBeVisible({ timeout: 10_000 })
      const option = page
        .locator('[cmdk-item], [data-slot="command-item"]')
        .filter({ has: page.getByText(optionPattern) })
        .first()
      await search.fill(searchText)
      await expect(option).toBeVisible({ timeout: 10_000 })
      await option.click({ force: true })
      await expect(search).toBeHidden({ timeout: 5_000 })
      return
    } catch (error) {
      lastError = error
      const search = page.getByPlaceholder(searchPlaceholder).last()
      if (await search.isVisible().catch(() => false)) {
        await page.keyboard.press('Escape').catch(() => undefined)
      }
    }
  }
  throw lastError
}

/** Sonner can keep a previous toast in the DOM; assert the newest match. */
export async function expectToast(
  page: Page,
  text: string | RegExp,
  timeout = 30_000,
): Promise<void> {
  await expect(page.getByText(text).first()).toBeVisible({ timeout })
}

function readDatabaseLifecycleStatus(
  body: unknown,
  databaseId: string,
): string | null {
  if (!body || typeof body !== 'object') return null
  const record = body as Record<string, unknown>
  if (record.$id !== databaseId) return null
  if (typeof record.status === 'string' && record.status.trim()) {
    return record.status.trim()
  }
  if (record.status && typeof record.status === 'object') {
    const status = record.status as { ready?: boolean; health?: string }
    if (status.ready === true) return 'ready'
    if (status.health === 'unhealthy') return 'failed'
    if (status.health === 'degraded') return 'provisioning'
  }
  return null
}

function isFailedDatabaseStatus(status: string | null): boolean {
  const normalized = status?.toLowerCase() ?? ''
  return normalized === 'failed' || normalized === 'deleted'
}

function isBusyDatabaseStatus(status: string | null): boolean {
  const normalized = status?.toLowerCase() ?? ''
  return (
    normalized === 'provisioning' ||
    normalized === 'starting' ||
    normalized === 'scaling' ||
    normalized === 'restoring' ||
    normalized === 'upgrading' ||
    normalized === 'migrating' ||
    normalized === 'pausing' ||
    normalized === 'resuming' ||
    normalized === 'deleting'
  )
}

function isReadyDatabaseStatus(status: string | null): boolean {
  return status?.toLowerCase() === 'ready'
}

/** True while dedicated compute is still coming up and SQL/DDL will 409. */
export function dedicatedDatabaseBusyLocator(page: Page) {
  return page.locator('p.font-semibold').filter({
    hasText: /^Database is (provisioning|starting|not ready)$/i,
  })
}

export async function waitForDedicatedDatabaseReady(
  page: Page,
  timeoutMs: number,
): Promise<void> {
  await expect(dedicatedDatabaseBusyLocator(page)).toHaveCount(0, {
    timeout: timeoutMs,
  })
}

/**
 * After the create API returns an id, leave the wizard and wait until the
 * database route can render. Dedicated compute often stays on /databases/create
 * for minutes; the wizard also resets to the form if its own poll gives up.
 * When `waitUntilReady` is set, also wait until lifecycle status is `ready`
 * so later SQL/DDL is not rejected with dedicated_database_not_available.
 */
export async function openCreatedDatabase(
  page: Page,
  targetPath: string,
  targetPattern: RegExp,
  timeoutMs: number,
  options?: { waitUntilReady?: boolean },
): Promise<void> {
  const deadline = Date.now() + timeoutMs
  const waitUntilReady = options?.waitUntilReady === true
  const databaseId = targetPath.split('/').filter(Boolean).at(-1) ?? ''
  let lastStatus: string | null = null
  let stableReadyChecks = 0

  const onResponse = async (response: Response) => {
    try {
      if (response.request().method() !== 'GET') return
      const url = new URL(response.url())
      if (!url.pathname.includes(databaseId) || url.pathname.includes('/executions')) {
        return
      }
      const status = readDatabaseLifecycleStatus(
        await response.json(),
        databaseId,
      )
      if (status) lastStatus = status
    } catch {
      // Ignore non-JSON or already-consumed bodies.
    }
  }

  page.on('response', onResponse)

  const go = async () => {
    await page.goto(targetPath, {
      waitUntil: 'domcontentloaded',
      timeout: 45_000,
    })
  }

  try {
    await go()

    while (Date.now() < deadline) {
      if (!targetPattern.test(page.url())) {
        await go()
      }

      const notFound = await page
        .getByText(/Database not found/i)
        .isVisible()
        .catch(() => false)
      const loading = await page
        .getByText('Loading database...')
        .isVisible()
        .catch(() => false)
      const createWizard = /\/databases\/create(?:\/)?$/.test(
        new URL(page.url()).pathname,
      )
      const provisioning = await dedicatedDatabaseBusyLocator(page)
        .first()
        .isVisible()
        .catch(() => false)

      if (isFailedDatabaseStatus(lastStatus)) {
        throw new Error(
          `Database entered ${lastStatus} while waiting for ${targetPath}`,
        )
      }

      if (!createWizard && !notFound && !loading) {
        if (!waitUntilReady) return
        if (isReadyDatabaseStatus(lastStatus) && !provisioning) return
        if (!provisioning && !isBusyDatabaseStatus(lastStatus)) {
          stableReadyChecks += 1
          if (stableReadyChecks >= 2) return
        } else {
          stableReadyChecks = 0
        }
      } else {
        stableReadyChecks = 0
      }

      await page.waitForTimeout(1_000)
      if (notFound || createWizard) {
        await go()
      }
    }

    throw new Error(
      waitUntilReady
        ? `Database was not ready before timeout (last status: ${lastStatus ?? 'unknown'})`
        : `Database route did not load before timeout: ${targetPath}`,
    )
  } finally {
    page.off('response', onResponse)
  }
}
