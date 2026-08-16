import { test, expect } from '@playwright/test'
import { env } from './config/env'
import { enableMysqlFeatureFlags } from './helpers/feature-flags'
import {
  createMysqlDatabaseViaWizard,
  expectMysqlTabRenders,
  mysqlDatabasePath,
  type CreatedMysqlDatabase,
} from './helpers/mysql'
import {
  createE2eProject,
  deleteE2eProject,
  type CreatedProject,
} from './helpers/project-lifecycle'

const DATABASE_TABS = [
  { name: 'sql editor', path: '/sql', readyText: /Run|SQL|monaco/i },
  { name: 'visualizer', path: '/visualizer' },
  { name: 'enums', path: '/enums' },
  { name: 'monitor', path: '/monitor' },
  { name: 'backups', path: '/backups' },
  { name: 'connections', path: '/connections' },
  { name: 'roles', path: '/roles' },
] as const

const SETTINGS_SECTIONS = [
  { name: 'general', path: '/settings' },
  { name: 'compute', path: '/settings/compute' },
  { name: 'replication', path: '/settings/replication' },
  { name: 'network', path: '/settings/network' },
  { name: 'pitr', path: '/settings/pitr' },
  { name: 'storage', path: '/settings/storage' },
  { name: 'maintenance', path: '/settings/maintenance' },
] as const

/**
 * Suite: management surfaces (tabs + settings) on a fresh project + MySQL DB.
 */
test.describe('console mysql management', () => {
  test.describe.configure({ mode: 'serial', timeout: 15 * 60_000 })

  let project: CreatedProject
  let database: CreatedMysqlDatabase

  test.beforeAll(async ({ browser }) => {
    test.skip(!env.E2E_ORG_ID, 'E2E_ORG_ID is required for MySQL e2e')

    const context = await browser.newContext({
      storageState: 'e2e/.auth/auth.json',
      recordVideo: { dir: 'test-results/mysql-videos/management-setup' },
    })
    const page = await context.newPage()
    try {
      await enableMysqlFeatureFlags(page)
      project = await createE2eProject(page, {
        namePrefix: 'e2e-mysql-mgmt',
      })
      database = await createMysqlDatabaseViaWizard(page, project.projectId, {
        namePrefix: 'mgmt',
      })
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

  for (const tab of DATABASE_TABS) {
    test(`${tab.name} tab renders`, async ({ page }) => {
      await expectMysqlTabRenders(
        page,
        project.projectId,
        database.databaseId,
        tab.path,
      )
      await expect(page.locator('body')).toBeVisible()
      await expect(page.getByText(/Something went wrong/i)).toHaveCount(0)
    })
  }

  for (const section of SETTINGS_SECTIONS) {
    test(`settings ${section.name} renders`, async ({ page }) => {
      await expectMysqlTabRenders(
        page,
        project.projectId,
        database.databaseId,
        section.path,
      )
      await expect(page.getByText(/Something went wrong/i)).toHaveCount(0)
    })
  }

  test('update database display name in general settings', async ({ page }) => {
    await page.goto(
      mysqlDatabasePath(project.projectId, database.databaseId, '/settings'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)
    await expect(
      page.getByRole('heading', { name: 'Error', exact: true }),
    ).toHaveCount(0)
    // Settings can briefly show the global fullscreen loader over the form.
    await expect(page.locator('[data-fullscreen-loader]')).toHaveCount(0, {
      timeout: 120_000,
    })

    const nameCard = page
      .locator('div.rounded-xl')
      .filter({
        has: page.getByRole('heading', { name: 'Name', exact: true }),
      })
      .first()
    await expect(nameCard).toBeVisible({ timeout: 60_000 })

    const input = nameCard.locator('input').first()
    await expect(input).toBeVisible({ timeout: 30_000 })

    const updatedName = `${database.databaseName}-renamed`.slice(0, 128)
    await input.fill(updatedName)

    const updateButton = nameCard.getByRole('button', {
      name: 'Update',
      exact: true,
    })
    await expect(updateButton).toBeEnabled({ timeout: 15_000 })
    await expect(page.locator('[data-fullscreen-loader]')).toHaveCount(0, {
      timeout: 60_000,
    })

    const patchPromise = page.waitForResponse(
      (response) => {
        try {
          const url = new URL(response.url())
          return (
            response.request().method() === 'PATCH' &&
            url.pathname.includes(`/mysql/${database.databaseId}`)
          )
        } catch {
          return false
        }
      },
      { timeout: 60_000 },
    )

    await updateButton.click()
    const response = await patchPromise
    expect(response.ok(), await response.text()).toBeTruthy()

    await expect(
      page.getByText(/Database name updated|updated/i).first(),
    ).toBeVisible({ timeout: 30_000 })
  })

  test('roles empty / create role control is reachable', async ({ page }) => {
    await page.goto(
      mysqlDatabasePath(project.projectId, database.databaseId, '/roles'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(
      page
        .getByRole('button', { name: /Create role/i })
        .or(page.getByText(/Create roles|No roles/i))
        .first(),
    ).toBeVisible({ timeout: 60_000 })
  })

  test('connections page shows connection details entry', async ({ page }) => {
    await page.goto(
      mysqlDatabasePath(
        project.projectId,
        database.databaseId,
        '/connections',
      ),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(
      page
        .getByText(/Connect|Host|Connection|Clients|Backends/i)
        .first(),
    ).toBeVisible({ timeout: 60_000 })
  })

  test('backups page shows policies or empty state', async ({ page }) => {
    await page.goto(
      mysqlDatabasePath(project.projectId, database.databaseId, '/backups'),
      { waitUntil: 'domcontentloaded', timeout: 60_000 },
    )
    await expect(
      page
        .getByText(/Backup|Policy|Create backup|No backups|Snapshots/i)
        .first(),
    ).toBeVisible({ timeout: 60_000 })
  })
})
