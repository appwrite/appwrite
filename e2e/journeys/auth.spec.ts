import { test, expect } from '@playwright/test';
import { signInStep } from '../steps/account';

test.describe('auth pages', () => {
    test('sign-in page loads', async ({ page }) => {
        await page.goto('/sign-in', { waitUntil: 'domcontentloaded' });
        await page.waitForURL(/\/sign-in/);
        // Wait for hydration/rendering
        await page.getByLabel('Email').waitFor({ state: 'visible' });
        await expect(page.getByLabel('Email')).toBeVisible();
        await expect(page.getByLabel('Password')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Login', exact: true })).toBeVisible();
    });

    test('sign-in flow', async ({ page }) => {
        const email = process.env.E2E_TEST_EMAIL;
        const password = process.env.E2E_TEST_PASSWORD;

        if (!email || !password) {
            test.skip(true, 'E2E_TEST_EMAIL and E2E_TEST_PASSWORD must be set');
            return;
        }

        // Test: Perform Sign In
        // signInStep now handles the post-login wait
        await signInStep(page, email, password);
    });
});
