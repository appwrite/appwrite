import { expect, test } from '../fixtures/authenticated'

test('account page renders tabs and logout', async ({ page }) => {
  await page.goto('/account', { waitUntil: 'domcontentloaded' })

  // Should stay within account area.
  await expect(page).toHaveURL((url) => new URL(url).pathname === '/account')

  // Tabs are the stable, public UI for this page.
  const tablist = page.getByRole('tablist')
  await expect(tablist).toBeVisible()

  await expect(page.getByRole('tab', { name: 'Overview' })).toBeVisible()
  await expect(page.getByRole('tab', { name: 'Sessions' })).toBeVisible()
  await expect(page.getByRole('tab', { name: 'Payments' })).toBeVisible()

  // Logout button is always present in the account header.
  await expect(page.getByRole('button', { name: 'Logout' })).toBeVisible()
})
