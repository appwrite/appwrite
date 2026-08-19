import { expect, type Locator, type Page } from '@playwright/test'
import { enableDatabaseFeatureFlags } from './feature-flags'
import { acceptCookieBannerIfPresent } from './cookie-banner'
import { waitForFullscreenLoaderHidden } from './fullscreen-loader'
import {
  chooseCommandItem,
  clickInPage,
  expectToast,
  openCreatedDatabase,
  waitForDedicatedDatabaseReady,
} from './ui'
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

function nativeSqlEditorMount(page: Page) {
  return page.locator('.monaco-editor, textarea.inputarea').first()
}

/** Shell chrome is up; do not sit on "Loading database..." for minutes. */
export async function waitForNativeDatabaseShell(
  page: Page,
  timeout = 45_000,
): Promise<void> {
  await waitForFullscreenLoaderHidden(page, Math.min(30_000, timeout))
  const loading = page.getByText('Loading database...')
  const chrome = page
    .getByRole('heading', { name: /sql editor/i })
    .or(page.getByRole('button', { name: 'Schema' }))
    .or(page.getByRole('link', { name: 'SQL editor' }))
    .first()
  await expect(chrome.or(loading)).toBeVisible({ timeout })
  if (await loading.isVisible().catch(() => false)) {
    await expect(loading).toHaveCount(0, { timeout })
  }
}

/**
 * Monaco is lazy and can hang after a cold /sql navigation. Wait for attach
 * (not visibility: the root is often aria-hidden), then reload once.
 */
export async function waitForNativeSqlEditor(page: Page): Promise<void> {
  await waitForNativeDatabaseShell(page)
  const editor = nativeSqlEditorMount(page)
  const mounted = await editor
    .waitFor({ state: 'attached', timeout: 12_000 })
    .then(() => true)
    .catch(() => false)
  if (mounted) return
  await page.reload({ waitUntil: 'domcontentloaded', timeout: 45_000 })
  await waitForNativeDatabaseShell(page)
  await editor.waitFor({ state: 'attached', timeout: 30_000 })
}

async function recoverIfNativeServerError(page: Page): Promise<boolean> {
  const heading = page.getByRole('heading', { name: /Server Error|^Error$/ })
  if (!(await heading.first().isVisible().catch(() => false))) return false
  const tryAgain = page.getByRole('button', { name: 'Try again' })
  if (await tryAgain.isVisible().catch(() => false)) {
    await tryAgain.click()
  } else {
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 45_000 })
  }
  return true
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

  await clickInPage(createButton)

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

  const targetPath = nativeDatabasePath(engine, projectId, databaseId)
  const targetPattern = new RegExp(
    `/projects/${projectId}/databases/${config.pathKind}/${databaseId}`,
  )
  await openCreatedDatabase(
    page,
    targetPath,
    targetPattern,
    NATIVE_PROVISION_TIMEOUT_MS,
    { waitUntilReady: true },
  )
  await expect(page).toHaveURL(targetPattern, { timeout: 30_000 })
  await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)
  await expect(
    page.getByRole('heading', { name: 'Error', exact: true }),
  ).toHaveCount(0)
  await expect(
    page
      .getByRole('heading', { name: /sql editor/i })
      .or(page.getByRole('link', { name: 'SQL editor' }))
      .first(),
  ).toBeVisible({ timeout: 30_000 })
  await expect(
    page.getByText(databaseName, { exact: true }).first(),
  ).toBeVisible({ timeout: 30_000 })

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
  const timeout = options?.timeout ?? 30_000
  const path = nativeDatabasePath(engine, projectId, databaseId, tabPath)
  const deadline = Date.now() + Math.max(timeout, 90_000)

  const go = async () => {
    await page.goto(path, { waitUntil: 'domcontentloaded', timeout: 45_000 })
    await acceptCookieBannerIfPresent(page)
  }

  await go()
  await expect(page).toHaveURL(new RegExp(tabPath.replace(/\//g, '\\/')), {
    timeout,
  })

  let lastError: unknown
  while (Date.now() < deadline) {
    await waitForNativeDatabaseShell(
      page,
      Math.min(timeout, Math.max(5_000, deadline - Date.now())),
    )
    await waitForDedicatedDatabaseReady(
      page,
      Math.min(NATIVE_PROVISION_TIMEOUT_MS, Math.max(5_000, deadline - Date.now())),
    )
    await expect(page.getByText(/Database not found/i)).toHaveCount(0)
    await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)

    if (await recoverIfNativeServerError(page)) {
      await page.waitForTimeout(1_500)
      continue
    }

    if (!options?.ready) return

    try {
      await expect(options.ready()).toBeVisible({
        timeout: Math.min(timeout, Math.max(3_000, deadline - Date.now())),
      })
      return
    } catch (error) {
      lastError = error
      if (await recoverIfNativeServerError(page)) {
        await page.waitForTimeout(1_500)
        continue
      }
      await go()
    }
  }

  if (lastError) throw lastError
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
  await waitForNativeDatabaseShell(page)

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
  await waitForNativeSqlEditor(page)
  const editor = nativeSqlEditorMount(page)
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

function nativeSqlRetryDelayMs(bodyText: string): number {
  return bodyText.includes('read-only') ? 3_000 : 1_500
}

async function submitNativeDdlForm(
  page: Page,
  engine: NativeEngine,
  submit: Locator,
  successToast: string,
): Promise<void> {
  await expect(submit).toBeEnabled({ timeout: 15_000 })
  const deadline = Date.now() + 90_000
  let lastError = 'Create did not reach the SQL API'

  while (Date.now() < deadline) {
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
      { timeout: 30_000 },
    )
    await clickInPage(submit)
    const response = await executionPromise.catch(() => null)
    if (!response) {
      throw new Error(lastError)
    }
    if (response.ok()) {
      await expectToast(page, successToast)
      return
    }
    lastError = `SQL execution failed: ${response.status()} ${await response.text()}`
    if (!isRetriableNativeSqlFailure(response.status(), lastError)) {
      throw new Error(lastError)
    }
    await page.waitForTimeout(nativeSqlRetryDelayMs(lastError))
  }

  throw new Error(lastError)
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

function isRetriableNativeSqlFailure(status: number, bodyText: string): boolean {
  if (status === 409) {
    return (
      bodyText.includes('provisioning') ||
      bodyText.includes('starting') ||
      bodyText.includes('dedicated_database_not_available')
    )
  }
  if (status === 503) {
    return (
      bodyText.includes('database_outcome_unknown') ||
      bodyText.includes('outcome could not be confirmed') ||
      bodyText.includes('read-only') ||
      bodyText.includes('sidecar_unavailable')
    )
  }
  return false
}

export async function runNativeSql(
  page: Page,
  engine: NativeEngine,
): Promise<NativeExecutionPayload> {
  const runButton = page.getByRole('button', { name: /^Run/ }).first()
  await expect(runButton).toBeEnabled({ timeout: 15_000 })
  await waitForFullscreenLoaderHidden(page, 30_000)
  await waitForDedicatedDatabaseReady(page, NATIVE_PROVISION_TIMEOUT_MS)

  const isExecutionResponse = (response: {
    url: () => string
    request: () => { method: () => string }
  }) => {
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
  }

  const deadline = Date.now() + NATIVE_PROVISION_TIMEOUT_MS
  let lastStatus = 0
  let lastBody = ''

  while (Date.now() < deadline) {
    await expect(runButton).toBeEnabled({ timeout: 30_000 })
    const executionPromise = page.waitForResponse(isExecutionResponse, {
      timeout: 90_000,
    })
    await runButton.click()
    const response = await executionPromise
    lastStatus = response.status()
    lastBody = await response.text()

    if (response.ok()) {
      try {
        return JSON.parse(lastBody) as NativeExecutionPayload
      } catch {
        throw new Error(
          `SQL execution response was not JSON: ${lastBody.slice(0, 500)}`,
        )
      }
    }

    if (!isRetriableNativeSqlFailure(lastStatus, lastBody)) {
      break
    }
    const delayMs = lastBody.includes('read-only') ? 3_000 : 1_500
    await page.waitForTimeout(delayMs)
  }

  expect(
    lastStatus >= 200 && lastStatus < 300,
    `SQL execution failed: ${lastStatus} ${lastBody}`,
  ).toBeTruthy()

  return JSON.parse(lastBody) as NativeExecutionPayload
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
  await waitForNativeSqlEditor(page)
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
  await expectToast(page, 'Table created', 90_000)
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
  await chooseCommandItem(
    page,
    page.locator(`#${ENGINE[engine].typeSelectorId}`),
    'Search types...',
    typeSearch,
    new RegExp(`^${escapeRegExp(typeSearch)}$`, 'i'),
  )
}

export async function addNativeColumnViaUi(
  page: Page,
  engine: NativeEngine,
  options: {
    name: string
    typeSearch: string
    unique?: boolean
    enumValues?: string[]
  },
): Promise<void> {
  const addButton = page.getByRole('button', { name: 'Add column' }).first()
  await expect(addButton).toBeVisible({ timeout: 30_000 })
  await addButton.click()

  await expect(page.getByRole('heading', { name: 'Create column' })).toBeVisible(
    { timeout: 15_000 },
  )
  await selectNativeColumnType(page, engine, options.typeSearch)
  await page.locator('#column-name').fill(options.name)

  if (options.unique) {
    await page.locator('#column-unique').click()
  }

  if (options.enumValues?.length) {
    const firstValue = page.getByPlaceholder('Value').first()
    await expect(firstValue).toBeVisible({ timeout: 10_000 })
    await firstValue.fill(options.enumValues[0]!)
    for (const extra of options.enumValues.slice(1)) {
      await page.getByRole('button', { name: 'Add value' }).click()
      await page.getByPlaceholder('Value').last().fill(extra)
    }
  }

  await submitNativeDdlForm(
    page,
    engine,
    page
      .locator('form')
      .filter({ has: page.locator('#column-name') })
      .getByRole('button', { name: 'Create', exact: true }),
    'Column created',
  )
  await expect(page.getByRole('heading', { name: 'Create column' })).toHaveCount(
    0,
    { timeout: 15_000 },
  )

  const columnName = page
    .locator('code')
    .filter({ hasText: new RegExp(`^${escapeRegExp(options.name)}$`) })
    .or(page.getByText(options.name, { exact: true }))
    .first()
  try {
    await expect(columnName).toBeVisible({ timeout: 15_000 })
  } catch {
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 60_000 })
    await waitForNativeDatabaseShell(page)
    await expect(columnName).toBeVisible({ timeout: 30_000 })
  }
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

  await chooseCommandItem(
    page,
    page.locator('#index-algorithm'),
    'Search algorithms...',
    options.algorithm,
    new RegExp(`^${escapeRegExp(options.algorithm)}$`, 'i'),
  )
  await page.locator('#index-name').fill(options.name)

  if (options.unique) {
    await page.locator('#index-unique').click()
  }

  const columnLabel = page
    .locator('form')
    .filter({ has: page.locator('#index-name') })
    .locator('label')
    .filter({
      hasText: new RegExp(`^${escapeRegExp(options.column)}$`),
    })
    .first()
  await expect(columnLabel).toBeVisible({ timeout: 15_000 })
  await columnLabel.click()

  await clickInPage(
    page
      .locator('form')
      .filter({ has: page.locator('#index-name') })
      .getByRole('button', { name: 'Create', exact: true }),
  )
  await expectToast(page, 'Index created')
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
  await waitForNativeDatabaseShell(page)

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
  await waitForDedicatedDatabaseReady(page, NATIVE_PROVISION_TIMEOUT_MS)

  const isRenameResponse = (response: {
    url: () => string
    request: () => { method: () => string }
  }) => {
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
  }

  const deadline = Date.now() + NATIVE_PROVISION_TIMEOUT_MS
  let lastError = 'Rename did not reach the API'
  while (Date.now() < deadline) {
    const patchPromise = page.waitForResponse(isRenameResponse, {
      timeout: 60_000,
    })
    await updateButton.click()
    const response = await patchPromise
    if (response.ok()) {
      await expect(page.getByText('Database name updated')).toBeVisible({
        timeout: 30_000,
      })
      await expect(input).toHaveValue(updatedName)
      return updatedName
    }
    lastError = await response.text()
    if (
      !lastError.includes('cannot_modify') &&
      !lastError.includes('scaling') &&
      !lastError.includes('provisioning')
    ) {
      throw new Error(lastError)
    }
    await page.waitForTimeout(3_000)
    await waitForDedicatedDatabaseReady(
      page,
      Math.min(NATIVE_PROVISION_TIMEOUT_MS, Math.max(5_000, deadline - Date.now())),
    )
    await expect(updateButton).toBeEnabled({ timeout: 15_000 })
  }

  throw new Error(lastError)
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
  await expectToast(page, 'Enum created')
  await expect(page.getByText(options.name, { exact: true }).first()).toBeVisible(
    { timeout: 30_000 },
  )
}
