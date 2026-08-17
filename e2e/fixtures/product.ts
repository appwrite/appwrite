import { test as base } from '../fixtures'
import { env } from '../config/env'
import { E2E_VIEWPORT } from '../config/viewport'
import { newE2ePage } from '../helpers/cookie-banner'
import { enableDatabaseFeatureFlags } from '../helpers/feature-flags'
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

export type ProductDbSuite = {
  project: CreatedProject
  tablesdb: CreatedProductDatabase | null
  documentsdb: CreatedProductDatabase | null
  vectorsdb: CreatedProductDatabase | null
}

async function tryCreateProductDatabase(
  page: import('@playwright/test').Page,
  projectId: string,
  kind: ProductDbKind,
  namePrefix: string,
): Promise<CreatedProductDatabase | null> {
  try {
    return await createProductDatabaseViaWizard(page, projectId, kind, {
      namePrefix,
    })
  } catch (error) {
    console.error(`Failed to create ${kind} database for e2e:`, error)
    return null
  }
}

export const productTest = base.extend<
  Record<string, never>,
  { productSuite: ProductDbSuite }
>({
  productSuite: [
    async ({ browser }, use) => {
      if (!env.E2E_ORG_ID) {
        await use({
          project: { projectId: '', projectName: '' },
          tablesdb: null,
          documentsdb: null,
          vectorsdb: null,
        })
        return
      }

      const context = await browser.newContext({
        storageState: 'e2e/.auth/auth.json',
        viewport: E2E_VIEWPORT,
        screen: E2E_VIEWPORT,
        recordVideo: {
          dir: 'test-results/product-db-videos/suite-setup',
          size: E2E_VIEWPORT,
        },
      })
      const page = await newE2ePage(context)

      let project: CreatedProject | undefined
      try {
        await enableDatabaseFeatureFlags(page)
        project = await createE2eProject(page, {
          namePrefix: 'e2e-product-dbs',
        })

        const tablesdb = await tryCreateProductDatabase(
          page,
          project.projectId,
          'tablesdb',
          'tables',
        )
        const documentsdb = await tryCreateProductDatabase(
          page,
          project.projectId,
          'documentsdb',
          'docs',
        )
        const vectorsdb = await tryCreateProductDatabase(
          page,
          project.projectId,
          'vectorsdb',
          'vectors',
        )

        await use({ project, tablesdb, documentsdb, vectorsdb })
      } finally {
        if (project?.projectId) {
          await deleteE2eProject(page, project).catch(() => undefined)
        }
        await context.close()
      }
    },
    { scope: 'worker', timeout: 30 * 60_000 },
  ],
})

export { expect } from '../fixtures'
