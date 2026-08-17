import { test as base } from './database-flags'
import { env } from '../config/env'
import { E2E_VIEWPORT } from '../config/viewport'
import { newE2ePage } from '../helpers/cookie-banner'
import {
  enableDatabaseFeatureFlags,
  seedDatabaseFeatureFlags,
} from '../helpers/feature-flags'
import {
  createProductDatabaseViaWizard,
  type CreatedProductDatabase,
  type ProductDbKind,
} from '../helpers/product-db'
import {
  createE2eProject,
  deleteE2eProject,
  type CreatedProject,
} from '../helpers/project-lifecycle'

export type DatabaseSuite = {
  project: CreatedProject
  database: CreatedProductDatabase
}

async function setupDatabaseSuite(
  browser: import('@playwright/test').Browser,
  kind: ProductDbKind,
  namePrefix: string,
  use: (suite: DatabaseSuite) => Promise<void>,
) {
  if (!env.E2E_ORG_ID) {
    await use({
      project: { projectId: '', projectName: '' },
      database: { kind, databaseId: '', databaseName: '' },
    })
    return
  }

  const context = await browser.newContext({
    storageState: 'e2e/.auth/auth.json',
    viewport: E2E_VIEWPORT,
    screen: E2E_VIEWPORT,
  })
  await seedDatabaseFeatureFlags(context)
  const page = await newE2ePage(context)

  let project: CreatedProject | undefined
  try {
    await enableDatabaseFeatureFlags(page)
    project = await createE2eProject(page, { namePrefix })
    const database = await createProductDatabaseViaWizard(
      page,
      project.projectId,
      kind,
      { namePrefix },
    )
    await use({ project, database })
  } finally {
    if (project?.projectId) {
      await deleteE2eProject(page, project).catch(() => undefined)
    }
    await context.close()
  }
}

export const tablesdbTest = base.extend<
  Record<string, never>,
  { tablesdbSuite: DatabaseSuite }
>({
  tablesdbSuite: [
    async ({ browser }, use) => {
      await setupDatabaseSuite(browser, 'tablesdb', 'e2e-tdb', use)
    },
    { scope: 'worker', timeout: 20 * 60_000 },
  ],
})

export const documentsdbTest = base.extend<
  Record<string, never>,
  { documentsdbSuite: DatabaseSuite }
>({
  documentsdbSuite: [
    async ({ browser }, use) => {
      await setupDatabaseSuite(browser, 'documentsdb', 'e2e-ddb', use)
    },
    { scope: 'worker', timeout: 25 * 60_000 },
  ],
})

export const vectorsdbTest = base.extend<
  Record<string, never>,
  { vectorsdbSuite: DatabaseSuite }
>({
  vectorsdbSuite: [
    async ({ browser }, use) => {
      await setupDatabaseSuite(browser, 'vectorsdb', 'e2e-vdb', use)
    },
    { scope: 'worker', timeout: 25 * 60_000 },
  ],
})

export { expect } from '../fixtures'
