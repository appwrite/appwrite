import { expect, type Page } from '@playwright/test'

/**
 * Free-plan projects can be paused for inactivity. Console smoke tests need an
 * active project, so restore once up front when the paused curtain is shown.
 */
export async function ensureProjectActive(
  page: Page,
  projectId: string,
): Promise<void> {
  await page.goto(`/projects/${projectId}`, {
    waitUntil: 'domcontentloaded',
    timeout: 45_000,
  })

  const pausedHeading = page.getByRole('heading', { name: 'Project paused' })
  const restoreButton = page.getByRole('button', { name: 'Restore project' })

  const isPaused = await restoreButton
    .waitFor({ state: 'visible', timeout: 10_000 })
    .then(() => true)
    .catch(() => false)

  if (!isPaused) {
    return
  }

  await expect(pausedHeading).toBeVisible()
  await restoreButton.click()

  // Button label flips to "Resuming…" while the mutation runs, then the curtain
  // unmounts after a successful resume and the layout navigates to overview.
  await expect(restoreButton).toBeHidden({ timeout: 90_000 })
  await expect(pausedHeading).toHaveCount(0, { timeout: 30_000 })
  await expect(page).toHaveURL(new RegExp(`/projects/${projectId}(?:/|$|\\?)`), {
    timeout: 30_000,
  })
}
