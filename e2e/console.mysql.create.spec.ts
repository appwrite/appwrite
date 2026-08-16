import { test, expect } from '@playwright/test'
import { env } from './config/env'
import { enableMysqlFeatureFlags } from './helpers/feature-flags'
import {
  createMysqlDatabaseViaWizard,
  type CreatedMysqlDatabase,
} from './helpers/mysql'
import {
  createE2eProject,
  deleteE2eProject,
  type CreatedProject,
} from './helpers/project-lifecycle'

/**
 * Suite: create a project, provision a dedicated MySQL database via the wizard,
 * verify the shell loads, then delete the entire project.
 */
test.describe('console mysql create', () => {
  test.describe.configure({ mode: 'serial', timeout: 15 * 60_000 })

  let project: CreatedProject
  let database: CreatedMysqlDatabase

  test.beforeAll(async ({ browser }) => {
    test.skip(!env.E2E_ORG_ID, 'E2E_ORG_ID is required for MySQL e2e')

    const context = await browser.newContext({
      storageState: 'e2e/.auth/auth.json',
      recordVideo: { dir: 'test-results/mysql-videos/create' },
    })
    const page = await context.newPage()
    try {
      await enableMysqlFeatureFlags(page)
      project = await createE2eProject(page, { namePrefix: 'e2e-mysql-create' })
    } finally {
      await context.close()
    }
  })

  test.afterAll(async ({ browser }) => {
    if (!project?.projectId) return
    const context = await browser.newContext({
      storageState: 'e2e/.auth/auth.json',
    })
    const page = await context.newPage()
    try {
      await deleteE2eProject(page, project)
    } finally {
      await context.close()
    }
  })

  test.beforeEach(async ({ page }) => {
    await enableMysqlFeatureFlags(page)
  })

  test('create dedicated MySQL via wizard and open shell', async ({ page }) => {
    database = await createMysqlDatabaseViaWizard(page, project.projectId, {
      namePrefix: 'wizard',
    })

    await expect(page).toHaveURL(
      new RegExp(
        `/projects/${project.projectId}/databases/mysql/${database.databaseId}`,
      ),
    )
    await expect(page.getByText(database.databaseName).first()).toBeVisible({
      timeout: 60_000,
    })
  })

  test('MySQL appears on databases list', async ({ page }) => {
    test.skip(!database?.databaseId, 'Database was not created')

    await page.goto(`/projects/${project.projectId}/databases`, {
      waitUntil: 'domcontentloaded',
      timeout: 60_000,
    })
    await expect(page.getByText(database.databaseName).first()).toBeVisible({
      timeout: 60_000,
    })
  })
})
