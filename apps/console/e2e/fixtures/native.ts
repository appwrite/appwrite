import { test as base } from './database-flags'
import { env } from '../config/env'
import { E2E_VIEWPORT } from '../config/viewport'
import { newE2ePage } from '../helpers/cookie-banner'
import {
  enableDatabaseFeatureFlags,
  seedDatabaseFeatureFlags,
} from '../helpers/feature-flags'
import {
  createNativeDatabaseViaWizard,
  type CreatedNativeDatabase,
  type NativeEngine,
} from '../helpers/native-db'
import {
  createE2eProject,
  deleteE2eProject,
  DATABASE_SUITE_FIXTURE_TIMEOUT_MS,
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
  })
  await seedDatabaseFeatureFlags(context)
  const page = await newE2ePage(context)

  let project: CreatedProject | undefined
  try {
    await enableDatabaseFeatureFlags(page)
    project = await createE2eProject(page, { namePrefix })
    const database = await createNativeDatabaseViaWizard(
      page,
      project.projectId,
      engine,
      { namePrefix },
    )
    await use({ project, database })
  } finally {
    try {
      if (project?.projectId) {
        await deleteE2eProject(page, project)
      }
    } finally {
      await context.close()
    }
  }
}

export const mysqlTest = base.extend<
  Record<string, never>,
  { mysqlSuite: NativeSuite }
>({
  mysqlSuite: [
    async ({ browser }, use) => {
      await setupNativeSuite(browser, 'mysql', 'e2e-sqlm', use)
    },
    { scope: 'worker', timeout: DATABASE_SUITE_FIXTURE_TIMEOUT_MS },
  ],
})

export const postgresTest = base.extend<
  Record<string, never>,
  { postgresSuite: NativeSuite }
>({
  postgresSuite: [
    async ({ browser }, use) => {
      await setupNativeSuite(browser, 'postgres', 'e2e-sqlp', use)
    },
    { scope: 'worker', timeout: DATABASE_SUITE_FIXTURE_TIMEOUT_MS },
  ],
})

export { expect } from '../fixtures'
