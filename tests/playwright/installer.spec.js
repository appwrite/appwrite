// @ts-check
const { test, expect } = require('@playwright/test');

const isUpgradeRun = process.env.TEST_MODE === 'upgrade';

const openAdvancedSettings = async (page) => {
  const toggle = page.locator('.accordion-toggle');
  const expanded = await toggle.getAttribute('aria-expanded');
  if (expanded !== 'true') {
    await toggle.click();
  }
};

const fillValidSetup = async (page, overrides = {}) => {
  const {
    hostname = 'appwrite.example.com',
    httpPort = '8080',
    httpsPort = '8443',
    email = 'admin@example.com',
    assistantKey = ''
  } = overrides;

  await page.locator('#hostname').fill(hostname);
  await openAdvancedSettings(page);
  await page.locator('#http-port').fill(httpPort);
  await page.locator('#https-port').fill(httpsPort);
  await page.locator('#ssl-email').fill(email);
  if (assistantKey) {
    await page.locator('#assistant-openai-key').fill(assistantKey);
  }
};

test.describe('@install Appwrite Web Installer - Page Load', () => {
  test.skip(isUpgradeRun, 'Upgrade mode');
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('loads installer with correct title', async ({ page }) => {
    await expect(page).toHaveTitle(/Appwrite Installation/);
  });

  test('shows step 1 by default', async ({ page }) => {
    await expect(page.locator('.installer-card[data-step="1"]')).toBeVisible();
    await expect(page.locator('h1')).toContainText(/Setup your app/i);
  });

  test('loads with correct body data attributes', async ({ page }) => {
    const body = page.locator('body');
    await expect(body).toHaveAttribute('data-step', '1');
    await expect(body).toHaveAttribute('data-install-mode');
  });
});

test.describe('@install Appwrite Web Installer - Step 1: Setup', () => {
  test.skip(isUpgradeRun, 'Upgrade mode');
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('shows hostname input with default value', async ({ page }) => {
    const hostname = page.locator('#hostname');
    await expect(hostname).toBeVisible();
    await expect(hostname).toHaveValue(/localhost|.+/);
  });

  test('shows database selector with MongoDB and MariaDB options', async ({ page }) => {
    await expect(page.locator('input[name="database"][value="mongodb"]')).toBeAttached();
    await expect(page.locator('input[name="database"][value="mariadb"]')).toBeAttached();
    await expect(page.locator('text=MongoDB')).toBeVisible();
    await expect(page.locator('text=MariaDB')).toBeVisible();
  });

  test('allows selecting database', async ({ page }) => {
    const mongodb = page.locator('input[name="database"][value="mongodb"]');
    const mariadb = page.locator('input[name="database"][value="mariadb"]');

    await page.locator('text=MariaDB').click();
    await expect(mariadb).toBeChecked();

    await page.locator('text=MongoDB').click();
    await expect(mongodb).toBeChecked();
  });

  test('shows advanced settings accordion collapsed by default', async ({ page }) => {
    const accordion = page.locator('.accordion-toggle');
    await expect(accordion).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('.accordion-content')).not.toBeVisible();
  });

  test('expands advanced settings on click', async ({ page }) => {
    const accordion = page.locator('.accordion-toggle');
    await accordion.click();
    await expect(accordion).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('.accordion-content')).toBeVisible();
  });

  test('shows port fields in advanced settings', async ({ page }) => {
    await page.locator('.accordion-toggle').click();
    await expect(page.locator('#http-port')).toBeVisible();
    await expect(page.locator('#http-port')).toHaveValue('80');
    await expect(page.locator('#https-port')).toBeVisible();
    await expect(page.locator('#https-port')).toHaveValue('443');
  });

  test('shows SSL email field in advanced settings', async ({ page }) => {
    await page.locator('.accordion-toggle').click();
    await expect(page.locator('#ssl-email')).toBeVisible();
  });

  test('shows OpenAI key field marked as optional', async ({ page }) => {
    await page.locator('.accordion-toggle').click();
    await expect(page.locator('#assistant-openai-key')).toBeVisible();
    await expect(page.locator('text=optional')).toBeVisible();
  });

  test('validates hostname - rejects empty', async ({ page }) => {
    await fillValidSetup(page);
    await page.locator('#hostname').fill('');
    await page.locator('button:has-text("Next")').click();
    await expect(page.locator('.installer-card[data-step="1"]')).toBeVisible();
  });

  test('validates hostname - accepts valid domain', async ({ page }) => {
    await fillValidSetup(page, { hostname: 'appwrite.example.com' });
    await page.locator('button:has-text("Next")').click();
    await expect(page.locator('.installer-card[data-step="2"]')).toBeVisible();
  });

  test('validates hostname - accepts localhost', async ({ page }) => {
    await fillValidSetup(page, { hostname: 'localhost' });
    await page.locator('button:has-text("Next")').click();
    await expect(page.locator('.installer-card[data-step="2"]')).toBeVisible();
  });

  test('validates hostname - accepts IP address', async ({ page }) => {
    await fillValidSetup(page, { hostname: '192.168.1.1' });
    await page.locator('button:has-text("Next")').click();
    await expect(page.locator('.installer-card[data-step="2"]')).toBeVisible();
  });

  test('validates port numbers - rejects invalid HTTP port', async ({ page }) => {
    await fillValidSetup(page, { httpPort: '0' });
    await page.locator('button:has-text("Next")').click();
    await expect(page.locator('.installer-card[data-step="1"]')).toBeVisible();
  });

  test('validates port numbers - rejects out of range port', async ({ page }) => {
    await fillValidSetup(page, { httpPort: '99999' });
    await page.locator('button:has-text("Next")').click();
    await expect(page.locator('.installer-card[data-step="1"]')).toBeVisible();
  });

  test('validates port numbers - accepts valid custom ports', async ({ page }) => {
    await fillValidSetup(page);
    await page.locator('button:has-text("Next")').click();
    await expect(page.locator('.installer-card[data-step="2"]')).toBeVisible();
  });

  test('validates SSL email - rejects invalid email', async ({ page }) => {
    await fillValidSetup(page, { email: 'invalid-email' });
    await page.locator('button:has-text("Next")').click();
    await expect(page.locator('.installer-card[data-step="1"]')).toBeVisible();
  });

  test('validates SSL email - accepts valid email', async ({ page }) => {
    await fillValidSetup(page, { email: 'admin@example.com' });
    await page.locator('button:has-text("Next")').click();
    await expect(page.locator('.installer-card[data-step="2"]')).toBeVisible();
  });

  test('reset button enables when field is modified', async ({ page }) => {
    await page.locator('.accordion-toggle').click();
    const resetButton = page.locator('[data-reset-target="http-port"]');

    await expect(resetButton).toBeDisabled();
    await page.locator('#http-port').fill('8080');
    await expect(resetButton).toBeEnabled();
  });

  test('reset button restores default value', async ({ page }) => {
    await page.locator('.accordion-toggle').click();
    const httpPort = page.locator('#http-port');
    const resetButton = page.locator('[data-reset-target="http-port"]');

    await httpPort.fill('8080');
    await resetButton.click();
    await expect(httpPort).toHaveValue('80');
    await expect(resetButton).toBeDisabled();
  });
});

test.describe('@install Appwrite Web Installer - Step 2: Secret Key', () => {
  test.skip(isUpgradeRun, 'Upgrade mode');
  test.beforeEach(async ({ page }) => {
    await page.goto('/?step=2');
    await page.waitForLoadState('networkidle');
  });

  test('shows secret key step', async ({ page }) => {
    await expect(page.locator('.installer-card[data-step="2"]')).toBeVisible();
    await expect(page.locator('h1')).toContainText(/Secure your app/i);
  });

  test('shows secret key input with generated value', async ({ page }) => {
    const secretKey = page.locator('#secret-key');
    await expect(secretKey).toBeVisible();
    const value = await secretKey.inputValue();
    expect(value.length).toBeGreaterThanOrEqual(32);
  });

  test('shows warning alert about saving key', async ({ page }) => {
    await expect(page.locator('.inline-alert--warning')).toBeVisible();
    await expect(page.locator('.inline-alert-title')).toContainText('Save your key');
    await expect(page.locator('.inline-alert-description')).toContainText(/won't be able to see/i);
  });

  test('copy button exists', async ({ page }) => {
    await expect(page.locator('[data-copy-target="secret-key"]')).toBeVisible();
  });

  test('regenerate button exists and is functional', async ({ page }) => {
    const secretKey = page.locator('#secret-key');
    const regenerateButton = page.locator('[data-regenerate-target="secret-key"]');

    const initialValue = await secretKey.inputValue();
    await regenerateButton.click();
    await page.waitForTimeout(100);
    const newValue = await secretKey.inputValue();

    expect(newValue).not.toBe(initialValue);
    expect(newValue.length).toBeGreaterThanOrEqual(32);
  });

  test('validates secret key - rejects empty', async ({ page }) => {
    await page.goto('/?step=2');
    await page.waitForLoadState('networkidle');

    await page.locator('#secret-key').fill('');
    await page.locator('button:has-text("Next")').click();
    await page.waitForTimeout(500);
    await expect(page.locator('body')).toHaveAttribute('data-step', '2');
  });

  test('enforces secret key max length at 64 chars', async ({ page }) => {
    await page.goto('/?step=2');
    await page.waitForLoadState('networkidle');

    const tooLongKey = 'a'.repeat(65);
    const secretKey = page.locator('#secret-key');
    await secretKey.fill(tooLongKey);
    await expect(secretKey).toHaveAttribute('maxlength', '64');
    const value = await secretKey.inputValue();
    expect(value.length).toBe(64);
  });

  test('validates secret key - accepts minimum length', async ({ page }) => {
    await page.goto('/?step=2');
    await page.waitForLoadState('networkidle');

    const validKey = 'a'.repeat(1);
    await page.locator('#secret-key').fill(validKey);
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('#account-name', { state: 'visible', timeout: 10000 });
    await expect(page.locator('#account-name')).toBeVisible();
  });

  test('validates secret key - accepts default value', async ({ page }) => {
    await page.goto('/?step=2');
    await page.waitForLoadState('networkidle');

    await page.locator('#secret-key').fill('your-secret-key');
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('#account-name', { state: 'visible', timeout: 10000 });
    await expect(page.locator('#account-name')).toBeVisible();
  });

  test('validates secret key - accepts maximum length', async ({ page }) => {
    await page.goto('/?step=2');
    await page.waitForLoadState('networkidle');

    const validKey = 'a'.repeat(64);
    await page.locator('#secret-key').fill(validKey);
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('#account-name', { state: 'visible', timeout: 10000 });
    await expect(page.locator('#account-name')).toBeVisible();
  });

  test('allows navigating back to step 1', async ({ page }) => {
    await page.locator('button:has-text("Back")').click();
    await page.waitForSelector('.installer-card[data-step="1"]', { state: 'visible', timeout: 5000 });
    await expect(page.locator('.installer-card[data-step="1"]')).toBeVisible();
  });
});

test.describe('@install Appwrite Web Installer - Step 3: Account Creation', () => {
  test.skip(isUpgradeRun, 'Upgrade mode');
  test.beforeEach(async ({ page }) => {
    await page.goto('/?step=3');
    await page.waitForLoadState('networkidle');
  });

  test('shows account creation step', async ({ page }) => {
    await expect(page.locator('#account-name')).toBeVisible();
    await expect(page.locator('h1')).toContainText(/Create your account/i);
  });

  test('shows name, email, and password fields', async ({ page }) => {
    await expect(page.locator('#account-name')).toBeVisible();
    await expect(page.locator('#account-email')).toBeVisible();
    await expect(page.locator('#account-password')).toBeVisible();
  });

  test('password field is type password by default', async ({ page }) => {
    await expect(page.locator('#account-password')).toHaveAttribute('type', 'password');
  });

  test('password toggle button exists', async ({ page }) => {
    await expect(page.locator('[data-password-toggle="account-password"]')).toBeVisible();
  });

  test('password toggle shows/hides password', async ({ page }) => {
    const result = await page.evaluate(() => {
      const input = document.querySelector('#account-password');
      const button = document.querySelector('[data-password-toggle="account-password"]');
      const first = input?.getAttribute('type') || '';
      button?.click();
      const second = input?.getAttribute('type') || '';
      button?.click();
      const third = input?.getAttribute('type') || '';
      return { first, second, third };
    });

    expect(result.first).toBe('password');
    expect(result.second).toBe('text');
    expect(result.third).toBe('password');
  });

  test('validates name - rejects empty', async ({ page }) => {
    await page.goto('/?step=3');
    await page.waitForLoadState('networkidle');

    await page.locator('#account-name').fill('');
    await page.locator('#account-email').fill('test@example.com');
    await page.locator('#account-password').fill('password123');
    await page.locator('button:has-text("Next")').click();
    await page.waitForTimeout(500);
    await expect(page.locator('#account-name')).toBeVisible();
  });

  test('validates email - rejects invalid email', async ({ page }) => {
    await page.goto('/?step=3');
    await page.waitForLoadState('networkidle');

    await page.locator('#account-name').fill('Test User');
    await page.locator('#account-email').fill('invalid-email');
    await page.locator('#account-password').fill('password123');
    await page.locator('button:has-text("Next")').click();
    await page.waitForTimeout(500);
    await expect(page.locator('#account-name')).toBeVisible();
  });

  test('validates password - rejects too short', async ({ page }) => {
    await page.goto('/?step=3');
    await page.waitForLoadState('networkidle');

    await page.locator('#account-name').fill('Test User');
    await page.locator('#account-email').fill('test@example.com');
    await page.locator('#account-password').fill('short');
    await page.locator('button:has-text("Next")').click();
    await page.waitForTimeout(500);
    await expect(page.locator('#account-name')).toBeVisible();
  });

  test('accepts valid account details', async ({ page }) => {
    await page.goto('/?step=3');
    await page.waitForLoadState('networkidle');

    await page.locator('#account-name').fill('Test User');
    await page.locator('#account-email').fill('test@example.com');
    await page.locator('#account-password').fill('password123');
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('[data-review-value="appDomain"]', { state: 'visible', timeout: 10000 });
    await expect(page.locator('[data-review-value="appDomain"]')).toBeVisible();
  });

  test('allows navigating back to step 2', async ({ page }) => {
    await page.locator('button:has-text("Back")').click();
    await page.waitForSelector('.installer-card[data-step="2"]', { state: 'visible', timeout: 5000 });
    await expect(page.locator('.installer-card[data-step="2"]')).toBeVisible();
  });
});

test.describe('@install Appwrite Web Installer - Step 4: Review', () => {
  test.skip(isUpgradeRun, 'Upgrade mode');
  test.beforeEach(async ({ page }) => {
    await page.goto('/?step=4');
    await page.waitForLoadState('networkidle');
  });

  test('shows review step', async ({ page }) => {
    await expect(page.locator('[data-review-value="appDomain"]')).toBeVisible();
    await expect(page.locator('h1')).toContainText(/Review your setup/i);
  });

  test('displays review card', async ({ page }) => {
    await expect(page.locator('.review-card')).toBeVisible();
  });

  test('shows hostname field in review', async ({ page }) => {
    await expect(page.locator('[data-review-value="appDomain"]')).toBeVisible();
  });

  test('shows database field in review', async ({ page }) => {
    await expect(page.locator('[data-review-value="database"]')).toBeVisible();
    await expect(page.locator('[data-review-value="database"]')).toContainText(/MongoDB|MariaDB/);
  });

  test('shows HTTP port field in review', async ({ page }) => {
    await expect(page.locator('[data-review-value="httpPort"]')).toBeVisible();
  });

  test('shows HTTPS port field in review', async ({ page }) => {
    await expect(page.locator('[data-review-value="httpsPort"]')).toBeVisible();
  });

  test('shows SSL email field in review', async ({ page }) => {
    await expect(page.locator('[data-review-value="emailCertificates"]')).toBeVisible();
  });

  test('shows secret key status badge', async ({ page }) => {
    await expect(page.locator('[data-review-badge]')).toBeVisible();
    await expect(page.locator('[data-review-badge]')).toContainText(/Generated|Missing/);
  });

  test('shows assistant status badge', async ({ page }) => {
    await expect(page.locator('[data-review-assistant-badge]')).toBeVisible();
  });

  test('shows install button', async ({ page }) => {
    await expect(page.locator('button:has-text("Install")')).toBeVisible();
  });

  test('allows navigating back to step 3', async ({ page }) => {
    await page.locator('button:has-text("Back")').click();
    await page.waitForSelector('#account-name', { state: 'visible', timeout: 5000 });
    await expect(page.locator('#account-name')).toBeVisible();
  });

  test('reflects setup values from previous steps', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    // Step 1: setup
    await page.locator('#hostname').fill('review.example.com');
    await page.locator('input[name="database"][value="mariadb"]').check({ force: true });
    await page.locator('button:has-text("Advanced settings")').click();
    await page.locator('#http-port').fill('8088');
    await page.locator('#https-port').fill('8448');
    await page.locator('#ssl-email').fill('review@example.com');
    await page.locator('#assistant-openai-key').fill('');
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('#secret-key', { state: 'visible', timeout: 10000 });

    // Step 2: secret key
    await page.locator('#secret-key').fill('a'.repeat(32));
    await page.waitForTimeout(1000); /* due to transition */
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('#account-name', { state: 'visible', timeout: 10000 });

    // Step 3: account creation
    await page.locator('#account-name').fill('Review User');
    await page.locator('#account-email').fill('review@example.com');
    await page.locator('#account-password').fill('ReviewPass123!');
    await page.waitForTimeout(1000); /* due to transition */
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('[data-review-value="appDomain"]', { state: 'visible', timeout: 10000 });

    await expect(page.locator('[data-review-value="appDomain"]')).toContainText('review.example.com');
    await expect(page.locator('[data-review-value="database"]')).toContainText('MariaDB');
    await expect(page.locator('[data-review-value="httpPort"]')).toContainText('8088');
    await expect(page.locator('[data-review-value="httpsPort"]')).toContainText('8448');
    await expect(page.locator('[data-review-value="emailCertificates"]')).toContainText('review@example.com');
    await expect(page.locator('[data-review-badge]')).toContainText('Generated');
    await expect(page.locator('[data-review-assistant-badge]')).toContainText(/Disabled/i);
  });
});

test.describe('@install Appwrite Web Installer - Step 5: Install', () => {
  test.skip(isUpgradeRun, 'Upgrade mode');
  test.beforeEach(async ({ page }) => {
    await page.goto('/?step=5');
    await page.waitForLoadState('networkidle');
  });

  test('shows install list and progresses in mock mode', async ({ page }) => {
    await expect(page.locator('.install-list')).toBeVisible();
    const isUpgrade = await page.locator('body').getAttribute('data-upgrade');
    if (isUpgrade === 'true') {
      await expect(page.locator('.install-row[data-step="docker-containers"][data-status="completed"]')).toBeVisible({ timeout: 15000 });
    } else {
      await expect(page.locator('.install-row[data-step="account-setup"][data-status="completed"]')).toBeVisible({ timeout: 15000 });
    }
  });
});

test.describe('@upgrade Appwrite Web Installer - Upgrade Flow', () => {
  test.skip(!isUpgradeRun, 'Install mode');
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toHaveAttribute('data-upgrade', 'true');
  });

  test('shows upgrade heading and locked database', async ({ page }) => {
    await fillValidSetup(page);
    await expect(page.locator('h1')).toContainText(/Update your app/i);
    await expect(page.locator('.selector-group.is-locked')).toBeVisible();
    await expect(page.locator('input[name="database"][value="mariadb"]')).toBeDisabled();
    await expect(page.locator('input[name="database"][value="mongodb"]')).toBeChecked();
    await expect(page.locator('.selector-card.is-disabled')).toHaveCount(1);
  });

  test('skips account step and goes to review', async ({ page }) => {
    await fillValidSetup(page);
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('[data-review-value="appDomain"]', { state: 'visible', timeout: 10000 });
    await expect(page.getByRole('heading', { name: /Review your update/i })).toBeVisible();
    await expect(page.locator('button:has-text("Update")')).toBeVisible();
  });

  test('upgrade mock progresses without account step', async ({ page }) => {
    await fillValidSetup(page);
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('[data-review-value="appDomain"]', { state: 'visible', timeout: 10000 });
    await page.locator('button:has-text("Update")').click();
    await page.waitForSelector('.install-row[data-step="docker-containers"][data-status="completed"]', { timeout: 15000 });
  });
});

test.describe('@install Appwrite Web Installer - Toasts', () => {
  test.skip(isUpgradeRun, 'Upgrade mode');
  test('shows session expired toast when mock toast flag is set', async ({ page }) => {
    await page.addInitScript(() => {
      sessionStorage.setItem('appwrite-installer-mock-settings', JSON.stringify({ toast: true }));
    });
    await page.goto('/?step=5');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('.installer-toast')).toBeVisible();
  });
});

test.describe('@install Appwrite Web Installer - Navigation', () => {
  test.skip(isUpgradeRun, 'Upgrade mode');
  test('can navigate forward through all steps', async ({ page }) => {
    await page.goto('/?step=1');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#hostname')).toBeVisible();

    await page.goto('/?step=2');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#secret-key')).toBeVisible();

    await page.goto('/?step=3');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#account-name')).toBeVisible();

    await page.goto('/?step=4');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-review-value="appDomain"]')).toBeVisible();
  });

  test('can navigate backward through all steps', async ({ page }) => {
    await page.goto('/?step=4');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-review-value="appDomain"]')).toBeVisible();

    await page.locator('button:has-text("Back")').click();
    await page.waitForSelector('#account-name', { state: 'visible', timeout: 5000 });
    await expect(page.locator('#account-name')).toBeVisible();

    await page.locator('button:has-text("Back")').click();
    await page.waitForSelector('.installer-card[data-step="2"]', { state: 'visible', timeout: 5000 });
    await expect(page.locator('.installer-card[data-step="2"]')).toBeVisible();

    await page.locator('button:has-text("Back")').click();
    await page.waitForSelector('.installer-card[data-step="1"]', { state: 'visible', timeout: 5000 });
    await expect(page.locator('.installer-card[data-step="1"]')).toBeVisible();
  });

  test('preserves form values when navigating back', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    await page.locator('#hostname').fill('preserved.example.com');
    await openAdvancedSettings(page);
    await page.locator('#ssl-email').fill('admin@example.com');
    await page.locator('button:has-text("Next")').click();
    await page.waitForSelector('.installer-card[data-step="2"]', { state: 'visible', timeout: 5000 });
    await page.locator('button:has-text("Back")').click();
    await page.waitForSelector('.installer-card[data-step="1"]', { state: 'visible', timeout: 5000 });

    await expect(page.locator('#hostname')).toHaveValue('preserved.example.com');
  });
});

test.describe('@install Appwrite Web Installer - URL Parameters', () => {
  test.skip(isUpgradeRun, 'Upgrade mode');
  test('supports ?step=1 parameter', async ({ page }) => {
    await page.goto('/?step=1');
    await expect(page.locator('.installer-card[data-step="1"]')).toBeVisible();
  });

  test('supports ?step=2 parameter', async ({ page }) => {
    await page.goto('/?step=2');
    await expect(page.locator('.installer-card[data-step="2"]')).toBeVisible();
  });

  test('supports ?step=3 parameter', async ({ page }) => {
    await page.goto('/?step=3');
    await expect(page.locator('#account-name')).toBeVisible();
  });

  test('supports ?step=4 parameter', async ({ page }) => {
    await page.goto('/?step=4');
    await expect(page.locator('[data-review-value="appDomain"]')).toBeVisible();
  });

  test('supports ?step=5 parameter', async ({ page }) => {
    await page.goto('/?step=5');
    await expect(page.locator('body[data-step="5"]')).toBeVisible();
  });

  test('normalizes out-of-range step parameter to step 5', async ({ page }) => {
    await page.goto('/?step=99');
    await page.waitForLoadState('networkidle');
    const body = page.locator('body');
    await expect(body).toHaveAttribute('data-step', '5');
  });
});

test.describe('@install Appwrite Web Installer - Accessibility', () => {
  test.skip(isUpgradeRun, 'Upgrade mode');
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('has proper name attributes for inputs', async ({ page }) => {
    await expect(page.locator('#hostname')).toHaveAttribute('name', 'hostname');
    await page.locator('.accordion-toggle').click();
    await expect(page.locator('#http-port')).toHaveAttribute('name', 'httpPort');
  });

  test('password toggle has aria-label', async ({ page }) => {
    await page.goto('/?step=3');
    await expect(page.locator('[data-password-toggle="account-password"]')).toHaveAttribute('aria-label');
  });

  test('database selector tooltips have role="tooltip"', async ({ page }) => {
    const tooltips = page.locator('.tooltip');
    const count = await tooltips.count();
    for (let i = 0; i < count; i++) {
      await expect(tooltips.nth(i)).toHaveAttribute('role', 'tooltip');
    }
  });
});

test.describe('Appwrite Web Installer - Design System', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('uses Pink typography classes', async ({ page }) => {
    const titleCount = await page.locator('.typography-title-s').count();
    const textCount = await page.locator('.typography-text-m-400').count();
    expect(titleCount).toBeGreaterThan(0);
    expect(textCount).toBeGreaterThan(0);
  });

  test('has no CSP-violating inline styles', async ({ page }) => {
    // The installer should use CSS classes like .is-hidden instead of inline styles
    const hasStylesheet = await page.evaluate(() => {
      return document.querySelector('link[rel="stylesheet"][href*="styles.css"]') !== null;
    });
    expect(hasStylesheet).toBe(true);
  });

  test('loads external stylesheet', async ({ page }) => {
    const stylesheets = await page.evaluate(() =>
      Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(link => link.getAttribute('href'))
    );
    expect(stylesheets.some(href => href && href.includes('styles.css'))).toBe(true);
  });
});
