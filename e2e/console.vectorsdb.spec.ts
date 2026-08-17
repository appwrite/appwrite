import { type Page } from '@playwright/test'
import { vectorsdbTest as test, expect } from './fixtures/product'
import { env } from './config/env'
import {
  addCollectionIndexViaUi,
  createProductContainerViaUi,
  createVectorsDbDocumentViaUi,
  expectProductTabRenders,
  gotoProductContainerTab,
  gotoProductDatabase,
} from './helpers/product-db'

const KIND = 'vectorsdb' as const

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
            /Loading schema|Fit to view|No collections|Copy schema|Export as SVG/i,
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

test.describe('console vectorsdb', () => {
  test.describe.configure({ mode: 'serial', timeout: 20 * 60_000 })

  let collectionId = ''

  test.beforeEach(async ({ vectorsdbSuite }) => {
    test.skip(!env.E2E_ORG_ID, 'E2E_ORG_ID is required for database e2e')
    test.skip(!vectorsdbSuite.database.databaseId, 'VectorsDB was not created')
  })

  test('VectorsDB appears on databases list', async ({ page, vectorsdbSuite }) => {
    const { project, database } = vectorsdbSuite
    await page.goto(`/projects/${project.projectId}/databases`, {
      waitUntil: 'domcontentloaded',
      timeout: 60_000,
    })
    await expect(page.getByText(database.databaseName).first()).toBeVisible({
      timeout: 60_000,
    })
  })

  test('workspace loads and create collection is available', async ({
    page,
    vectorsdbSuite,
  }) => {
    const { project, database } = vectorsdbSuite
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
      page.getByRole('button', { name: 'Create collection' }).first(),
    ).toBeEnabled({ timeout: 60_000 })
  })

  test('create collection with embedding dimension and a document', async ({
    page,
    vectorsdbSuite,
  }) => {
    const { project, database } = vectorsdbSuite
    await gotoProductDatabase(
      page,
      KIND,
      project.projectId,
      database.databaseId,
    )
    collectionId = await createProductContainerViaUi(
      page,
      KIND,
      'e2e_embeddings',
      { embeddingModelSearch: 'all-minilm' },
    )

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      collectionId,
      'documents',
    )
    await createVectorsDbDocumentViaUi(page, {
      text: 'Vector doc',
    })
  })

  test('create key and unique indexes on system attributes', async ({
    page,
    vectorsdbSuite,
  }) => {
    test.skip(!collectionId, 'Collection was not created')
    const { project, database } = vectorsdbSuite
    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      collectionId,
      'indexes',
    )

    await addCollectionIndexViaUi(page, {
      key: 'idx_id',
      typeLabel: 'Key',
      attribute: '$id',
    })
    await addCollectionIndexViaUi(page, {
      key: 'idx_created_unique',
      typeLabel: 'Unique',
      attribute: '$createdAt',
    })
    await addCollectionIndexViaUi(page, {
      key: 'idx_updated_key',
      typeLabel: 'Key',
      attribute: '$updatedAt',
    })
  })

  test('documents, settings, and security tabs render', async ({
    page,
    vectorsdbSuite,
  }) => {
    test.skip(!collectionId, 'Collection was not created')
    const { project, database } = vectorsdbSuite

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      collectionId,
      'documents',
    )
    await expect(
      page.getByText(/Vector doc|Documents|Create document|embedding/i).first(),
    ).toBeVisible({ timeout: 60_000 })

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      collectionId,
      'settings',
    )
    await expect(
      page.getByText(/e2e_embeddings|Collection|Delete|dimension/i).first(),
    ).toBeVisible({ timeout: 60_000 })

    await gotoProductContainerTab(
      page,
      KIND,
      project.projectId,
      database.databaseId,
      collectionId,
      'security',
    )
    await expect(
      page.getByText(/permission|security|role/i).first(),
    ).toBeVisible({ timeout: 60_000 })
  })

  for (const tab of DATABASE_TABS) {
    test(`${tab.name} tab renders`, async ({ page, vectorsdbSuite }) => {
      const { project, database } = vectorsdbSuite
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
    test(`settings ${section.name} renders`, async ({ page, vectorsdbSuite }) => {
      const { project, database } = vectorsdbSuite
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
