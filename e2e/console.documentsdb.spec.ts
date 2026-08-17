import { type Page } from '@playwright/test'
import { productTest as test, expect } from './fixtures/product'
import { env } from './config/env'
import { enableDatabaseFeatureFlags } from './helpers/feature-flags'
import {
  addCollectionIndexViaUi,
  createCollectionDocumentViaUi,
  createProductContainerViaUi,
  expectProductTabRenders,
  gotoProductContainerTab,
  gotoProductDatabase,
} from './helpers/product-db'

const KIND = 'documentsdb' as const

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
        .getByText(/No collections|Fit to view|Create collection|schema/i)
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

test.describe('console documentsdb', () => {
  test.describe.configure({ mode: 'serial', timeout: 15 * 60_000 })

  let collectionId = ''

  test.beforeEach(async ({ page, productSuite }) => {
    test.skip(!env.E2E_ORG_ID, 'E2E_ORG_ID is required for database e2e')
    test.skip(
      !productSuite.documentsdb?.databaseId,
      'DocumentsDB was not created',
    )
    await enableDatabaseFeatureFlags(page)
  })

  test('DocumentsDB appears on databases list', async ({
    page,
    productSuite,
  }) => {
    const { project, documentsdb } = productSuite
    await page.goto(`/projects/${project.projectId}/databases`, {
      waitUntil: 'domcontentloaded',
      timeout: 60_000,
    })
    await expect(page.getByText(documentsdb!.databaseName).first()).toBeVisible({
      timeout: 60_000,
    })
  })

  test('workspace loads and create collection is available', async ({
    page,
    productSuite,
  }) => {
    const { project, documentsdb } = productSuite
    await gotoProductDatabase(
      page,
      KIND,
      project.projectId,
      documentsdb!.databaseId,
    )
    await expect(page.getByText(documentsdb!.databaseName).first()).toBeVisible({
      timeout: 60_000,
    })
    await expect(
      page.getByRole('button', { name: 'Create collection' }).first(),
    ).toBeEnabled({ timeout: 60_000 })
  })

  test('create collection and a JSON document', async ({
    page,
    productSuite,
  }) => {
    const { project, documentsdb } = productSuite
    await gotoProductDatabase(
      page,
      KIND,
      project.projectId,
      documentsdb!.databaseId,
    )
    collectionId = await createProductContainerViaUi(
      page,
      KIND,
      'e2e_articles',
    )

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      documentsdb!.databaseId,
      collectionId,
      'documents',
    )
    await createCollectionDocumentViaUi(page, {
      title: 'Hello',
      body: 'First document body',
      status: 'published',
      views: 1,
    })
  })

  test('create key, unique, and fulltext indexes on custom attributes', async ({
    page,
    productSuite,
  }) => {
    test.skip(!collectionId, 'Collection was not created')
    const { project, documentsdb } = productSuite
    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      documentsdb!.databaseId,
      collectionId,
      'indexes',
    )

    await addCollectionIndexViaUi(page, {
      key: 'idx_title',
      typeLabel: 'Key',
      attribute: 'title',
    })
    await addCollectionIndexViaUi(page, {
      key: 'idx_status_unique',
      typeLabel: 'Unique',
      attribute: 'status',
    })
    await addCollectionIndexViaUi(page, {
      key: 'idx_body_fulltext',
      typeLabel: 'Fulltext',
      attribute: 'body',
    })
  })

  test('documents, json, settings, and security tabs render', async ({
    page,
    productSuite,
  }) => {
    test.skip(!collectionId, 'Collection was not created')
    const { project, documentsdb } = productSuite

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      documentsdb!.databaseId,
      collectionId,
      'documents',
    )
    await expect(
      page.getByText(/Hello|Documents|Create document/i).first(),
    ).toBeVisible({ timeout: 60_000 })

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      documentsdb!.databaseId,
      collectionId,
      'json',
    )
    await expect(page.getByText(/JSON|document/i).first()).toBeVisible({
      timeout: 60_000,
    })

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      documentsdb!.databaseId,
      collectionId,
      'settings',
    )
    await expect(page.getByText(/e2e_articles|Collection|Delete/i).first()).toBeVisible(
      { timeout: 60_000 },
    )

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      documentsdb!.databaseId,
      collectionId,
      'security',
    )
    await expect(
      page.getByText(/permission|security|role/i).first(),
    ).toBeVisible({ timeout: 60_000 })
  })

  for (const tab of DATABASE_TABS) {
    test(`${tab.name} tab renders`, async ({ page, productSuite }) => {
      const { project, documentsdb } = productSuite
      await expectProductTabRenders(
        page,
        KIND,
        project.projectId,
        documentsdb!.databaseId,
        tab.path,
        { ready: () => tab.ready(page) },
      )
      await expect(page.getByText(/Something went wrong/i)).toHaveCount(0)
    })
  }

  for (const section of SETTINGS_SECTIONS) {
    test(`settings ${section.name} renders`, async ({ page, productSuite }) => {
      const { project, documentsdb } = productSuite
      await expectProductTabRenders(
        page,
        KIND,
        project.projectId,
        documentsdb!.databaseId,
        section.path,
        { ready: () => section.ready(page) },
      )
      await expect(page.getByText(/Something went wrong/i)).toHaveCount(0)
    })
  }
})
