// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Appwrite Web Installer - Screenshots', () => {
  test('capture screenshots of wizard steps', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);

    await expect(page.locator('h1')).toContainText('Appwrite Installation');
    await page.screenshot({ path: 'tests/playwright/screenshots/step-1-configuration.png', fullPage: true });

    await page.locator('#httpPort').fill('8080');
    await page.locator('#httpsPort').fill('8443');
    await page.locator('#primaryButton').click();
    await page.waitForTimeout(300);

    await expect(page.locator('.step[data-step="2"]')).toHaveClass(/active/);
    await page.screenshot({ path: 'tests/playwright/screenshots/step-2-database.png', fullPage: true });

    await page.locator('#primaryButton').click();
    await page.waitForTimeout(300);
    await page.screenshot({ path: 'tests/playwright/screenshots/step-3-domain.png', fullPage: true });

    await page.locator('#appDomain').fill('appwrite.example.com');
    await page.locator('#emailCertificates').fill('admin@example.com');
    await page.locator('#primaryButton').click();
    await page.waitForTimeout(300);
    await page.screenshot({ path: 'tests/playwright/screenshots/step-4-security.png', fullPage: true });

    await page.locator('button:has-text("Review")').click();
    await page.waitForTimeout(300);
    await page.screenshot({ path: 'tests/playwright/screenshots/step-5-review.png', fullPage: true });
    await page.locator('#reviewContent').screenshot({ path: 'tests/playwright/screenshots/step-5-review-detail.png' });

    await page.setViewportSize({ width: 375, height: 812 });
    await page.waitForTimeout(300);
    await page.screenshot({ path: 'tests/playwright/screenshots/mobile-view.png', fullPage: true });
  });

  test('capture validation states', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    await page.locator('#httpPort').fill('invalid');
    await page.locator('#primaryButton').click();
    await page.waitForTimeout(300);

    await expect(page.locator('.alert.is-error')).toBeVisible();
    await page.screenshot({ path: 'tests/playwright/screenshots/validation-error.png', fullPage: true });
  });
});
