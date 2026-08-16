import { test, expect } from '@playwright/test'
import { env } from './config/env'
import { enableMysqlFeatureFlags } from './helpers/feature-flags'
import {
  createMysqlDatabaseViaWizard,
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
      recordVideo: { dir: 'test-results/mysql-videos/data-setup' },
    })
    const page = await context.newPage()
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
    })
    const page = await context.newPage()
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
    await runMysqlSql(page)

    await expect(page.getByText('ok', { exact: true }).first()).toBeVisible({
      timeout: 60_000,
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
            .getByText(tableName)
            .or(page.getByRole('columnheader').first())
            .first(),
      },
    )

    await expectMysqlTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/columns`,
      {
        ready: () =>
          page
            .getByText(/id|Column/i)
            .or(page.getByRole('columnheader').first())
            .first(),
      },
    )

    await expectMysqlTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/indexes`,
      {
        ready: () =>
          page
            .getByText(/PRIMARY|Index|index/i)
            .or(page.getByRole('columnheader').first())
            .first(),
      },
    )

    await expectMysqlTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/settings`,
    )

    await expectMysqlTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/security`,
    )
  })

  test('SQL DDL creates a second table', async ({ page }) => {
    test.skip(!schemaName, 'Schema was not selected')

    await page.goto(
      mysqlDatabasePath(project.projectId, database.databaseId, '/sql'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )

    const ddlTable = 'e2e_sql_table'
    await typeMysqlSql(
      page,
      `CREATE TABLE \`${schemaName}\`.\`${ddlTable}\` (id BIGINT PRIMARY KEY, name VARCHAR(64));`,
    )
    await runMysqlSql(page)

    await expect(
      page
        .getByText(ddlTable)
        .or(page.getByText(/success|0 rows|affected/i))
        .first(),
    ).toBeVisible({ timeout: 60_000 })
  })
})
