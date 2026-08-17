import { expect, type Page } from '@playwright/test'
import { enableDatabaseFeatureFlags } from './feature-flags'
import { acceptCookieBannerIfPresent } from './cookie-banner'
import { waitForFullscreenLoaderHidden } from './fullscreen-loader'
import {
  escapeRegExp,
  fillWizardDatabaseName,
  openCreateDatabaseWizard,
  selectFirstEnabledSpecification,
  selectWizardDatabaseType,
  uniqueSuffix,
  type WizardDatabaseType,
} from './wizard'

export type NativeEngine = 'mysql' | 'postgres'

export type CreatedNativeDatabase = {
  engine: NativeEngine
  databaseId: string
  databaseName: string
}

/** Dedicated native provisioning against production can take several minutes. */
export const NATIVE_PROVISION_TIMEOUT_MS = 10 * 60_000

type NativeEngineConfig = {
  wizardType: WizardDatabaseType
  label: string
  pathKind: 'mysql' | 'postgres'
  createPath: RegExp
  executionPathIncludes: string[]
  typeSelectorId: string
}

const ENGINE: Record<NativeEngine, NativeEngineConfig> = {
  mysql: {
    wizardType: 'MySQL',
    label: 'MySQL',
    pathKind: 'mysql',
    createPath: /\/v1\/mysql\/?$/,
    executionPathIncludes: ['/mysql/'],
    typeSelectorId: 'mysql-column-type',
  },
  postgres: {
    wizardType: 'Postgres',
    label: 'PostgreSQL',
    pathKind: 'postgres',
    createPath: /\/v1\/postgres(?:ql)?\/?$/,
    executionPathIncludes: ['/postgresql/', '/postgres/'],
    typeSelectorId: 'postgres-column-type',
  },
}

export function quoteIdent(engine: NativeEngine, name: string): string {
  if (engine === 'postgres') {
    return `"${name.replace(/"/g, '""')}"`
  }
  return `\`${name.replace(/`/g, '``')}\``
}

export function nativeDatabasePath(
  engine: NativeEngine,
  projectId: string,
  databaseId: string,
  suffix = '',
): string {
  const base = `/projects/${projectId}/databases/${ENGINE[engine].pathKind}/${databaseId}`
  return suffix ? `${base}${suffix.startsWith('/') ? suffix : `/${suffix}`}` : base
}

/**
 * Create a dedicated MySQL or PostgreSQL database via the fullscreen wizard
 * and wait until the shell route loads.
 */
export async function createNativeDatabaseViaWizard(
  page: Page,
  projectId: string,
  engine: NativeEngine,
  options?: { namePrefix?: string },
): Promise<CreatedNativeDatabase> {
  await enableDatabaseFeatureFlags(page)
  const config = ENGINE[engine]
  const databaseName =
    `${options?.namePrefix ?? `e2e-${engine}-db`}-${uniqueSuffix()}`.slice(0, 128)

  await openCreateDatabaseWizard(page, projectId)
  await selectWizardDatabaseType(page, config.wizardType)

  await expect(page.getByText(config.label).first()).toBeVisible({
    timeout: 15_000,
  })
  await fillWizardDatabaseName(page, databaseName)
  await selectFirstEnabledSpecification(page)

  const createButton = page.getByRole('button', { name: 'Create database' })
  await expect(createButton).toBeEnabled({ timeout: 30_000 })

  const createResponsePromise = page.waitForResponse(
    (response) => {
      try {
        const url = new URL(response.url())
        return (
          response.request().method() === 'POST' &&
          config.createPath.test(url.pathname) &&
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
      `${config.label} create failed: ${createResponse.status()} ${await createResponse.text()}`,
    )
  }
  const body = (await createResponse.json()) as { $id?: string }
  const databaseId = body.$id
  if (!databaseId) {
    throw new Error(
      `${config.label} create response missing $id: ${JSON.stringify(body).slice(0, 500)}`,
    )
  }

  await expect(page).toHaveURL(
    new RegExp(
      `/projects/${projectId}/databases/${config.pathKind}/${databaseId}`,
    ),
    { timeout: NATIVE_PROVISION_TIMEOUT_MS },
  )

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
  await expect(
    page.getByText(databaseName, { exact: false }).first(),
  ).toBeVisible({ timeout: 60_000 })

  return { engine, databaseId, databaseName }
}

/** Navigate to a native-DB shell tab and assert it rendered. */
export async function expectNativeTabRenders(
  page: Page,
  engine: NativeEngine,
  projectId: string,
  databaseId: string,
  tabPath: string,
  options?: {
    ready?: () => ReturnType<Page['locator']>
    timeout?: number
  },
): Promise<void> {
  const timeout = options?.timeout ?? 90_000
  const path = nativeDatabasePath(engine, projectId, databaseId, tabPath)

  await page.goto(path, { waitUntil: 'domcontentloaded', timeout })
  await acceptCookieBannerIfPresent(page)
  await expect(page).toHaveURL(new RegExp(tabPath.replace(/\//g, '\\/')), {
    timeout,
  })
  await expect(page.getByText('Loading database...')).toHaveCount(0, {
    timeout,
  })
  await expect(page.getByText(/Database not found/i)).toHaveCount(0)
  await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)
  await expect(
    page.getByRole('heading', { name: 'Error', exact: true }),
  ).toHaveCount(0)

  if (options?.ready) {
    await expect(options.ready()).toBeVisible({ timeout })
  }
}

/**
 * Open the schema picker and select a schema.
 * Managed MySQL denies CREATE SCHEMA for admin, so tests must use an existing
 * application schema. Postgres typically exposes `public`.
 */
export async function selectNativeSchema(
  page: Page,
  schema?: string,
): Promise<string> {
  await waitForFullscreenLoaderHidden(page, 120_000)

  const picker = page.getByRole('button', { name: 'Schema' }).first()
  await expect(picker).toBeVisible({ timeout: 60_000 })

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
          ? options
              .filter({ hasText: new RegExp(`^${escapeRegExp(schema)}$`) })
              .first()
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

export async function typeNativeSql(page: Page, sql: string): Promise<void> {
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

  await page.waitForTimeout(200)
}

export type NativeExecutionPayload = {
  rows?: unknown
  rowCount?: number
  columns?: Array<{ name?: string }>
}

export function nativeExecutionRows(
  payload: NativeExecutionPayload,
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

export function expectNativeExecutionCell(
  payload: NativeExecutionPayload,
  column: string,
  expected: string,
): void {
  const rows = nativeExecutionRows(payload)
  if (rows.length === 0) return
  const value = rowValueForColumn(rows[0], column)
  if (value === undefined) return
  expect(stringifyExecutionValue(value)).toBe(expected)
}

export async function runNativeSql(
  page: Page,
  engine: NativeEngine,
): Promise<NativeExecutionPayload> {
  const runButton = page.getByRole('button', { name: /^Run/ }).first()
  await expect(runButton).toBeEnabled({ timeout: 30_000 })
  await waitForFullscreenLoaderHidden(page, 120_000)

  const executionPromise = page.waitForResponse(
    (response) => {
      try {
        const url = new URL(response.url())
        if (
          response.request().method() !== 'POST' ||
          !url.pathname.includes('/executions')
        ) {
          return false
        }
        return ENGINE[engine].executionPathIncludes.some((part) =>
          url.pathname.includes(part),
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
    return JSON.parse(bodyText) as NativeExecutionPayload
  } catch {
    throw new Error(
      `SQL execution response was not JSON: ${bodyText.slice(0, 500)}`,
    )
  }
}

export async function expectNativeQueryResult(
  page: Page,
  options: {
    column: string
    value: string
    rowCount?: number
  },
): Promise<void> {
  await expect(page.getByRole('heading', { name: 'Query failed' })).toHaveCount(
    0,
  )
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

export async function expectNativeSidebarTable(
  page: Page,
  tableName: string,
): Promise<void> {
  await expect(
    page.getByRole('link', { name: tableName, exact: true }),
  ).toBeVisible({ timeout: 60_000 })
}

export async function openNativeSqlEditor(
  page: Page,
  engine: NativeEngine,
  projectId: string,
  databaseId: string,
): Promise<void> {
  await page.goto(nativeDatabasePath(engine, projectId, databaseId, '/sql'), {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  })
  await expect(page.getByText('Loading database...')).toHaveCount(0, {
    timeout: 120_000,
  })
  await expect(page.locator('.monaco-editor').first()).toBeVisible({
    timeout: 120_000,
  })
}

export async function createNativeTableViaUi(
  page: Page,
  tableName: string,
): Promise<void> {
  const createTableButton = page.getByRole('button', { name: 'Create table' })
  await expect(createTableButton).toBeEnabled({ timeout: 60_000 })
  await createTableButton.click()

  await expect(page.getByRole('heading', { name: 'Create table' })).toBeVisible({
    timeout: 30_000,
  })
  await page.locator('#table-name').fill(tableName)
  await expect(page.locator('[data-fullscreen-loader]')).toHaveCount(0, {
    timeout: 60_000,
  })
  await page
    .locator('form')
    .filter({ has: page.locator('#table-name') })
    .getByRole('button', { name: 'Create', exact: true })
    .click()
  await expect(page.getByText('Table created')).toBeVisible({
    timeout: 90_000,
  })
  await expect(page.getByRole('dialog', { name: 'Create table' })).toHaveCount(
    0,
    { timeout: 30_000 },
  )
  await expectNativeSidebarTable(page, tableName)
}

export async function selectNativeColumnType(
  page: Page,
  engine: NativeEngine,
  typeSearch: string,
): Promise<void> {
  const trigger = page.locator(`#${ENGINE[engine].typeSelectorId}`)
  await expect(trigger).toBeVisible({ timeout: 15_000 })
  await trigger.click()
  const search = page.getByPlaceholder('Search types...')
  await expect(search).toBeVisible({ timeout: 10_000 })
  await search.fill(typeSearch)
  const option = page
    .locator('[cmdk-item], [data-slot="command-item"]')
    .filter({
      has: page.locator('span').filter({
        hasText: new RegExp(`^${escapeRegExp(typeSearch)}$`, 'i'),
      }),
    })
    .first()
  await expect(option).toBeVisible({ timeout: 10_000 })
  await option.click()
}

export async function addNativeColumnViaUi(
  page: Page,
  engine: NativeEngine,
  options: { name: string; typeSearch: string; unique?: boolean },
): Promise<void> {
  const addButton = page.getByRole('button', { name: 'Add column' }).first()
  await expect(addButton).toBeVisible({ timeout: 30_000 })
  await addButton.click()

  await expect(page.getByRole('heading', { name: 'Create column' })).toBeVisible(
    { timeout: 15_000 },
  )
  await page.locator('#column-name').fill(options.name)
  await selectNativeColumnType(page, engine, options.typeSearch)

  if (options.unique) {
    await page.locator('#column-unique').click()
  }

  await page
    .locator('form')
    .filter({ has: page.locator('#column-name') })
    .getByRole('button', { name: 'Create', exact: true })
    .click()
  await expect(page.getByText('Column created')).toBeVisible({
    timeout: 60_000,
  })
  await expect(page.getByText(options.name, { exact: true }).first()).toBeVisible(
    { timeout: 30_000 },
  )
}

export async function addNativeIndexViaUi(
  page: Page,
  options: {
    name: string
    algorithm: string
    column: string
    unique?: boolean
  },
): Promise<void> {
  const createButton = page.getByRole('button', { name: 'Create index' }).first()
  await expect(createButton).toBeVisible({ timeout: 30_000 })
  await createButton.click()

  await expect(page.getByRole('heading', { name: 'Create index' })).toBeVisible({
    timeout: 15_000,
  })
  await page.locator('#index-name').fill(options.name)

  const algorithmTrigger = page.locator('#index-algorithm')
  await algorithmTrigger.click()
  const search = page.getByPlaceholder('Search algorithms...')
  await expect(search).toBeVisible({ timeout: 10_000 })
  await search.fill(options.algorithm)
  const option = page
    .locator('[cmdk-item], [data-slot="command-item"]')
    .filter({ hasText: new RegExp(`^${escapeRegExp(options.algorithm)}$`, 'i') })
    .first()
  await expect(option).toBeVisible({ timeout: 10_000 })
  await option.click()

  if (options.unique) {
    await page.locator('#index-unique').click()
  }

  const columnLabel = page.locator('label').filter({
    hasText: new RegExp(`^${escapeRegExp(options.column)}$`),
  })
  await expect(columnLabel).toBeVisible({ timeout: 15_000 })
  await columnLabel.click()

  await page
    .locator('form')
    .filter({ has: page.locator('#index-name') })
    .getByRole('button', { name: 'Create', exact: true })
    .click()
  await expect(page.getByText('Index created')).toBeVisible({
    timeout: 60_000,
  })
  await expect(page.getByText(options.name, { exact: true }).first()).toBeVisible(
    { timeout: 30_000 },
  )
}

export async function renameNativeDatabase(
  page: Page,
  engine: NativeEngine,
  projectId: string,
  database: CreatedNativeDatabase,
): Promise<string> {
  await page.goto(
    nativeDatabasePath(engine, projectId, database.databaseId, '/settings'),
    { waitUntil: 'domcontentloaded', timeout: 60_000 },
  )
  await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)
  await expect(
    page.getByRole('heading', { name: 'Error', exact: true }),
  ).toHaveCount(0)
  await waitForFullscreenLoaderHidden(page, 120_000)

  const nameCard = page
    .locator('div.rounded-xl')
    .filter({
      has: page.getByRole('heading', { name: 'Name', exact: true }),
    })
    .first()
  await expect(nameCard).toBeVisible({ timeout: 60_000 })

  const input = nameCard.locator('input').first()
  await expect(input).toBeVisible({ timeout: 30_000 })

  const updatedName = `${database.databaseName}-renamed`.slice(0, 128)
  await input.fill(updatedName)

  const updateButton = nameCard.getByRole('button', {
    name: 'Update',
    exact: true,
  })
  await expect(updateButton).toBeEnabled({ timeout: 15_000 })
  await waitForFullscreenLoaderHidden(page, 60_000)

  const patchPromise = page.waitForResponse(
    (response) => {
      try {
        const url = new URL(response.url())
        return (
          response.request().method() === 'PATCH' &&
          url.pathname.includes(`/${database.databaseId}`) &&
          (engine === 'mysql'
            ? url.pathname.includes('/mysql/')
            : /\/postgres(?:ql)?\//.test(url.pathname))
        )
      } catch {
        return false
      }
    },
    { timeout: 60_000 },
  )

  await updateButton.click()
  const response = await patchPromise
  expect(response.ok(), await response.text()).toBeTruthy()

  await expect(page.getByText('Database name updated')).toBeVisible({
    timeout: 30_000,
  })
  await expect(input).toHaveValue(updatedName)
  return updatedName
}

/**
 * Create a schema-level enum from the Enums tab (PostgreSQL CREATE TYPE).
 * MySQL has no standalone enum types; do not call this on MySQL.
 */
export async function createNativeEnumViaUi(
  page: Page,
  options: { name: string; values: string[] },
): Promise<void> {
  const createButton = page.getByRole('button', { name: 'Create enum' }).first()
  await expect(createButton).toBeEnabled({ timeout: 60_000 })
  await createButton.click()

  await expect(page.getByRole('heading', { name: 'Create enum' })).toBeVisible({
    timeout: 15_000,
  })
  await page.locator('#enum-name').fill(options.name)

  const values = options.values.length > 0 ? options.values : ['draft']
  const firstValue = page.getByPlaceholder('Value').first()
  await expect(firstValue).toBeVisible({ timeout: 10_000 })
  await firstValue.fill(values[0])

  for (const extra of values.slice(1)) {
    await page.getByRole('button', { name: 'Add value' }).click()
    await page.getByPlaceholder('Value').last().fill(extra)
  }

  await page
    .locator('form')
    .filter({ has: page.locator('#enum-name') })
    .getByRole('button', { name: 'Create', exact: true })
    .click()
  await expect(page.getByText('Enum created')).toBeVisible({ timeout: 60_000 })
  await expect(page.getByText(options.name, { exact: true }).first()).toBeVisible(
    { timeout: 30_000 },
  )
}
