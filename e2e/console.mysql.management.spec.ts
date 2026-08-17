import { type Page } from '@playwright/test'
import { test, expect } from './fixtures'
import { env } from './config/env'
import { E2E_VIEWPORT } from './config/viewport'
import { newE2ePage } from './helpers/cookie-banner'
import { enableMysqlFeatureFlags } from './helpers/feature-flags'
import { waitForFullscreenLoaderHidden } from './helpers/fullscreen-loader'
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

const DATABASE_TABS: Array<{
  name: string
  path: string
  ready: (page: Page) => ReturnType<Page['locator']>
}> = [
  {
    name: 'sql editor',
    path: '/sql',
    ready: (page) => page.locator('.monaco-editor').first(),
  },
  {
    name: 'visualizer',
    path: '/visualizer',
    ready: (page) =>
      page
        .getByText(/Select a schema in the sidebar|no user tables or views|Fit to view/i)
        .first(),
  },
  {
    name: 'enums',
    path: '/enums',
    ready: (page) =>
      page
        .getByText(/No enums|Create enum/i)
        .or(page.getByRole('button', { name: /Create enum/i }))
        .first(),
  },
  {
    name: 'monitor',
    path: '/monitor',
    ready: (page) =>
      page.getByRole('navigation', { name: 'Monitor metrics' }),
  },
  {
    name: 'backups',
    path: '/backups',
    ready: (page) =>
      page.getByText(/Backup|Policy|Create backup|No backups|Snapshots/i).first(),
  },
  {
    name: 'connections',
    path: '/connections',
    ready: (page) =>
      page.getByText(/Connect|Host|Connection|Clients|Backends/i).first(),
  },
  {
    name: 'roles',
    path: '/roles',
    ready: (page) =>
      page
        .getByRole('button', { name: /Create role/i })
        .or(page.getByText(/Create roles|No roles/i))
        .first(),
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
    name: 'compute',
    path: '/settings/compute',
    ready: (page) => page.getByRole('heading', { name: 'Compute tier' }),
  },
  {
    name: 'replication',
    path: '/settings/replication',
    ready: (page) => page.getByRole('heading', { name: 'Read replicas' }),
  },
  {
    name: 'network',
    path: '/settings/network',
    ready: (page) => page.getByRole('heading', { name: 'Network', exact: true }),
  },
  {
    name: 'pitr',
    path: '/settings/pitr',
    ready: (page) => page.getByRole('heading', { name: /Point-in-time recovery/ }),
  },
  {
    name: 'storage',
    path: '/settings/storage',
    ready: (page) => page.getByRole('heading', { name: 'Storage', exact: true }),
  },
  {
    name: 'maintenance',
    path: '/settings/maintenance',
    ready: (page) => page.getByRole('heading', { name: 'Maintenance window' }),
  },
]

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
      viewport: E2E_VIEWPORT,
      screen: E2E_VIEWPORT,
      recordVideo: {
        dir: 'test-results/mysql-videos/management-setup',
        size: E2E_VIEWPORT,
      },
    })
    const page = await newE2ePage(context)
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
      viewport: E2E_VIEWPORT,
      screen: E2E_VIEWPORT,
    })
    const page = await newE2ePage(context)
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
        { ready: () => tab.ready(page) },
      )
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
        { ready: () => section.ready(page) },
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
    await waitForFullscreenLoaderHidden(page, 120_000)

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
    await waitForFullscreenLoaderHidden(page, 60_000)

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

    await expect(page.getByText('Database name updated')).toBeVisible({
      timeout: 30_000,
    })
    await expect(input).toHaveValue(updatedName)

    await page.reload({ waitUntil: 'domcontentloaded' })
    await expect(page.locator('[data-fullscreen-loader]')).toHaveCount(0, {
      timeout: 120_000,
    })
    const nameCardAfterReload = page
      .locator('div.rounded-xl')
      .filter({
        has: page.getByRole('heading', { name: 'Name', exact: true }),
      })
      .first()
    await expect(nameCardAfterReload.locator('input').first()).toHaveValue(
      updatedName,
      { timeout: 60_000 },
    )
  })
})
