import { type Page } from '@playwright/test'
import { postgresTest as test, expect } from './fixtures/native'
import { env } from './config/env'
import { enableDatabaseFeatureFlags } from './helpers/feature-flags'
import {
  addPostgresColumnViaUi,
  addPostgresIndexViaUi,
  createPostgresEnumViaUi,
  createPostgresTableViaUi,
  expectPostgresExecutionCell,
  expectPostgresQueryResult,
  expectPostgresSidebarTable,
  expectPostgresTabRenders,
  postgresDatabasePath,
  postgresTablePath,
  quoteIdent,
  renamePostgresDatabase,
  runPostgresSql,
  selectPostgresSchema,
  typePostgresSql,
} from './helpers/postgres'

const DATABASE_TABS: Array<{
  name: string
  path: string
  ready: (page: Page) => ReturnType<Page['locator']>
}> = [
  {
    name: 'sql editor',
    path: '/sql',
    ready: (page) => page.locator('.monaco-editor').first(),
  },
  {
    name: 'visualizer',
    path: '/visualizer',
    ready: (page) =>
      page
        .getByText(/Select a schema in the sidebar|no user tables or views|Fit to view/i)
        .first(),
  },
  {
    name: 'enums',
    path: '/enums',
    ready: (page) =>
      page
        .getByText(/No enums|Create enum/i)
        .or(page.getByRole('button', { name: /Create enum/i }))
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
    name: 'extensions',
    path: '/settings/extensions',
    ready: (page) =>
      page
        .getByRole('heading', { name: /Extension/i })
        .or(page.getByText(/No extensions|pgvector|postgis/i))
        .first(),
  },
  {
    name: 'maintenance',
    path: '/settings/maintenance',
    ready: (page) => page.getByRole('heading', { name: 'Maintenance window' }),
  },
]

/**
 * Dedicated PostgreSQL coverage against one shared project + database (worker
 * fixture). Extra column, index, and enum scenarios create additional objects
 * in that database.
 */
test.describe('console postgres', () => {
  test.describe.configure({ mode: 'serial', timeout: 15 * 60_000 })

  let schemaName = ''
  const tableName = 'e2e_items'
  const typesTable = 'e2e_column_types'

  test.beforeEach(async ({ page, postgresSuite }) => {
    test.skip(!env.E2E_ORG_ID, 'E2E_ORG_ID is required for Postgres e2e')
    test.skip(!postgresSuite.database.databaseId, 'Database was not created')
    await enableDatabaseFeatureFlags(page)
  })

  test('dedicated PostgreSQL shell loads', async ({ page, postgresSuite }) => {
    const { project, database } = postgresSuite

    await page.goto(
      `/projects/${project.projectId}/databases/postgres/${database.databaseId}`,
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page).toHaveURL(
      new RegExp(
        `/projects/${project.projectId}/databases/postgres/${database.databaseId}`,
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
    await expect(page.locator('.monaco-editor').first()).toBeVisible({
      timeout: 60_000,
    })
  })

  test('PostgreSQL appears on databases list', async ({
    page,
    postgresSuite,
  }) => {
    const { project, database } = postgresSuite

    await page.goto(`/projects/${project.projectId}/databases`, {
      waitUntil: 'domcontentloaded',
      timeout: 60_000,
    })
    await expect(page.getByText(database.databaseName).first()).toBeVisible({
      timeout: 60_000,
    })
  })

  test('SQL editor runs SELECT 1', async ({ page, postgresSuite }) => {
    const { project, database } = postgresSuite
    await page.goto(
      postgresDatabasePath(project.projectId, database.databaseId, '/sql'),
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

    await typePostgresSql(page, 'SELECT 1 AS ok;')
    const execution = await runPostgresSql(page)

    expectPostgresExecutionCell(execution, 'ok', '1')
    if (execution.rowCount != null) {
      expect(Number(execution.rowCount)).toBe(1)
    }
    await expectPostgresQueryResult(page, {
      column: 'ok',
      value: '1',
      rowCount: 1,
    })
  })

  test('select public schema in the sidebar', async ({ page, postgresSuite }) => {
    const { project, database } = postgresSuite
    await page.goto(
      postgresDatabasePath(project.projectId, database.databaseId, '/sql'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page.getByText('Loading database...')).toHaveCount(0, {
      timeout: 120_000,
    })

    try {
      schemaName = await selectPostgresSchema(page, 'public')
    } catch {
      schemaName = await selectPostgresSchema(page)
    }
    expect(schemaName.length).toBeGreaterThan(0)
    await expect(page.getByRole('button', { name: 'Create table' })).toBeEnabled(
      { timeout: 30_000 },
    )
  })

  test('create table and open rows / columns / indexes tabs', async ({
    page,
    postgresSuite,
  }) => {
    test.skip(!schemaName, 'Schema was not selected')
    const { project, database } = postgresSuite

    await page.goto(
      postgresDatabasePath(project.projectId, database.databaseId, '/sql'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page.getByText('Loading database...')).toHaveCount(0, {
      timeout: 120_000,
    })
    await selectPostgresSchema(page, schemaName)
    await createPostgresTableViaUi(page, tableName)

    const encoded = encodeURIComponent(`${schemaName}.${tableName}`)

    await expectPostgresTabRenders(
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
    await expectPostgresSidebarTable(page, tableName)

    await expectPostgresTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/columns`,
      {
        ready: () => page.getByText('id', { exact: true }).first(),
      },
    )
    await expect(
      page.getByText('created_at', { exact: true }).first(),
    ).toBeVisible()

    await expectPostgresTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/indexes`,
      {
        ready: () =>
          page
            .getByText('PRIMARY', { exact: true })
            .or(page.getByText('Primary key'))
            .or(page.getByText(tableName))
            .first(),
      },
    )

    await expectPostgresTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/settings`,
      {
        ready: () => page.getByRole('heading', { name: 'Table properties' }),
      },
    )

    await expectPostgresTabRenders(
      page,
      project.projectId,
      database.databaseId,
      `/tables/${encoded}/security`,
      {
        ready: () => page.getByRole('heading', { name: /Row level security/ }),
      },
    )
  })

  test('add varchar / text / integer / boolean / jsonb columns via UI', async ({
    page,
    postgresSuite,
  }) => {
    test.skip(!schemaName, 'Schema was not selected')
    const { project, database } = postgresSuite

    await page.goto(
      postgresTablePath(
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

    await addPostgresColumnViaUi(page, { name: 'title', typeSearch: 'Varchar' })
    await addPostgresColumnViaUi(page, { name: 'body', typeSearch: 'Text' })
    await addPostgresColumnViaUi(page, { name: 'amount', typeSearch: 'Integer' })
    await addPostgresColumnViaUi(page, { name: 'active', typeSearch: 'Boolean' })
    await addPostgresColumnViaUi(page, { name: 'payload', typeSearch: 'Jsonb' })
    await addPostgresColumnViaUi(page, { name: 'external_id', typeSearch: 'Uuid' })
  })

  test('create btree, unique, hash, and gin indexes via UI', async ({
    page,
    postgresSuite,
  }) => {
    test.skip(!schemaName, 'Schema was not selected')
    const { project, database } = postgresSuite

    await page.goto(
      postgresTablePath(
        project.projectId,
        database.databaseId,
        schemaName,
        tableName,
        'indexes',
      ),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )

    await addPostgresIndexViaUi(page, {
      name: 'idx_e2e_title',
      algorithm: 'B-tree',
      column: 'title',
    })
    await addPostgresIndexViaUi(page, {
      name: 'idx_e2e_amount_unique',
      algorithm: 'B-tree',
      column: 'amount',
      unique: true,
    })
    await addPostgresIndexViaUi(page, {
      name: 'idx_e2e_title_hash',
      algorithm: 'Hash',
      column: 'title',
    })
    await addPostgresIndexViaUi(page, {
      name: 'idx_e2e_payload_gin',
      algorithm: 'GIN',
      column: 'payload',
    })
  })

  test('SQL DDL creates a second table with extra column types', async ({
    page,
    postgresSuite,
  }) => {
    test.skip(!schemaName, 'Schema was not selected')
    const { project, database } = postgresSuite
    const schema = quoteIdent('postgres', schemaName)
    const table = quoteIdent('postgres', typesTable)

    await page.goto(
      postgresDatabasePath(project.projectId, database.databaseId, '/sql'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page.getByText('Loading database...')).toHaveCount(0, {
      timeout: 120_000,
    })
    await expect(page.locator('.monaco-editor').first()).toBeVisible({
      timeout: 120_000,
    })
    await selectPostgresSchema(page, schemaName)

    await typePostgresSql(
      page,
      `CREATE TABLE ${schema}.${table} (
        id BIGINT PRIMARY KEY,
        label VARCHAR(64) NOT NULL,
        note TEXT,
        qty INT,
        price NUMERIC(10,2),
        flag BOOLEAN,
        created_on DATE,
        seen_at TIMESTAMPTZ,
        meta JSONB,
        ip INET
      );`,
    )
    await runPostgresSql(page)
    await expect(
      page.getByRole('heading', { name: 'Query failed' }),
    ).toHaveCount(0)

    await typePostgresSql(
      page,
      `SELECT table_name FROM information_schema.tables WHERE table_schema = '${schemaName}' AND table_name = '${typesTable}';`,
    )
    const execution = await runPostgresSql(page)
    expectPostgresExecutionCell(execution, 'table_name', typesTable)
    await expectPostgresQueryResult(page, {
      column: 'table_name',
      value: typesTable,
      rowCount: 1,
    })
    await expectPostgresSidebarTable(page, typesTable)
  })

  test('create extra table with remaining column types via UI', async ({
    page,
    postgresSuite,
  }) => {
    test.skip(!schemaName, 'Schema was not selected')
    const { project, database } = postgresSuite
    const extraTable = 'e2e_ui_types'

    await page.goto(
      postgresDatabasePath(project.projectId, database.databaseId, '/sql'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page.getByText('Loading database...')).toHaveCount(0, {
      timeout: 120_000,
    })
    await selectPostgresSchema(page, schemaName)
    await createPostgresTableViaUi(page, extraTable)

    await page.goto(
      postgresTablePath(
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

    await addPostgresColumnViaUi(page, { name: 'code', typeSearch: 'Char' })
    await addPostgresColumnViaUi(page, { name: 'small_qty', typeSearch: 'Smallint' })
    await addPostgresColumnViaUi(page, { name: 'big_qty', typeSearch: 'Bigint' })
    await addPostgresColumnViaUi(page, { name: 'price', typeSearch: 'Numeric' })
    await addPostgresColumnViaUi(page, { name: 'born_on', typeSearch: 'Date' })
    await addPostgresColumnViaUi(page, { name: 'seen_at', typeSearch: 'Timestamp' })
    await addPostgresColumnViaUi(page, { name: 'ip', typeSearch: 'Inet' })
    await addPostgresColumnViaUi(page, { name: 'network', typeSearch: 'CIDR' })
  })

  test('create BRIN and SP-GiST indexes on extra table', async ({
    page,
    postgresSuite,
  }) => {
    test.skip(!schemaName, 'Schema was not selected')
    const { project, database } = postgresSuite

    await page.goto(
      postgresTablePath(
        project.projectId,
        database.databaseId,
        schemaName,
        'e2e_ui_types',
        'indexes',
      ),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )

    await addPostgresIndexViaUi(page, {
      name: 'idx_e2e_big_qty_brin',
      algorithm: 'BRIN',
      column: 'big_qty',
    })
    await addPostgresIndexViaUi(page, {
      name: 'idx_e2e_ip_spgist',
      algorithm: 'SP-GiST',
      column: 'ip',
    })
  })

  test('create schema enum via UI', async ({ page, postgresSuite }) => {
    test.skip(!schemaName, 'Schema was not selected')
    const { project, database } = postgresSuite

    await page.goto(
      postgresDatabasePath(project.projectId, database.databaseId, '/enums'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page.getByText('Loading database...')).toHaveCount(0, {
      timeout: 120_000,
    })
    await selectPostgresSchema(page, schemaName)
    await createPostgresEnumViaUi(page, {
      name: 'e2e_status',
      values: ['draft', 'published', 'archived'],
    })
  })

  for (const tab of DATABASE_TABS) {
    test(`${tab.name} tab renders`, async ({ page, postgresSuite }) => {
      const { project, database } = postgresSuite
      await expectPostgresTabRenders(
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
    test(`settings ${section.name} renders`, async ({ page, postgresSuite }) => {
      const { project, database } = postgresSuite
      await expectPostgresTabRenders(
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
    postgresSuite,
  }) => {
    const { project, database } = postgresSuite
    const updatedName = await renamePostgresDatabase(
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
