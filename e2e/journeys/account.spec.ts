import { expect, test } from '@playwright/test'

test('account page renders tabs and logout', async ({ page }) => {
  await page.goto('/account', { waitUntil: 'domcontentloaded' })

  // Should stay within account area and not bounce to sign-in.
  await expect(page).toHaveURL(/\/account/)
  await expect(page).not.toHaveURL(/\/sign-in/)

  // Tabs are the stable, public UI for this page.
  const tablist = page.getByRole('tablist')
  await expect(tablist).toBeVisible()

  await expect(page.getByRole('tab', { name: 'Overview' })).toBeVisible()
  await expect(page.getByRole('tab', { name: 'Sessions' })).toBeVisible()
  await expect(page.getByRole('tab', { name: 'Payments' })).toBeVisible()

  // Logout button is always present in the account header.
  await expect(page.getByRole('button', { name: 'Logout' })).toBeVisible()
})
