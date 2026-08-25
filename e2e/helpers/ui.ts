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

const DEDICATED_BUSY_TITLE =
  /^Database is (provisioning|starting|not ready|scaling|upgrading|migrating|restoring|pausing|resuming|deleting)$/i
const DEDICATED_FAILED_TITLE = /Database update failed|^Database is deleted$/i
const DEDICATED_STATUS_RELOAD_MS = 15_000

function readRecordLifecycleStatus(
  record: Record<string, unknown>,
  databaseId: string,
): string | null {
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

function readDatabaseLifecycleStatus(
  body: unknown,
  databaseId: string,
): string | null {
  if (!body || typeof body !== 'object') return null
  const record = body as Record<string, unknown>
  const direct = readRecordLifecycleStatus(record, databaseId)
  if (direct) return direct
  if (Array.isArray(record.databases)) {
    for (const item of record.databases) {
      if (!item || typeof item !== 'object') continue
      const nested = readRecordLifecycleStatus(
        item as Record<string, unknown>,
        databaseId,
      )
      if (nested) return nested
    }
  }
  return null
}

async function readResponseJson(response: Response): Promise<unknown> {
  const text = await response.text()
  if (!text.trim()) return null
  return JSON.parse(text) as unknown
}

function isFailedDatabaseStatus(status: string | null): boolean {
  const normalized = status?.toLowerCase() ?? ''
  return normalized === 'failed' || normalized === 'deleted'
}

function isReadyDatabaseStatus(status: string | null): boolean {
  const normalized = status?.toLowerCase() ?? ''
  return normalized === 'ready' || normalized === 'paused'
}

/** True while dedicated compute is still coming up and SQL/DDL will 409. */
export function dedicatedDatabaseBusyLocator(page: Page) {
  return page.locator('p.font-semibold').filter({
    hasText: DEDICATED_BUSY_TITLE,
  })
}

export function dedicatedDatabaseFailedLocator(page: Page) {
  return page.locator('p.font-semibold').filter({
    hasText: DEDICATED_FAILED_TITLE,
  })
}

async function readDedicatedBannerTitle(page: Page): Promise<string | null> {
  const failed = dedicatedDatabaseFailedLocator(page).first()
  if (await failed.isVisible().catch(() => false)) {
    return ((await failed.textContent()) ?? '').trim() || 'Database update failed'
  }
  const busy = dedicatedDatabaseBusyLocator(page).first()
  if (await busy.isVisible().catch(() => false)) {
    return ((await busy.textContent()) ?? '').trim() || 'Database is not ready'
  }
  return null
}

async function assertDedicatedDatabaseNotFailed(page: Page): Promise<void> {
  const title = await readDedicatedBannerTitle(page)
  if (title && DEDICATED_FAILED_TITLE.test(title)) {
    throw new Error(`Dedicated database failed (${title})`)
  }
}

export async function waitForDedicatedDatabaseReady(
  page: Page,
  timeoutMs: number,
  options?: { reload?: boolean },
): Promise<void> {
  const reload = options?.reload !== false
  const deadline = Date.now() + timeoutMs
  let lastReloadAt = Date.now()
  let lastBanner: string | null = null

  while (Date.now() < deadline) {
    await assertDedicatedDatabaseNotFailed(page)

    const loading = await page
      .getByText('Loading database...')
      .isVisible()
      .catch(() => false)
    lastBanner = await readDedicatedBannerTitle(page)
    if (!loading && !lastBanner) {
      const busyCount = await dedicatedDatabaseBusyLocator(page).count()
      if (busyCount === 0) return
    }

    const remaining = deadline - Date.now()
    if (remaining <= 0) break

    if (reload && Date.now() - lastReloadAt >= DEDICATED_STATUS_RELOAD_MS) {
      await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => undefined)
      lastReloadAt = Date.now()
      await page
        .getByText('Loading database...')
        .waitFor({ state: 'hidden', timeout: 5_000 })
        .catch(() => undefined)
      continue
    }

    await page.waitForTimeout(Math.min(2_000, remaining))
  }

  throw new Error(
    `Dedicated database still busy after ${timeoutMs}ms${
      lastBanner ? ` (${lastBanner})` : ''
    }`,
  )
}

/**
 * After the create API returns an id, leave the wizard and wait until the
 * database route can render. Dedicated compute often stays on /databases/create
 * for minutes; the wizard also resets to the form if its own poll gives up.
 * When `waitUntilReady` is set, also wait until lifecycle status is `ready`
 * (or `paused`) so later SQL/DDL is not rejected with
 * dedicated_database_not_available. Unknown/null status is not treated as ready.
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
  let lastBanner: string | null = null
  let lastReloadAt = 0

  const onResponse = async (response: Response) => {
    try {
      if (response.request().method() !== 'GET') return
      const url = new URL(response.url())
      if (url.pathname.includes('/executions')) return
      if (
        !url.pathname.includes(databaseId) &&
        !url.pathname.includes('/databases')
      ) {
        return
      }
      const status = readDatabaseLifecycleStatus(
        await readResponseJson(response),
        databaseId,
      )
      if (status) lastStatus = status
    } catch {
      // Ignore non-JSON or already-consumed bodies.
    }
  }

  page.on('response', onResponse)

  const go = async () => {
    lastReloadAt = Date.now()
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
      lastBanner = await readDedicatedBannerTitle(page)
      const provisioning = lastBanner != null && !DEDICATED_FAILED_TITLE.test(lastBanner)

      if (isFailedDatabaseStatus(lastStatus) || (lastBanner && DEDICATED_FAILED_TITLE.test(lastBanner))) {
        throw new Error(
          `Database entered ${lastStatus ?? lastBanner} while waiting for ${targetPath}`,
        )
      }

      if (!createWizard && !notFound && !loading) {
        if (!waitUntilReady) return
        if (isReadyDatabaseStatus(lastStatus) && !provisioning) return
      }

      await page.waitForTimeout(1_000)
      if (notFound || createWizard) {
        await go()
      } else if (
        waitUntilReady &&
        Date.now() - lastReloadAt >= DEDICATED_STATUS_RELOAD_MS
      ) {
        await go()
      }
    }

    throw new Error(
      waitUntilReady
        ? `Database was not ready before timeout (last status: ${lastStatus ?? 'unknown'}${
            lastBanner ? `, banner: ${lastBanner}` : ''
          })`
        : `Database route did not load before timeout: ${targetPath}`,
    )
  } finally {
    page.off('response', onResponse)
  }
}
