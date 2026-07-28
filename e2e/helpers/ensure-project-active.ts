import { expect, type Page } from '@playwright/test'

/**
 * Free-plan projects can be paused for inactivity. Console project smoke tests
 * need an active project, so restore once up front when the paused curtain is shown.
 *
 * Resume requires a signed console fingerprint (`VITE_CONSOLE_FINGERPRINT_KEY`
 * baked into the app at build time).
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

  if (!process.env.VITE_CONSOLE_FINGERPRINT_KEY?.trim()) {
    throw new Error(
      'Project is paused but VITE_CONSOLE_FINGERPRINT_KEY is not set. ' +
        'Restoring a paused project requires a signed console fingerprint; ' +
        'add the secret to CI so the Playwright webServer build can embed it.',
    )
  }

  // Do not treat the button becoming "hidden" as success: while pending the
  // label flips to "Resuming…", which no longer matches "Restore project".
  const statusResponsePromise = page.waitForResponse(
    (response) => {
      try {
        const pathname = new URL(response.url()).pathname
        return (
          pathname.endsWith(`/projects/${projectId}/status`) &&
          response.request().method() === 'PATCH'
        )
      } catch {
        return false
      }
    },
    { timeout: 90_000 },
  )

  await expect(pausedHeading).toBeVisible()
  await restoreButton.click()

  const statusResponse = await statusResponsePromise
  if (!statusResponse.ok()) {
    const body = await statusResponse.text().catch(() => '')
    const uiError = (
      await page
        .locator('.text-destructive')
        .first()
        .textContent({ timeout: 5_000 })
        .catch(() => null)
    )?.trim()

    throw new Error(
      `Failed to resume paused project ${projectId}: HTTP ${statusResponse.status()} ${body}` +
        (uiError ? ` (UI: ${uiError})` : ''),
    )
  }

  await expect(pausedHeading).toHaveCount(0, { timeout: 60_000 })
  await expect(page).toHaveURL(new RegExp(`/projects/${projectId}(?:/|$|\\?)`), {
    timeout: 30_000,
  })
}
