import { expect, type Page } from '@playwright/test'
import { env } from '../config/env'
import { enableDatabaseFeatureFlags } from './feature-flags'
import { acceptCookieBannerIfPresent } from './cookie-banner'

export const MYSQL_E2E_PROJECT_REGION = 'fra'

export type CreatedProject = {
  projectId: string
  projectName: string
}

function uniqueSuffix(): string {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
}

/**
 * Create a disposable project in E2E_ORG_ID (Frankfurt) for database e2e.
 * Returns the new project id and display name.
 *
 * Reuse this project across a suite (tables, columns, indexes, tabs). Do not
 * create a new project per test: dedicated compute keys take minutes to
 * provision, and deleting a project forces the next one to wait again.
 */
export async function createE2eProject(
  page: Page,
  options?: { namePrefix?: string },
): Promise<CreatedProject> {
  const orgId = env.E2E_ORG_ID
  if (!orgId) {
    throw new Error('E2E_ORG_ID is required for database e2e suites')
  }

  await enableDatabaseFeatureFlags(page)

  const projectName = `${options?.namePrefix ?? 'e2e-db'}-${uniqueSuffix()}`.slice(
    0,
    128,
  )

  await page.goto(`/organizations/${orgId}`, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  })
  await enableDatabaseFeatureFlags(page)
  await acceptCookieBannerIfPresent(page)
  await expect(page).toHaveURL(new RegExp(`/organizations/${orgId}`), {
    timeout: 60_000,
  })

  // Prefer the primary create control; fall back to empty-state CTA.
  const createTriggers = page.getByRole('button', { name: 'Create project' })
  await expect(createTriggers.first()).toBeVisible({ timeout: 60_000 })
  await createTriggers.first().click()

  const dialog = page.getByRole('dialog')
  await expect(dialog.getByRole('heading', { name: 'Create project' })).toBeVisible({
    timeout: 30_000,
  })

  await dialog.locator('#name').fill(projectName)

  // Region picker (cloud multi-region). Prefer Frankfurt for dedicated DB support.
  const regionTrigger = dialog.getByRole('combobox').first()
  if (await regionTrigger.isVisible().catch(() => false)) {
    await regionTrigger.click()
    const fraOption = page.getByRole('option', {
      name: /Frankfurt|fra/i,
    })
    if (await fraOption.first().isVisible().catch(() => false)) {
      await fraOption.first().click()
    } else {
      // Close list and keep whatever default was selected if FRA is unavailable.
      await page.keyboard.press('Escape')
    }
  }

  const createResponsePromise = page.waitForResponse(
    (response) => {
      try {
        const url = new URL(response.url())
        return (
          response.request().method() === 'POST' &&
          /\/(?:v1\/)?(?:organizations?|organization)\/.*projects/i.test(
            url.pathname,
          ) &&
          response.ok()
        )
      } catch {
        return false
      }
    },
    { timeout: 90_000 },
  )

  await dialog.getByRole('button', { name: 'Create', exact: true }).click()

  const createResponse = await createResponsePromise
  const body = (await createResponse.json()) as { $id?: string }
  const projectId = body.$id
  if (!projectId) {
    throw new Error(
      `Project create response missing $id: ${JSON.stringify(body).slice(0, 500)}`,
    )
  }

  await expect(page).toHaveURL(new RegExp(`/projects/${projectId}`), {
    timeout: 90_000,
  })

  return { projectId, projectName }
}

/**
 * Permanently delete a project from project settings (danger zone).
 * Safe to call when the project may already be gone.
 */
export async function deleteE2eProject(
  page: Page,
  project: CreatedProject,
): Promise<void> {
  await enableDatabaseFeatureFlags(page)

  await page.goto(`/projects/${project.projectId}/settings`, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  })
  await acceptCookieBannerIfPresent(page)

  // Already deleted / not found - treat as success for cleanup.
  const notFound = page.getByText(/not found|doesn't exist|does not exist/i)
  if (await notFound.first().isVisible().catch(() => false)) {
    return
  }

  const deleteCard = page.locator('[data-card-id="delete-project"]')
  if (!(await deleteCard.isVisible({ timeout: 10_000 }).catch(() => false))) {
    return
  }

  await deleteCard.getByRole('button', { name: 'Delete project' }).click()

  const dialog = page.getByRole('dialog')
  await expect(
    dialog.getByRole('heading', { name: /Delete [Pp]roject/ }),
  ).toBeVisible({ timeout: 15_000 })

  await dialog.getByPlaceholder('Enter project name').fill(project.projectName)

  const deleteResponsePromise = page.waitForResponse(
    (response) => {
      try {
        const url = new URL(response.url())
        const method = response.request().method()
        return (
          (method === 'DELETE' || method === 'POST') &&
          url.pathname.includes(project.projectId) &&
          response.status() < 500
        )
      } catch {
        return false
      }
    },
    { timeout: 120_000 },
  )

  await dialog.getByRole('button', { name: 'Delete', exact: true }).click()
  await deleteResponsePromise.catch(() => undefined)

  // Best-effort: leave settings / land on org after delete.
  await page
    .waitForURL(
      (url) => !url.pathname.includes(`/projects/${project.projectId}`),
      { timeout: 60_000 },
    )
    .catch(() => undefined)
}
