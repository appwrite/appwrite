import { expect, test } from '@playwright/test';

test.use({ storageState: 'e2e/.auth/auth.json' });

test('authenticated session is restored', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // The storage state keeps the user signed in, so this should not redirect to sign-in.
    await expect(page).not.toHaveURL(/\/sign-in/);
});
