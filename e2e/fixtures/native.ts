import { test as base } from '../fixtures'
import { env } from '../config/env'
import { E2E_VIEWPORT } from '../config/viewport'
import { newE2ePage } from '../helpers/cookie-banner'
import { enableDatabaseFeatureFlags } from '../helpers/feature-flags'
import {
  createNativeDatabaseViaWizard,
  type CreatedNativeDatabase,
  type NativeEngine,
} from '../helpers/native-db'
import {
  createE2eProject,
  deleteE2eProject,
  type CreatedProject,
} from '../helpers/project-lifecycle'

export type NativeSuite = {
  project: CreatedProject
  database: CreatedNativeDatabase
}

async function setupNativeSuite(
  browser: import('@playwright/test').Browser,
  engine: NativeEngine,
  namePrefix: string,
  use: (suite: NativeSuite) => Promise<void>,
) {
  if (!env.E2E_ORG_ID) {
    await use({
      project: { projectId: '', projectName: '' },
      database: { engine, databaseId: '', databaseName: '' },
    })
    return
  }

  const context = await browser.newContext({
    storageState: 'e2e/.auth/auth.json',
    viewport: E2E_VIEWPORT,
    screen: E2E_VIEWPORT,
    recordVideo: {
      dir: `test-results/${engine}-videos/suite-setup`,
      size: E2E_VIEWPORT,
    },
  })
  const page = await newE2ePage(context)

  let project: CreatedProject | undefined
  try {
    await enableDatabaseFeatureFlags(page)
    project = await createE2eProject(page, { namePrefix })
    const database = await createNativeDatabaseViaWizard(
      page,
      project.projectId,
      engine,
      { namePrefix: engine },
    )
    await use({ project, database })
  } finally {
    if (project?.projectId) {
      await deleteE2eProject(page, project).catch(() => undefined)
    }
    await context.close()
  }
}

export const mysqlTest = base.extend<
  Record<string, never>,
  { mysqlSuite: NativeSuite }
>({
  mysqlSuite: [
    async ({ browser }, use) => {
      await setupNativeSuite(browser, 'mysql', 'e2e-mysql', use)
    },
    { scope: 'worker', timeout: 15 * 60_000 },
  ],
})

export const postgresTest = base.extend<
  Record<string, never>,
  { postgresSuite: NativeSuite }
>({
  postgresSuite: [
    async ({ browser }, use) => {
      await setupNativeSuite(browser, 'postgres', 'e2e-pg', use)
    },
    { scope: 'worker', timeout: 15 * 60_000 },
  ],
})

export { expect } from '../fixtures'
