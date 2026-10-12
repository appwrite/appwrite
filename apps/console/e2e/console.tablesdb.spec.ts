import { type Page } from '@playwright/test'
import { tablesdbTest as test, expect } from './fixtures/product'
import { env } from './config/env'
import {
  addTablesDbColumnViaUi,
  addTablesDbIndexViaUi,
  addTablesDbRelationshipColumnViaUi,
  createProductContainerViaUi,
  createTablesDbRowViaUi,
  expectProductTabRenders,
  gotoProductContainerTab,
  gotoProductDatabase,
} from './helpers/product-db'
import { expectToast } from './helpers/ui'

const KIND = 'tablesdb' as const

const DATABASE_TABS: Array<{
  name: string
  path: string
  ready: (page: Page) => ReturnType<Page['locator']>
}> = [
  {
    name: 'visualizer',
    path: '/visualizer',
    ready: (page) =>
      page
        .getByRole('heading', { name: 'Visualizer' })
        .or(
          page.getByText(
            /e2e_core|Loading schema|Fit to view|No tables|Copy schema|Export as SVG/i,
          ),
        )
        .first(),
  },
  {
    name: 'monitor',
    path: '/monitor',
    ready: (page) =>
      page
        .getByRole('navigation', { name: 'Monitor metrics' })
        .or(page.getByText(/Operations|Requests|Usage/i))
        .first(),
  },
  {
    name: 'backups',
    path: '/backups',
    ready: (page) =>
      page.getByText(/Backup|Policy|Create backup|No backups|Snapshots/i).first(),
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
    name: 'specification',
    path: '/settings/specification',
    ready: (page) =>
      page
        .getByRole('heading', { name: /Specification|Compute|Serverless/i })
        .or(page.getByText(/Serverless|Tier/i))
        .first(),
  },
  {
    name: 'security',
    path: '/settings/security',
    ready: (page) =>
      page
        .getByRole('heading', { name: /Security|Permissions/i })
        .or(page.getByText(/permission/i))
        .first(),
  },
]

test.describe('console tablesdb', () => {
  test.describe.configure({ mode: 'serial', timeout: 20 * 60_000 })

  let coreTableId = ''
  let spatialTableId = ''

  test.beforeEach(async ({ tablesdbSuite }) => {
    test.skip(!env.E2E_ORG_ID, 'E2E_ORG_ID is required for database e2e')
    test.skip(!tablesdbSuite.database.databaseId, 'TablesDB was not created')
  })

  test('TablesDB appears on databases list', async ({ page, tablesdbSuite }) => {
    const { project, database } = tablesdbSuite
    await page.goto(`/projects/${project.projectId}/databases`, {
      waitUntil: 'domcontentloaded',
      timeout: 60_000,
    })
    await expect(page.getByText(database.databaseName).first()).toBeVisible({
      timeout: 60_000,
    })
  })

  test('workspace loads and create table is available', async ({
    page,
    tablesdbSuite,
  }) => {
    const { project, database } = tablesdbSuite
    await gotoProductDatabase(
      page,
      KIND,
      project.projectId,
      database.databaseId,
    )
    await expect(page.getByText(database.databaseName).first()).toBeVisible({
      timeout: 60_000,
    })
    await expect(
      page.getByRole('button', { name: 'Create table' }).first(),
    ).toBeEnabled({ timeout: 60_000 })
  })

  test('create core table and columns of each scalar type', async ({
    page,
    tablesdbSuite,
  }) => {
    const { project, database } = tablesdbSuite
    await gotoProductDatabase(
      page,
      KIND,
      project.projectId,
      database.databaseId,
    )
    coreTableId = await createProductContainerViaUi(page, KIND, 'e2e_core')

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      coreTableId,
      'columns',
    )

    const columns: Array<{ key: string; typeLabel: string }> = [
      { key: 'title', typeLabel: 'Varchar' },
      { key: 'body', typeLabel: 'Text' },
      { key: 'summary', typeLabel: 'Mediumtext' },
      { key: 'article', typeLabel: 'Longtext' },
      { key: 'count', typeLabel: 'Integer' },
      { key: 'big_count', typeLabel: 'Bigint' },
      { key: 'price', typeLabel: 'Float' },
      { key: 'active', typeLabel: 'Boolean' },
      { key: 'published_at', typeLabel: 'Datetime' },
      { key: 'email', typeLabel: 'Email' },
      { key: 'ip', typeLabel: 'IP' },
      { key: 'website', typeLabel: 'URL' },
      { key: 'status', typeLabel: 'Enum' },
    ]

    for (const column of columns) {
      await addTablesDbColumnViaUi(page, {
        key: column.key,
        typeLabel: column.typeLabel,
        enumElements: column.typeLabel === 'Enum' ? ['draft', 'published'] : undefined,
      })
    }
  })

  test('create key, unique, and fulltext indexes on core table', async ({
    page,
    tablesdbSuite,
  }) => {
    test.skip(!coreTableId, 'Core table was not created')
    const { project, database } = tablesdbSuite
    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      coreTableId,
      'indexes',
    )

    await addTablesDbIndexViaUi(page, {
      key: 'idx_title',
      typeLabel: 'Key',
      column: 'title',
    })
    await addTablesDbIndexViaUi(page, {
      key: 'idx_email_unique',
      typeLabel: 'Unique',
      column: 'email',
    })
    await addTablesDbIndexViaUi(page, {
      key: 'idx_body_fulltext',
      typeLabel: 'Fulltext',
      column: 'body',
    })
  })

  test('create a row on the core table', async ({ page, tablesdbSuite }) => {
    test.skip(!coreTableId, 'Core table was not created')
    const { project, database } = tablesdbSuite
    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      coreTableId,
      'rows',
    )
    await createTablesDbRowViaUi(page)
  })

  test('create spatial table with point / line / polygon and spatial index', async ({
    page,
    tablesdbSuite,
  }) => {
    const { project, database } = tablesdbSuite
    await gotoProductDatabase(
      page,
      KIND,
      project.projectId,
      database.databaseId,
    )
    spatialTableId = await createProductContainerViaUi(page, KIND, 'e2e_spatial')
    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      spatialTableId,
      'columns',
    )
    await addTablesDbColumnViaUi(page, {
      key: 'location',
      typeLabel: 'Point',
      required: true,
    })
    await addTablesDbColumnViaUi(page, { key: 'path', typeLabel: 'Line' })
    await addTablesDbColumnViaUi(page, { key: 'area', typeLabel: 'Polygon' })

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      spatialTableId,
      'indexes',
    )
    await addTablesDbIndexViaUi(page, {
      key: 'idx_location_spatial',
      typeLabel: 'Spatial',
      column: 'location',
    })
  })

  test('create related table and a relationship column', async ({
    page,
    tablesdbSuite,
  }) => {
    test.skip(!coreTableId, 'Core table was not created')
    const { project, database } = tablesdbSuite
    await gotoProductDatabase(
      page,
      KIND,
      project.projectId,
      database.databaseId,
    )
    await createProductContainerViaUi(page, KIND, 'e2e_related')

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      coreTableId,
      'columns',
    )
    await addTablesDbRelationshipColumnViaUi(page, {
      key: 'related',
      relatedTableName: 'e2e_related',
      relationshipType: 'One to many',
    })
  })

  test('relationship column resolves in the grid and survives a row save', async ({
    page,
    tablesdbSuite,
  }) => {
    test.skip(!coreTableId, 'Core table was not created')
    const { project, database } = tablesdbSuite
    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      coreTableId,
      'rows',
    )

    // The API resolves relationships only for a request whose select is dotted
    // (`related.*`). Without one the value never arrives and the cell renders the
    // literal string "null" - the regression this asserts against.
    const relatedCell = page.locator('td[data-column="related"]').first()
    await expect(relatedCell).toBeVisible({ timeout: 30_000 })
    await expect(relatedCell).toHaveText(/item/i)
    await expect(relatedCell).not.toHaveText(/^null$/)

    // Saving must not send the relationship back: it used to go out as
    // `"related": null`, which the API rejects on a to-many and unlinks a to-one.
    const rowId = await page
      .locator('tbody tr')
      .first()
      .locator('td[data-column="$id"]')
      .first()
      .innerText()
    await page.goto(`${page.url().split('#')[0]}#row-${rowId.trim()}`)

    const drawer = page.getByRole('dialog').last()
    await expect(
      drawer.getByRole('heading', { name: 'Update row' }),
    ).toBeVisible({ timeout: 30_000 })
    // Saving without touching anything is the reported case: the editor used to
    // send every column, so the unrequested relationship went back as null.
    await drawer.getByRole('button', { name: /^Update$/ }).click()

    await expectToast(page, /Row updated successfully|Row updated/)
    await expect(
      page.getByText(/Invalid relationship value/i),
    ).toHaveCount(0)
    await expect(relatedCell).toHaveText(/item/i)
  })

  test('core table settings and security tabs render', async ({
    page,
    tablesdbSuite,
  }) => {
    test.skip(!coreTableId, 'Core table was not created')
    const { project, database } = tablesdbSuite
    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      coreTableId,
      'settings',
    )
    await expect(
      page.getByText(/e2e_core|Table|Name|Delete/i).first(),
    ).toBeVisible({ timeout: 60_000 })

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      coreTableId,
      'security',
    )
    await expect(
      page.getByText(/permission|security|role/i).first(),
    ).toBeVisible({ timeout: 60_000 })
  })

  for (const tab of DATABASE_TABS) {
    test(`${tab.name} tab renders`, async ({ page, tablesdbSuite }) => {
      const { project, database } = tablesdbSuite
      await expectProductTabRenders(
        page,
        KIND,
        project.projectId,
        database.databaseId,
        tab.path,
        { ready: () => tab.ready(page) },
      )
      await expect(page.getByText(/Something went wrong/i)).toHaveCount(0)
    })
  }

  for (const section of SETTINGS_SECTIONS) {
    test(`settings ${section.name} renders`, async ({ page, tablesdbSuite }) => {
      const { project, database } = tablesdbSuite
      await expectProductTabRenders(
        page,
        KIND,
        project.projectId,
        database.databaseId,
        section.path,
        { ready: () => section.ready(page) },
      )
      await expect(page.getByText(/Something went wrong/i)).toHaveCount(0)
    })
  }
})
