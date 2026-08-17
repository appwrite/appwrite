import { type Page } from '@playwright/test'
import { productTest as test, expect } from './fixtures/product'
import { env } from './config/env'
import { enableDatabaseFeatureFlags } from './helpers/feature-flags'
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
        .getByText(/No tables|Fit to view|Create table|schema/i)
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
  test.describe.configure({ mode: 'serial', timeout: 15 * 60_000 })

  let coreTableId = ''
  let spatialTableId = ''

  test.beforeEach(async ({ page, productSuite }) => {
    test.skip(!env.E2E_ORG_ID, 'E2E_ORG_ID is required for database e2e')
    test.skip(!productSuite.tablesdb?.databaseId, 'TablesDB was not created')
    await enableDatabaseFeatureFlags(page)
  })

  test('TablesDB appears on databases list', async ({ page, productSuite }) => {
    const { project, tablesdb } = productSuite
    await page.goto(`/projects/${project.projectId}/databases`, {
      waitUntil: 'domcontentloaded',
      timeout: 60_000,
    })
    await expect(page.getByText(tablesdb!.databaseName).first()).toBeVisible({
      timeout: 60_000,
    })
  })

  test('workspace loads and create table is available', async ({
    page,
    productSuite,
  }) => {
    const { project, tablesdb } = productSuite
    await gotoProductDatabase(
      page,
      KIND,
      project.projectId,
      tablesdb!.databaseId,
    )
    await expect(page.getByText(tablesdb!.databaseName).first()).toBeVisible({
      timeout: 60_000,
    })
    await expect(
      page.getByRole('button', { name: 'Create table' }).first(),
    ).toBeEnabled({ timeout: 60_000 })
  })

  test('create core table and columns of each scalar type', async ({
    page,
    productSuite,
  }) => {
    const { project, tablesdb } = productSuite
    await gotoProductDatabase(
      page,
      KIND,
      project.projectId,
      tablesdb!.databaseId,
    )
    coreTableId = await createProductContainerViaUi(page, KIND, 'e2e_core')

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      tablesdb!.databaseId,
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
    productSuite,
  }) => {
    test.skip(!coreTableId, 'Core table was not created')
    const { project, tablesdb } = productSuite
    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      tablesdb!.databaseId,
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

  test('create a row on the core table', async ({ page, productSuite }) => {
    test.skip(!coreTableId, 'Core table was not created')
    const { project, tablesdb } = productSuite
    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      tablesdb!.databaseId,
      coreTableId,
      'rows',
    )
    await createTablesDbRowViaUi(page)
  })

  test('create spatial table with point / line / polygon and spatial index', async ({
    page,
    productSuite,
  }) => {
    const { project, tablesdb } = productSuite
    await gotoProductDatabase(
      page,
      KIND,
      project.projectId,
      tablesdb!.databaseId,
    )
    spatialTableId = await createProductContainerViaUi(page, KIND, 'e2e_spatial')
    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      tablesdb!.databaseId,
      spatialTableId,
      'columns',
    )
    await addTablesDbColumnViaUi(page, { key: 'location', typeLabel: 'Point' })
    await addTablesDbColumnViaUi(page, { key: 'path', typeLabel: 'Line' })
    await addTablesDbColumnViaUi(page, { key: 'area', typeLabel: 'Polygon' })

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      tablesdb!.databaseId,
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
    productSuite,
  }) => {
    test.skip(!coreTableId, 'Core table was not created')
    const { project, tablesdb } = productSuite
    await gotoProductDatabase(
      page,
      KIND,
      project.projectId,
      tablesdb!.databaseId,
    )
    await createProductContainerViaUi(page, KIND, 'e2e_related')

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      tablesdb!.databaseId,
      coreTableId,
      'columns',
    )
    await addTablesDbRelationshipColumnViaUi(page, {
      key: 'related',
      relatedTableName: 'e2e_related',
      relationshipType: 'One to many',
    })
  })

  test('core table settings and security tabs render', async ({
    page,
    productSuite,
  }) => {
    test.skip(!coreTableId, 'Core table was not created')
    const { project, tablesdb } = productSuite
    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      tablesdb!.databaseId,
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
      tablesdb!.databaseId,
      coreTableId,
      'security',
    )
    await expect(
      page.getByText(/permission|security|role/i).first(),
    ).toBeVisible({ timeout: 60_000 })
  })

  for (const tab of DATABASE_TABS) {
    test(`${tab.name} tab renders`, async ({ page, productSuite }) => {
      const { project, tablesdb } = productSuite
      await expectProductTabRenders(
        page,
        KIND,
        project.projectId,
        tablesdb!.databaseId,
        tab.path,
        { ready: () => tab.ready(page) },
      )
      await expect(page.getByText(/Something went wrong/i)).toHaveCount(0)
    })
  }

  for (const section of SETTINGS_SECTIONS) {
    test(`settings ${section.name} renders`, async ({ page, productSuite }) => {
      const { project, tablesdb } = productSuite
      await expectProductTabRenders(
        page,
        KIND,
        project.projectId,
        tablesdb!.databaseId,
        section.path,
        { ready: () => section.ready(page) },
      )
      await expect(page.getByText(/Something went wrong/i)).toHaveCount(0)
    })
  }
})
