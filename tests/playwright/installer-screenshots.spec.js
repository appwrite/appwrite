// @ts-check
const { test, expect } = require('@playwright/test');

const isUpgradeRun = process.env.TEST_MODE === 'upgrade';

const setMockSettings = async (page, settings) => {
  await page.addInitScript((value) => {
    sessionStorage.setItem('appwrite-installer-mock-settings', JSON.stringify(value));
  }, settings);
};

const openAdvancedSettings = async (page) => {
  const toggle = page.locator('.accordion-toggle');
  if (await toggle.isVisible()) {
    await toggle.click();
  }
};

test.describe('@install Appwrite Web Installer - Screenshots', () => {
  test.skip(isUpgradeRun, 'Upgrade mode');
  test('capture screenshots of wizard steps', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    await page.waitForSelector('#hostname', { state: 'visible', timeout: 10000 });
    await page.waitForTimeout(300);

    await expect(page.locator('h1')).toContainText('Setup your app');
    await page.screenshot({ path: 'tests/playwright/screenshots/step-1-setup.png', fullPage: true });

    await page.locator('.accordion-toggle').click();
    await page.locator('#hostname').fill('appwrite.example.com');
    await page.locator('#http-port').fill('8080');
    await page.locator('#https-port').fill('8443');
    await page.locator('#ssl-email').fill('admin@example.com');
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('#secret-key', { state: 'visible', timeout: 10000 });
    await page.waitForTimeout(300);

    await page.screenshot({ path: 'tests/playwright/screenshots/step-2-secret-key.png', fullPage: true });

    await page.locator('#secret-key').fill('a'.repeat(32));
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('#account-name', { state: 'visible', timeout: 10000 });
    await page.waitForTimeout(300);
    await page.screenshot({ path: 'tests/playwright/screenshots/step-3-account.png', fullPage: true });

    await page.locator('#account-name').fill('Jane Doe');
    await page.locator('#account-email').fill('jane@example.com');
    await page.locator('#account-password').fill('password123');
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('[data-review-value="appDomain"]', { state: 'visible', timeout: 10000 });
    await page.waitForTimeout(300);
    await page.screenshot({ path: 'tests/playwright/screenshots/step-4-review.png', fullPage: true });

    await page.locator('button:has-text("Install")').click();
    await page.waitForSelector('.install-list', { state: 'visible', timeout: 10000 });
    await page.waitForTimeout(1000);
    await page.screenshot({ path: 'tests/playwright/screenshots/step-5-install.png', fullPage: true });

    await page.setViewportSize({ width: 375, height: 812 });
    await page.waitForTimeout(300);
    await page.screenshot({ path: 'tests/playwright/screenshots/mobile-view.png', fullPage: true });
  });

  test('capture validation states', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    await page.waitForSelector('#hostname', { state: 'visible', timeout: 10000 });

    await page.locator('#hostname').fill('');
    await page.locator('.accordion-toggle').click();
    await page.locator('#http-port').fill('0');
    await page.locator('#https-port').fill('70000');
    await page.locator('#ssl-email').fill('invalid');
    await page.locator('button:has-text("Next")').click();
    await page.waitForTimeout(300);

    await expect(page.locator('.field-error.is-visible').first()).toBeVisible();
    await page.screenshot({ path: 'tests/playwright/screenshots/validation-error.png', fullPage: true });
  });

  test('capture account validation states', async ({ page }) => {
    await page.goto('/?step=3');
    await page.waitForLoadState('networkidle');
    await page.waitForSelector('#account-name', { state: 'visible', timeout: 10000 });

    await page.locator('#account-name').fill('');
    await page.locator('#account-email').fill('invalid-email');
    await page.locator('#account-password').fill('short');
    await page.locator('button:has-text("Next")').click();
    await page.waitForTimeout(500);

    await expect(page.locator('.field-error.is-visible').first()).toBeVisible();
    await page.screenshot({ path: 'tests/playwright/screenshots/account-validation-error.png', fullPage: true });
  });

  test('capture install error state (mock)', async ({ page }) => {
    await setMockSettings(page, { error: true });
    await page.goto('/?step=4');
    await page.waitForLoadState('networkidle');

    await page.waitForSelector('button:has-text("Install")', { state: 'visible', timeout: 10000 });
    await page.locator('button:has-text("Install")').click();
    await page.waitForSelector('.install-row[data-status="error"]', { timeout: 15000 });
    await page.screenshot({ path: 'tests/playwright/screenshots/install-error.png', fullPage: true });
  });

  test('capture toast state (mock)', async ({ page }) => {
    await setMockSettings(page, { toast: true });
    await page.goto('/?step=4');
    await page.waitForLoadState('networkidle');
    await page.waitForSelector('button:has-text("Install")', { state: 'visible', timeout: 10000 });
    await page.locator('button:has-text("Install")').click();
    await page.waitForSelector('.installer-toast', { state: 'visible', timeout: 10000 });
    await page.waitForSelector('.installer-toast:not(.is-entering)', { state: 'visible', timeout: 10000 });
    await page.waitForTimeout(450);
    await page.screenshot({ path: 'tests/playwright/screenshots/toast-session-expired.png', fullPage: true });
  });
});

test.describe('@upgrade Appwrite Web Installer - Screenshots', () => {
  test.skip(!isUpgradeRun, 'Install mode');
  test('capture upgrade flow screenshots (mock upgrade)', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toHaveAttribute('data-upgrade', 'true');

    await expect(page.locator('h1')).toContainText(/Update your app/i);
    await page.screenshot({ path: 'tests/playwright/screenshots/upgrade-step-1-setup.png', fullPage: true });

    // Upgrade: advanced settings open
    await openAdvancedSettings(page);
    await page.waitForTimeout(200);
    await page.screenshot({ path: 'tests/playwright/screenshots/upgrade-step-1-advanced.png', fullPage: true });

    // Upgrade: locked database tooltip
    const dbTooltipTrigger = page.locator('.selector-card.has-tooltip').first();
    if (await dbTooltipTrigger.isVisible()) {
      await dbTooltipTrigger.hover();
      await page.waitForSelector('.tooltip-db-locked', { state: 'visible', timeout: 5000 });
      await page.waitForTimeout(200);
      await page.screenshot({ path: 'tests/playwright/screenshots/upgrade-step-1-db-tooltip.png', fullPage: true });
    }

    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('[data-review-value="appDomain"]', { state: 'visible', timeout: 10000 });
    await page.screenshot({ path: 'tests/playwright/screenshots/upgrade-step-4-review.png', fullPage: true });

    await page.locator('button:has-text("Update")').click();
    await page.waitForSelector('.install-row[data-step="docker-containers"][data-status="completed"]', { timeout: 15000 });
    await page.screenshot({ path: 'tests/playwright/screenshots/upgrade-step-5-install.png', fullPage: true });
  });
});
