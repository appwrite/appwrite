// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Appwrite Web Installer', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('loads installer wizard shell', async ({ page }) => {
    await expect(page).toHaveTitle(/Appwrite Installation/);
    await expect(page.locator('h1')).toContainText('Appwrite Installation');
    await expect(page.locator('#wizardStepLabel')).toHaveText(/Step 1/i);
  });

  test('shows configuration fields by default', async ({ page }) => {
    await expect(page.locator('.step[data-step="1"]')).toHaveClass(/active/);
    await expect(page.locator('#httpPort')).toHaveValue('80');
    await expect(page.locator('#httpsPort')).toHaveValue('443');
  });

  test('validates port numbers on step 1', async ({ page }) => {
    await page.locator('#httpPort').fill('invalid');
    await page.locator('#primaryButton').click();
    await expect(page.locator('.alert.is-error')).toBeVisible();
    await expect(page.locator('.step[data-step="1"]')).toHaveClass(/active/);
  });

  test('advances to database step with valid ports', async ({ page }) => {
    await page.locator('#httpPort').fill('8080');
    await page.locator('#httpsPort').fill('8443');
    await page.locator('#primaryButton').click();

    await expect(page.locator('.step[data-step="2"]')).toHaveClass(/active/);
    await expect(page.locator('.database-option')).toHaveCount(2);
    await expect(page.locator('#wizardStepLabel')).toHaveText(/Step 2/i);
  });

  test('moves to domain step after database selection', async ({ page }) => {
    await page.locator('#primaryButton').click();
    await page.locator('#primaryButton').click();

    await expect(page.locator('.step[data-step="3"]')).toHaveClass(/active/);
    await expect(page.locator('#appDomain')).toBeVisible();
    await expect(page.locator('#emailCertificates')).toBeVisible();
    await expect(page.locator('#wizardStepLabel')).toHaveText(/Step 3/i);
  });

  test('validates domain fields', async ({ page }) => {
    await page.locator('#primaryButton').click();
    await page.locator('#primaryButton').click();

    await page.locator('#appDomain').fill('');
    await page.locator('#primaryButton').click();
    await expect(page.locator('.alert.is-error')).toContainText(/hostname/i);

    await page.locator('#appDomain').fill('appwrite.example.com');
    await page.locator('#emailCertificates').fill('invalid');
    await page.locator('#primaryButton').click();
    await expect(page.locator('.alert.is-error')).toContainText(/valid email/i);
  });

  test('moves to security step with valid domain data', async ({ page }) => {
    await page.locator('#primaryButton').click();
    await page.locator('#primaryButton').click();
    await page.locator('#appDomain').fill('appwrite.example.com');
    await page.locator('#emailCertificates').fill('admin@example.com');
    await page.locator('#primaryButton').click();

    await expect(page.locator('.step[data-step="4"]')).toHaveClass(/active/);
    const keyValue = await page.locator('#opensslKey').inputValue();
    expect(keyValue.length).toBeGreaterThan(32);
    await expect(page.locator('#wizardStepLabel')).toHaveText(/Step 4/i);
  });

  test('shows review step before installation', async ({ page }) => {
    await page.locator('#primaryButton').click();
    await page.locator('#primaryButton').click();
    await page.locator('#appDomain').fill('appwrite.example.com');
    await page.locator('#emailCertificates').fill('admin@example.com');
    await page.locator('#primaryButton').click();
    await page.locator('button:has-text("Review")').click();

    await expect(page.locator('.step[data-step="5"]')).toHaveClass(/active/);
    await expect(page.locator('#reviewContent')).toContainText('appwrite.example.com');
    await expect(page.locator('#reviewContent')).toContainText('admin@example.com');
    await expect(page.locator('#wizardStepLabel')).toHaveText(/Step 5/i);
  });

  test('allows navigating backwards', async ({ page }) => {
    await page.locator('#primaryButton').click();
    await expect(page.locator('.step[data-step="2"]')).toHaveClass(/active/);
    await page.locator('#backButton').click();
    await expect(page.locator('.step[data-step="1"]')).toHaveClass(/active/);
  });

  test('prepares primary action before installation', async ({ page }) => {
    await page.locator('#httpPort').fill('8080');
    await page.locator('#httpsPort').fill('8443');
    await page.locator('#primaryButton').click();
    await page.locator('#primaryButton').click();
    await page.locator('#appDomain').fill('appwrite.local');
    await page.locator('#emailCertificates').fill('admin@appwrite.local');
    await page.locator('#primaryButton').click();
    await page.locator('button:has-text("Review")').click();

    await expect(page.locator('#reviewContent')).toContainText('8080');
    await expect(page.locator('#reviewContent')).toContainText('appwrite.local');
    await expect(page.locator('button:has-text("Install Appwrite")')).toBeVisible();
  });

  test('keeps installation log hidden until triggered', async ({ page }) => {
    await page.locator('#primaryButton').click();
    await page.locator('#primaryButton').click();
    await page.locator('#appDomain').fill('appwrite.test');
    await page.locator('#emailCertificates').fill('test@appwrite.test');
    await page.locator('#primaryButton').click();
    await page.locator('button:has-text("Review")').click();

    await expect(page.locator('#installationLog')).toBeHidden();
  });

  test('loads Pink design tokens', async ({ page }) => {
    const stylesheets = await page.evaluate(() => Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(link => link.getAttribute('href')));
    expect(stylesheets.some(href => href && href.includes('pink'))).toBe(true);
  });
});

test.describe('Installer API', () => {
  test.skip('API tests require the install task to be running', () => {
    // Intentionally skipped pending API harness for installer.
  });
});
