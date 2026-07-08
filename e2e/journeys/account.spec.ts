import { expect, test } from '../fixtures/authenticated'

test('account page renders navigation and logout', async ({ page }) => {
  await page.route('**/v1/account', async (route) => {
    if (route.request().method() !== 'GET') {
      await route.continue()
      return
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        $id: 'e2e-account',
        $createdAt: '2026-01-01T00:00:00.000+00:00',
        $updatedAt: '2026-01-01T00:00:00.000+00:00',
        accessedAt: '2026-01-01T00:00:00.000+00:00',
        name: 'E2E Account',
        email: 'e2e@example.com',
        emailVerification: true,
        phone: '',
        phoneVerification: false,
        prefs: {},
        registration: '2026-01-01T00:00:00.000+00:00',
        status: true,
        labels: [],
        passwordUpdate: '2026-01-01T00:00:00.000+00:00',
        mfa: false,
        targets: [],
        identities: [],
      }),
    })
  })

  await page.goto('/account', { waitUntil: 'domcontentloaded' })

  // Should stay within account area.
  await expect(page).toHaveURL((url) => new URL(url).pathname === '/account')

  // Wait for the authenticated account shell to finish rendering.
  await expect(page.getByTestId('account-logout')).toBeVisible({
    timeout: 30000,
  })

  // The settings navigation is the stable account-page navigation.
  const nav = page.locator('[data-testid="settings-navigation"]:visible')
  await expect(nav).toBeVisible()

  await expect(
    nav.getByRole('link').first().or(nav.getByRole('combobox')).first(),
  ).toBeVisible()
})
