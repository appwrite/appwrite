import { expect, test } from '../fixtures/authenticated'

test('authenticated session is restored', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' })

  // The storage state keeps the user signed in, so this should not redirect to sign-in.
  await expect(page).not.toHaveURL(/\/sign-in/)
})
