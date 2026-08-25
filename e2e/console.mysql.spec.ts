import { type Page } from '@playwright/test'
import { mysqlTest as test, expect } from './fixtures/native'
import { env } from './config/env'
import {
  addMysqlColumnViaUi,
  addMysqlIndexViaUi,
  createMysqlRoleViaUi,
  createMysqlTableViaUi,
  deleteMysqlRoleViaUi,
  expectMysqlExecutionCell,
  expectMysqlQueryResult,
  expectMysqlSidebarTable,
  expectMysqlTabRenders,
  mysqlDatabasePath,
  mysqlRoleName,
  mysqlRoleRow,
  mysqlTablePath,
  openMysqlRolesPage,
  openMysqlSqlEditor,
  quoteIdent,
  renameMysqlDatabase,
  runMysqlSql,
  selectMysqlSchema,
  typeMysqlSql,
  updateMysqlRoleViaUi,
  waitForMysqlDatabaseShell,
  waitForMysqlSqlEditor,
} from './helpers/mysql'

const DATABASE_TABS: Array<{
  name: string
  path: string
  ready: (page: Page) => ReturnType<Page['locator']>
}> = [
  {
    name: 'sql editor',
    path: '/sql',
    ready: (page) =>
      page
        .getByRole('heading', { name: /sql editor/i })
        .or(page.locator('[data-mysql-sql-editor], .monaco-editor'))
        .first(),
  },
  {
    name: 'visualizer',
    path: '/visualizer',
    ready: (page) =>
      page
        .getByRole('heading', { name: 'Visualizer' })
        .or(
          page.getByText(
            /Select a schema in the sidebar|no user tables or views|Fit to view/i,
          ),
        )
        .first(),
  },
  {
    name: 'monitor',
    path: '/monitor',
    ready: (page) =>
      page.getByRole('navigation', { name: 'Monitor metrics' }),
  },
  {
    name: 'backups',
    path: '/backups',
    ready: (page) =>
      page.getByText(/Backup|Policy|Create backup|No backups|Snapshots/i).first(),
  },
  {
    name: 'connections',
    path: '/connections',
    ready: (page) =>
      page.getByText(/Connect|Host|Connection|Clients|Backends/i).first(),
  },
  {
    name: 'roles',
    path: '/roles',
    ready: (page) =>
      page
        .getByRole('button', { name: /Create role/i })
        .or(page.getByText(/Create roles|No roles/i))
        .first(),
  },
]

const SETTINGS_SECTIONS: Array<{
  name: string
  path: string
  ready: (page: Page) => ReturnType<Page['locator']>
}> = [
  {
    name: 'general',
    path: '/settings',
    ready: (page) => page.getByRole('heading', { name: 'Name', exact: true }),
  },
  {
    name: 'compute',
    path: '/settings/compute',
    ready: (page) => page.getByRole('heading', { name: 'Compute tier' }),
  },
  {
    name: 'replication',
    path: '/settings/replication',
    ready: (page) => page.getByRole('heading', { name: 'Read replicas' }),
  },
  {
    name: 'network',
    path: '/settings/network',
    ready: (page) => page.getByRole('heading', { name: 'Network', exact: true }),
  },
  {
    name: 'pitr',
    path: '/settings/pitr',
    ready: (page) => page.getByRole('heading', { name: /Point-in-time recovery/ }),
  },
  {
    name: 'storage',
    path: '/settings/storage',
    ready: (page) => page.getByRole('heading', { name: 'Storage', exact: true }),
  },
  {
    name: 'maintenance',
    path: '/settings/maintenance',
    ready: (page) => page.getByRole('heading', { name: 'Maintenance window' }),
  },
]

/**
 * Dedicated MySQL coverage against one shared project + database (worker
 * fixture). Extra column and index scenarios create additional tables in that
 * database. Managed MySQL denies CREATE SCHEMA, so tests select an existing
 * application schema from the sidebar picker.
 */
test.describe('console mysql', () => {
  test.describe.configure({ mode: 'serial', timeout: 25 * 60_000 })

  let schemaName = ''
  const tableName = 'e2e_items'
  const typesTable = 'e2e_column_types'

  test.beforeEach(async ({ mysqlSuite }) => {
    test.skip(!env.E2E_ORG_ID, 'E2E_ORG_ID is required for MySQL e2e')
    test.skip(!mysqlSuite.database.databaseId, 'Database was not created')
  })

  test('dedicated MySQL shell loads', async ({ page, mysqlSuite }) => {
    const { project, database } = mysqlSuite

    await page.goto(
      `/projects/${project.projectId}/databases/mysql/${database.databaseId}`,
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page).toHaveURL(
      new RegExp(
        `/projects/${project.projectId}/databases/mysql/${database.databaseId}`,
      ),
    )
    await expect(page.getByText(database.databaseName).first()).toBeVisible({
      timeout: 60_000,
    })
    await expect(
      page
        .getByRole('heading', { name: /sql editor/i })
        .or(page.getByRole('link', { name: 'SQL editor' }))
        .first(),
    ).toBeVisible({ timeout: 60_000 })
    await waitForMysqlSqlEditor(page)
  })

  test('MySQL appears on databases list', async ({ page, mysqlSuite }) => {
    const { project, database } = mysqlSuite

    await page.goto(`/projects/${project.projectId}/databases`, {
      waitUntil: 'domcontentloaded',
      timeout: 60_000,
    })
    await expect(page.getByText(database.databaseName).first()).toBeVisible({
      timeout: 60_000,
    })
  })

  test('SQL editor runs SELECT 1', async ({ page, mysqlSuite }) => {
    const { project, database } = mysqlSuite
    await openMysqlSqlEditor(page, project.projectId, database.databaseId)
    await expect(page).toHaveURL(/\/sql/, { timeout: 60_000 })
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

  let e2eRoleName = ''

  test('create a role via UI', async ({ page, mysqlSuite }) => {
    const { project, database } = mysqlSuite
    e2eRoleName = mysqlRoleName()
    await openMysqlRolesPage(page, project.projectId, database.databaseId)
    await createMysqlRoleViaUi(page, { name: e2eRoleName })
    await expect(mysqlRoleRow(page, e2eRoleName)).toContainText('Unlimited')
  })

  test('update a role via UI', async ({ page, mysqlSuite }) => {
    test.skip(!e2eRoleName, 'Role was not created')
    const { project, database } = mysqlSuite
    await openMysqlRolesPage(page, project.projectId, database.databaseId)
    await updateMysqlRoleViaUi(page, {
      name: e2eRoleName,
      connectionLimit: 7,
    })
  })

  test('delete a role via UI', async ({ page, mysqlSuite }) => {
    test.skip(!e2eRoleName, 'Role was not created')
    const { project, database } = mysqlSuite
    await openMysqlRolesPage(page, project.projectId, database.databaseId)
    await deleteMysqlRoleViaUi(page, e2eRoleName)
  })

  test('select existing schema in the sidebar', async ({ page, mysqlSuite }) => {
    const { project, database } = mysqlSuite
    await page.goto(
      mysqlDatabasePath(project.projectId, database.databaseId, '/sql'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await waitForMysqlDatabaseShell(page)

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
    mysqlSuite,
  }) => {
    test.skip(!schemaName, 'Schema was not selected')
    const { project, database } = mysqlSuite

    await page.goto(
      mysqlDatabasePath(project.projectId, database.databaseId, '/sql'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await waitForMysqlDatabaseShell(page)
    await selectMysqlSchema(page, schemaName)
    await createMysqlTableViaUi(page, tableName)

    const encoded = encodeURIComponent(`${schemaName}.${tableName}`)

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
        ready: () => page.getByRole('heading', { name: 'Table properties' }),
      },
    )
    await expect(page.getByText(tableName).first()).toBeVisible()

    await expectMysqlTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/security`,
      {
        ready: () => page.getByRole('heading', { name: /Row level security/ }),
      },
    )
  })

  test('add varchar / text / integer / boolean / json columns via UI', async ({
    page,
    mysqlSuite,
  }) => {
    test.skip(!schemaName, 'Schema was not selected')
    const { project, database } = mysqlSuite

    await page.goto(
      mysqlTablePath(
        project.projectId,
        database.databaseId,
        schemaName,
        tableName,
        'columns',
      ),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page.getByText('id', { exact: true }).first()).toBeVisible({
      timeout: 60_000,
    })

    await addMysqlColumnViaUi(page, { name: 'title', typeSearch: 'Varchar' })
    await addMysqlColumnViaUi(page, { name: 'body', typeSearch: 'Text' })
    await addMysqlColumnViaUi(page, { name: 'amount', typeSearch: 'Integer' })
    await addMysqlColumnViaUi(page, { name: 'active', typeSearch: 'Boolean' })
    await addMysqlColumnViaUi(page, { name: 'payload', typeSearch: 'Json' })
    await addMysqlColumnViaUi(page, {
      name: 'status',
      typeSearch: 'Enum',
      enumValues: ['draft', 'published'],
    })
  })

  test('create btree and unique indexes via UI', async ({ page, mysqlSuite }) => {
    test.skip(!schemaName, 'Schema was not selected')
    const { project, database } = mysqlSuite

    await page.goto(
      mysqlTablePath(
        project.projectId,
        database.databaseId,
        schemaName,
        tableName,
        'indexes',
      ),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(
      page
        .getByText('PRIMARY', { exact: true })
        .or(page.getByText('Primary key'))
        .first(),
    ).toBeVisible({ timeout: 60_000 })

    await addMysqlIndexViaUi(page, {
      name: 'idx_e2e_title',
      algorithm: 'B-tree',
      column: 'title',
    })
    await addMysqlIndexViaUi(page, {
      name: 'idx_e2e_amount_unique',
      algorithm: 'B-tree',
      column: 'amount',
      unique: true,
    })
  })

  test('SQL DDL creates a second table with extra column types', async ({
    page,
    mysqlSuite,
  }) => {
    test.skip(!schemaName, 'Schema was not selected')
    const { project, database } = mysqlSuite
    const schema = quoteIdent('mysql', schemaName)
    const table = quoteIdent('mysql', typesTable)

    await openMysqlSqlEditor(page, project.projectId, database.databaseId)
    await selectMysqlSchema(page, schemaName)

    await typeMysqlSql(
      page,
      `CREATE TABLE ${schema}.${table} (
        id BIGINT PRIMARY KEY,
        label VARCHAR(64) NOT NULL,
        note TEXT,
        qty INT,
        price NUMERIC(10,2),
        flag BOOLEAN,
        created_on DATE,
        seen_at DATETIME,
        meta JSON
      );`,
    )
    await runMysqlSql(page)
    await expect(page.getByRole('heading', { name: 'Query failed' })).toHaveCount(
      0,
    )

    await typeMysqlSql(
      page,
      `SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = '${schemaName}' AND TABLE_NAME = '${typesTable}';`,
    )
    const execution = await runMysqlSql(page)
    expectMysqlExecutionCell(execution, 'table_name', typesTable)
    await expectMysqlQueryResult(page, {
      column: 'table_name',
      value: typesTable,
      rowCount: 1,
    })
    await expectMysqlSidebarTable(page, typesTable)
  })

  test('create extra table with remaining column types via UI', async ({
    page,
    mysqlSuite,
  }) => {
    test.skip(!schemaName, 'Schema was not selected')
    const { project, database } = mysqlSuite
    const extraTable = 'e2e_ui_types'

    await page.goto(
      mysqlDatabasePath(project.projectId, database.databaseId, '/sql'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await waitForMysqlDatabaseShell(page)
    await selectMysqlSchema(page, schemaName)
    await createMysqlTableViaUi(page, extraTable)

    await page.goto(
      mysqlTablePath(
        project.projectId,
        database.databaseId,
        schemaName,
        extraTable,
        'columns',
      ),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page.getByText('id', { exact: true }).first()).toBeVisible({
      timeout: 60_000,
    })

    await addMysqlColumnViaUi(page, { name: 'code', typeSearch: 'Char' })
    await addMysqlColumnViaUi(page, { name: 'small_qty', typeSearch: 'Smallint' })
    await addMysqlColumnViaUi(page, { name: 'big_qty', typeSearch: 'Bigint' })
    await addMysqlColumnViaUi(page, { name: 'price', typeSearch: 'Numeric' })
    await addMysqlColumnViaUi(page, { name: 'born_on', typeSearch: 'Date' })
    await addMysqlColumnViaUi(page, { name: 'seen_at', typeSearch: 'Timestamp' })
  })

  for (const tab of DATABASE_TABS) {
    test(`${tab.name} tab renders`, async ({ page, mysqlSuite }) => {
      const { project, database } = mysqlSuite
      await expectMysqlTabRenders(
        page,
        project.projectId,
        database.databaseId,
        tab.path,
        { ready: () => tab.ready(page) },
      )
      await expect(page.getByText(/Something went wrong/i)).toHaveCount(0)
    })
  }

  for (const section of SETTINGS_SECTIONS) {
    test(`settings ${section.name} renders`, async ({ page, mysqlSuite }) => {
      const { project, database } = mysqlSuite
      await expectMysqlTabRenders(
        page,
        project.projectId,
        database.databaseId,
        section.path,
        { ready: () => section.ready(page) },
      )
      await expect(page.getByText(/Something went wrong/i)).toHaveCount(0)
    })
  }

  test('update database display name in general settings', async ({
    page,
    mysqlSuite,
  }) => {
    const { project, database } = mysqlSuite
    const updatedName = await renameMysqlDatabase(
      page,
      project.projectId,
      database,
    )
    await page.reload({ waitUntil: 'domcontentloaded' })
    await expect(page.locator('[data-fullscreen-loader]')).toHaveCount(0, {
      timeout: 120_000,
    })
    const nameCardAfterReload = page
      .locator('div.rounded-xl')
      .filter({
        has: page.getByRole('heading', { name: 'Name', exact: true }),
      })
      .first()
    await expect(nameCardAfterReload.locator('input').first()).toHaveValue(
      updatedName,
      { timeout: 60_000 },
    )
  })
})
