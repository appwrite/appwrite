import { test, expect } from './fixtures'
import { env } from './config/env'
import { E2E_VIEWPORT } from './config/viewport'
import { newE2ePage } from './helpers/cookie-banner'
import { enableMysqlFeatureFlags } from './helpers/feature-flags'
import {
  createMysqlDatabaseViaWizard,
  expectMysqlExecutionCell,
  expectMysqlQueryResult,
  expectMysqlSidebarTable,
  expectMysqlTabRenders,
  mysqlDatabasePath,
  runMysqlSql,
  selectMysqlSchema,
  typeMysqlSql,
  type CreatedMysqlDatabase,
} from './helpers/mysql'
import {
  createE2eProject,
  deleteE2eProject,
  type CreatedProject,
} from './helpers/project-lifecycle'

/**
 * Suite: schema / table / SQL editor coverage against a fresh project + MySQL DB.
 *
 * Managed MySQL denies CREATE SCHEMA for the admin user, so tests select an
 * existing application schema from the sidebar picker.
 */
test.describe('console mysql data', () => {
  test.describe.configure({ mode: 'serial', timeout: 15 * 60_000 })

  let project: CreatedProject
  let database: CreatedMysqlDatabase
  let schemaName = ''
  const tableName = `e2e_items`

  test.beforeAll(async ({ browser }) => {
    test.skip(!env.E2E_ORG_ID, 'E2E_ORG_ID is required for MySQL e2e')

    const context = await browser.newContext({
      storageState: 'e2e/.auth/auth.json',
      viewport: E2E_VIEWPORT,
      screen: E2E_VIEWPORT,
      recordVideo: { dir: 'test-results/mysql-videos/data-setup', size: E2E_VIEWPORT },
    })
    const page = await newE2ePage(context)
    try {
      await enableMysqlFeatureFlags(page)
      project = await createE2eProject(page, { namePrefix: 'e2e-mysql-data' })
      database = await createMysqlDatabaseViaWizard(page, project.projectId, {
        namePrefix: 'data',
      })
    } finally {
      await context.close()
    }
  })

  test.afterAll(async ({ browser }) => {
    if (!project?.projectId) return
    const context = await browser.newContext({
      storageState: 'e2e/.auth/auth.json',
      viewport: E2E_VIEWPORT,
      screen: E2E_VIEWPORT,
    })
    const page = await newE2ePage(context)
    try {
      await deleteE2eProject(page, project)
    } finally {
      await context.close()
    }
  })

  test.beforeEach(async ({ page }) => {
    await enableMysqlFeatureFlags(page)
  })

  test('SQL editor runs SELECT 1', async ({ page }) => {
    await page.goto(
      mysqlDatabasePath(project.projectId, database.databaseId, '/sql'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page).toHaveURL(/\/sql/, { timeout: 60_000 })
    await expect(page.getByText('Loading database...')).toHaveCount(0, {
      timeout: 120_000,
    })
    await expect(page.locator('.monaco-editor').first()).toBeVisible({
      timeout: 120_000,
    })
    await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)
    await expect(
      page.getByRole('heading', { name: 'Error', exact: true }),
    ).toHaveCount(0)

    await typeMysqlSql(page, 'SELECT 1 AS ok;')
    const execution = await runMysqlSql(page)

    expectMysqlExecutionCell(execution, 'ok', '1')
    if (execution.rowCount != null) {
      expect(Number(execution.rowCount)).toBe(1)
    }
    await expectMysqlQueryResult(page, {
      column: 'ok',
      value: '1',
      rowCount: 1,
    })
  })

  test('select existing schema in the sidebar', async ({ page }) => {
    await page.goto(
      mysqlDatabasePath(project.projectId, database.databaseId, '/sql'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page.getByText('Loading database...')).toHaveCount(0, {
      timeout: 120_000,
    })

    // Managed MySQL scopes admin to a schema named after the database $id
    // (not the display name). Fall back to the first listed application schema.
    try {
      schemaName = await selectMysqlSchema(page, database.databaseId)
    } catch {
      schemaName = await selectMysqlSchema(page)
    }
    expect(schemaName.length).toBeGreaterThan(0)
    await expect(page.getByRole('button', { name: 'Create table' })).toBeEnabled(
      { timeout: 30_000 },
    )
  })

  test('create table and open rows / columns / indexes tabs', async ({
    page,
  }) => {
    test.skip(!schemaName, 'Schema was not selected')

    await page.goto(
      mysqlDatabasePath(project.projectId, database.databaseId, '/sql'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page.getByText('Loading database...')).toHaveCount(0, {
      timeout: 120_000,
    })

    await selectMysqlSchema(page, schemaName)

    const createTableButton = page.getByRole('button', { name: 'Create table' })
    await expect(createTableButton).toBeEnabled({ timeout: 60_000 })
    await createTableButton.click()

    await expect(
      page.getByRole('heading', { name: 'Create table' }),
    ).toBeVisible({ timeout: 30_000 })

    await page.locator('#table-name').fill(tableName)
    await expect(page.locator('[data-fullscreen-loader]')).toHaveCount(0, {
      timeout: 60_000,
    })
    await page.getByRole('button', { name: 'Create', exact: true }).click()

    await expect(page.getByText('Table created')).toBeVisible({
      timeout: 90_000,
    })
    await expect(page.getByRole('dialog', { name: 'Create table' })).toHaveCount(
      0,
      { timeout: 30_000 },
    )
    await expectMysqlSidebarTable(page, tableName)

    const tableId = `${schemaName}.${tableName}`
    const encoded = encodeURIComponent(tableId)

    await expectMysqlTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/rows`,
      {
        ready: () =>
          page
            .getByRole('columnheader', { name: 'id', exact: true })
            .or(page.getByText('No results for query'))
            .first(),
      },
    )
    await expectMysqlSidebarTable(page, tableName)

    await expectMysqlTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/columns`,
      {
        ready: () => page.getByText('id', { exact: true }).first(),
      },
    )
    await expect(page.getByText('created_at', { exact: true }).first()).toBeVisible()

    await expectMysqlTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/indexes`,
      {
        ready: () =>
          page
            .getByText('PRIMARY', { exact: true })
            .or(page.getByText('Primary key'))
            .first(),
      },
    )

    await expectMysqlTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/settings`,
      {
        ready: () =>
          page.getByRole('heading', { name: 'Table properties' }),
      },
    )
    await expect(page.getByText(tableName).first()).toBeVisible()

    await expectMysqlTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/security`,
      {
        ready: () =>
          page.getByRole('heading', { name: /Row level security/ }),
      },
    )
  })

  test('SQL DDL creates a second table', async ({ page }) => {
    test.skip(!schemaName, 'Schema was not selected')

    await page.goto(
      mysqlDatabasePath(project.projectId, database.databaseId, '/sql'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page.getByText('Loading database...')).toHaveCount(0, {
      timeout: 120_000,
    })
    await expect(page.locator('.monaco-editor').first()).toBeVisible({
      timeout: 120_000,
    })
    await selectMysqlSchema(page, schemaName)

    const ddlTable = 'e2e_sql_table'
    await typeMysqlSql(
      page,
      `CREATE TABLE \`${schemaName}\`.\`${ddlTable}\` (id BIGINT PRIMARY KEY, name VARCHAR(64));`,
    )
    await runMysqlSql(page)
    await expect(page.getByRole('heading', { name: 'Query failed' })).toHaveCount(
      0,
    )
    await expect(
      page
        .getByText('Query results', { exact: true })
        .or(page.getByText('0 rows', { exact: true }))
        .first(),
    ).toBeVisible({ timeout: 60_000 })

    await typeMysqlSql(
      page,
      `SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = '${schemaName}' AND TABLE_NAME = '${ddlTable}';`,
    )
    const execution = await runMysqlSql(page)
    expectMysqlExecutionCell(execution, 'table_name', ddlTable)
    await expectMysqlQueryResult(page, {
      column: 'table_name',
      value: ddlTable,
      rowCount: 1,
    })
    await expectMysqlSidebarTable(page, ddlTable)
  })
})
