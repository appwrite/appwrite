import { expect, test } from '../fixtures/authenticated'

test('account page renders navigation and logout', async ({ page }) => {
  await page.goto('/account', { waitUntil: 'domcontentloaded' })

  // Should stay within account area.
  await expect(page).toHaveURL((url) => new URL(url).pathname === '/account')

  // The settings sidebar is the stable, public navigation for this page.
  const nav = page.getByRole('navigation', { name: 'Settings navigation' })
  await expect(nav).toBeVisible()

  await expect(nav.getByRole('link', { name: 'General' })).toBeVisible()
  await expect(nav.getByRole('link', { name: 'Security' })).toBeVisible()
  await expect(nav.getByRole('link', { name: 'Sessions' })).toBeVisible()

  // Logout button is always present in the account header.
  await expect(page.getByRole('button', { name: 'Logout' })).toBeVisible()
})
