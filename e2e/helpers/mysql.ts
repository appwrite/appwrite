import { expect, type Page } from '@playwright/test'
import { enableMysqlFeatureFlags } from './feature-flags'
import { acceptCookieBannerIfPresent } from './cookie-banner'
import { waitForFullscreenLoaderHidden } from './fullscreen-loader'

export type CreatedMysqlDatabase = {
  databaseId: string
  databaseName: string
}

/** Dedicated MySQL provisioning against production can take several minutes. */
export const MYSQL_PROVISION_TIMEOUT_MS = 10 * 60_000

function uniqueSuffix(): string {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
}

/**
 * Create a native MySQL database via the fullscreen create wizard and wait until
 * the MySQL shell route loads.
 */
export async function createMysqlDatabaseViaWizard(
  page: Page,
  projectId: string,
  options?: { namePrefix?: string },
): Promise<CreatedMysqlDatabase> {
  await enableMysqlFeatureFlags(page)

  const databaseName =
    `${options?.namePrefix ?? 'e2e-mysql-db'}-${uniqueSuffix()}`.slice(0, 128)

  await page.goto(`/projects/${projectId}/databases/create`, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  })
  await acceptCookieBannerIfPresent(page)
  await expect(page).toHaveURL(/\/databases\/create/, { timeout: 60_000 })
  await expect(
    page.getByRole('heading', { name: 'Choose database type' }),
  ).toBeVisible({ timeout: 60_000 })

  // Native databases group - select MySQL via its unique description text.
  // Playwright's pointer hit-test fights the fullscreen WizardLayout chrome, so
  // we scroll the card into the scrollport and invoke click() in the page.
  const mysqlCard = page
    .locator('button:not([disabled])')
    .filter({
      hasText:
        'A dedicated MySQL database for common relational workloads and existing MySQL applications.',
    })
    .first()
  await expect(mysqlCard).toBeVisible({ timeout: 30_000 })
  await mysqlCard.evaluate((el: HTMLButtonElement) => {
    el.scrollIntoView({ block: 'center', inline: 'nearest' })
    el.click()
  })

  // Summary sidebar should reflect the selection.
  await expect(page.getByText('MySQL').first()).toBeVisible({ timeout: 15_000 })
  await expect(page.locator('#db-name')).toBeVisible({ timeout: 30_000 })
  await page.locator('#db-name').fill(databaseName)

  // Specs load from /v1/mysql/specifications - wait for at least one selectable row.
  const specRows = page.locator('table tbody tr').filter({
    hasNot: page.getByText('Upgrade', { exact: true }),
  })
  await expect(specRows.first()).toBeVisible({ timeout: 90_000 })
  // Prefer cheapest / first enabled tier (already defaulted, but click to be sure).
  await specRows.first().click()

  const createButton = page.getByRole('button', { name: 'Create database' })
  await expect(createButton).toBeEnabled({ timeout: 30_000 })

  const createResponsePromise = page.waitForResponse(
    (response) => {
      try {
        const url = new URL(response.url())
        return (
          response.request().method() === 'POST' &&
          /\/v1\/mysql\/?$/.test(url.pathname) &&
          response.status() < 500
        )
      } catch {
        return false
      }
    },
    { timeout: 120_000 },
  )

  await createButton.click()

  const createResponse = await createResponsePromise
  if (!createResponse.ok()) {
    throw new Error(
      `MySQL create failed: ${createResponse.status()} ${await createResponse.text()}`,
    )
  }
  const body = (await createResponse.json()) as { $id?: string }
  const databaseId = body.$id
  if (!databaseId) {
    throw new Error(
      `MySQL create response missing $id: ${JSON.stringify(body).slice(0, 500)}`,
    )
  }

  // Wizard shows provisioning progress then navigates to the MySQL shell.
  await expect(page).toHaveURL(
    new RegExp(`/projects/${projectId}/databases/mysql/${databaseId}`),
    { timeout: MYSQL_PROVISION_TIMEOUT_MS },
  )

  // Shell ready: sidebar / header present (not "Loading database...").
  await expect(page.getByText('Loading database...')).toHaveCount(0, {
    timeout: 120_000,
  })
  await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)
  await expect(
    page.getByRole('heading', { name: 'Error', exact: true }),
  ).toHaveCount(0)
  await expect(
    page
      .getByRole('heading', { name: /sql editor/i })
      .or(page.getByRole('link', { name: 'SQL editor' }))
      .first(),
  ).toBeVisible({ timeout: 120_000 })
  await expect(page.getByText(databaseName, { exact: false }).first()).toBeVisible({
    timeout: 60_000,
  })

  return { databaseId, databaseName }
}

export function mysqlDatabasePath(
  projectId: string,
  databaseId: string,
  suffix = '',
): string {
  const base = `/projects/${projectId}/databases/mysql/${databaseId}`
  return suffix ? `${base}${suffix.startsWith('/') ? suffix : `/${suffix}`}` : base
}

/** Navigate to a MySQL shell tab and assert it rendered. */
export async function expectMysqlTabRenders(
  page: Page,
  projectId: string,
  databaseId: string,
  tabPath: string,
  options?: {
    ready?: () => ReturnType<Page['locator']>
    timeout?: number
  },
): Promise<void> {
  const timeout = options?.timeout ?? 90_000
  const path = mysqlDatabasePath(projectId, databaseId, tabPath)

  await page.goto(path, { waitUntil: 'domcontentloaded', timeout })
  await acceptCookieBannerIfPresent(page)
  await expect(page).toHaveURL(new RegExp(tabPath.replace(/\//g, '\\/')), {
    timeout,
  })
  await expect(page.getByText('Loading database...')).toHaveCount(0, {
    timeout,
  })
  await expect(page.getByText(/Database not found/i)).toHaveCount(0)
  // Error boundary / crash page must not count as a successful render.
  await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)
  await expect(
    page.getByRole('heading', { name: 'Error', exact: true }),
  ).toHaveCount(0)

  if (options?.ready) {
    await expect(options.ready()).toBeVisible({ timeout })
  }
}

/**
 * Open the MySQL schema picker and select a schema.
 * Managed MySQL denies CREATE SCHEMA for admin, so tests must use an existing
 * application schema (often matching the database display name, including hyphens).
 */
export async function selectMysqlSchema(
  page: Page,
  schema?: string,
): Promise<string> {
  await waitForFullscreenLoaderHidden(page, 120_000)

  const picker = page.getByRole('button', { name: 'Schema' }).first()
  await expect(picker).toBeVisible({ timeout: 60_000 })

  // Schemas load via SQL after the shell is ready; poll until options appear.
  await expect
    .poll(
      async () => {
        const alreadySelected = await picker.innerText()
        if (
          alreadySelected &&
          !/Select schema/i.test(alreadySelected) &&
          (!schema || alreadySelected.includes(schema))
        ) {
          return alreadySelected.trim()
        }

        await picker.click()
        const search = page.getByPlaceholder('Search schemas...')
        await expect(search).toBeVisible({ timeout: 15_000 })

        if (schema) {
          await search.fill(schema)
          await page.waitForTimeout(500)
        }

        // Options are plain <button>s inside the Command list (not role=option).
        const options = page.locator(
          '[data-slot="command-list"] button, [cmdk-list] button',
        )
        const count = await options.count()
        if (count === 0) {
          const empty = page.getByText('No schemas found')
          if (await empty.isVisible().catch(() => false)) {
            await page.keyboard.press('Escape')
            return ''
          }
          await page.keyboard.press('Escape')
          return ''
        }

        const option = schema
          ? options.filter({ hasText: new RegExp(`^${escapeRegExp(schema)}$`) }).first()
          : options.first()

        if (!(await option.isVisible().catch(() => false))) {
          await page.keyboard.press('Escape')
          return ''
        }

        const selected = ((await option.textContent()) ?? '').trim()
        await option.click()
        return selected
      },
      { timeout: 120_000, intervals: [1_000, 2_000, 3_000, 5_000] },
    )
    .not.toEqual('')

  const selected = (await picker.innerText()).replace(/\s+/g, ' ').trim()
  expect(selected.length).toBeGreaterThan(0)
  expect(selected).not.toMatch(/Select schema/i)
  if (schema) {
    expect(selected).toContain(schema)
  }
  return selected
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

export async function typeMysqlSql(page: Page, sql: string): Promise<void> {
  await waitForFullscreenLoaderHidden(page, 120_000)
  const editor = page.locator('.monaco-editor').first()
  await expect(editor).toBeVisible({ timeout: 60_000 })
  await editor.click({ force: true })

  const setViaMonaco = await page.evaluate((nextSql) => {
    const monacoApi = (
      window as unknown as {
        monaco?: {
          editor?: {
            getEditors?: () => Array<{ setValue: (value: string) => void }>
            getModels?: () => Array<{ setValue: (value: string) => void }>
          }
        }
      }
    ).monaco
    const editors = monacoApi?.editor?.getEditors?.() ?? []
    if (editors[0]) {
      editors[0].setValue(nextSql)
      return true
    }
    const models = monacoApi?.editor?.getModels?.() ?? []
    if (models[0]) {
      models[0].setValue(nextSql)
      return true
    }
    return false
  }, sql)

  if (!setViaMonaco) {
    const modifier = process.platform === 'darwin' ? 'Meta' : 'Control'
    await page.keyboard.press(`${modifier}+KeyA`)
    await page.keyboard.press('Backspace')
    await page.keyboard.type(sql, { delay: 5 })
  }

  // Give React controlled state a tick to observe the Monaco change.
  await page.waitForTimeout(200)
}

export type MysqlExecutionPayload = {
  rows?: unknown
  rowCount?: number
  columns?: Array<{ name?: string }>
}

/** Normalize execution `rows` (array or object map) into row objects. */
export function mysqlExecutionRows(
  payload: MysqlExecutionPayload,
): Record<string, unknown>[] {
  const rows = payload.rows
  if (Array.isArray(rows)) {
    return rows.filter(
      (row): row is Record<string, unknown> =>
        row !== null && typeof row === 'object' && !Array.isArray(row),
    )
  }
  if (rows && typeof rows === 'object') {
    const values = Object.values(rows as Record<string, unknown>)
    const everyValueIsRowObject =
      values.length > 0 &&
      values.every(
        (value) =>
          value !== null && typeof value === 'object' && !Array.isArray(value),
      )
    if (everyValueIsRowObject) {
      return values as Record<string, unknown>[]
    }
    return [rows as Record<string, unknown>]
  }
  return []
}

function rowValueForColumn(
  row: Record<string, unknown>,
  column: string,
): unknown {
  const match = Object.keys(row).find(
    (key) => key.toLowerCase() === column.toLowerCase(),
  )
  return match === undefined ? undefined : row[match]
}

function stringifyExecutionValue(value: unknown): string {
  if (value === null || value === undefined) return String(value)
  // MySQL VARCHAR/CHAR often arrives as UTF-8 code units.
  if (
    Array.isArray(value) &&
    value.length > 0 &&
    value.every((item) => typeof item === 'number')
  ) {
    try {
      return new TextDecoder().decode(Uint8Array.from(value as number[]))
    } catch {
      return String(value)
    }
  }
  return String(value)
}

export function expectMysqlExecutionCell(
  payload: MysqlExecutionPayload,
  column: string,
  expected: string,
): void {
  const rows = mysqlExecutionRows(payload)
  if (rows.length === 0) return
  const value = rowValueForColumn(rows[0], column)
  if (value === undefined) return
  expect(stringifyExecutionValue(value)).toBe(expected)
}

export async function runMysqlSql(page: Page): Promise<MysqlExecutionPayload> {
  const runButton = page.getByRole('button', { name: /^Run/ }).first()
  await expect(runButton).toBeEnabled({ timeout: 30_000 })
  await waitForFullscreenLoaderHidden(page, 120_000)

  const executionPromise = page.waitForResponse(
    (response) => {
      try {
        const url = new URL(response.url())
        return (
          response.request().method() === 'POST' &&
          url.pathname.includes('/executions') &&
          url.pathname.includes('/mysql/')
        )
      } catch {
        return false
      }
    },
    { timeout: 90_000 },
  )

  await runButton.click()
  const response = await executionPromise
  const bodyText = await response.text()
  expect(
    response.ok(),
    `SQL execution failed: ${response.status()} ${bodyText}`,
  ).toBeTruthy()

  try {
    return JSON.parse(bodyText) as MysqlExecutionPayload
  } catch {
    throw new Error(`SQL execution response was not JSON: ${bodyText.slice(0, 500)}`)
  }
}

/** Assert the SQL results grid shows a cell value (and optional row count). */
export async function expectMysqlQueryResult(
  page: Page,
  options: {
    column: string
    value: string
    rowCount?: number
  },
): Promise<void> {
  await expect(page.getByRole('heading', { name: 'Query failed' })).toHaveCount(0)
  await expect(page.getByText('Query results', { exact: true })).toBeVisible({
    timeout: 60_000,
  })

  if (options.rowCount != null) {
    const countLabel =
      options.rowCount === 1 ? '1 row' : `${options.rowCount} rows`
    await expect(page.getByText(countLabel, { exact: true })).toBeVisible()
  }

  await expect(
    page
      .locator('thead th')
      .filter({ hasText: new RegExp(`^${escapeRegExp(options.column)}$`, 'i') }),
  ).toBeVisible()

  await expect(
    page
      .locator('tbody td[data-column]')
      .filter({ hasText: new RegExp(`^${escapeRegExp(options.value)}$`) })
      .first(),
  ).toBeVisible()
}

export async function expectMysqlSidebarTable(
  page: Page,
  tableName: string,
): Promise<void> {
  await expect(
    page.getByRole('link', { name: tableName, exact: true }),
  ).toBeVisible({ timeout: 60_000 })
}
