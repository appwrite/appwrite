// @ts-check
const {defineConfig, devices} = require('@playwright/test');

/**
 * @see https://playwright.dev/docs/test-configuration
 */
module.exports = defineConfig({
    testDir: './tests/playwright',
    globalTeardown: './tests/playwright/global-setup.js',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: 'html',
    use: {
        baseURL: 'http://localhost:20080',
        trace: 'on-first-retry',
        screenshot: 'on',
    },

    webServer: {
        command: process.env.TEST_MODE === 'upgrade'
            ? 'composer installer:mock-upgrade'
            : 'composer installer:mock',
        url: 'http://localhost:20080',
        timeout: 120000,
        reuseExistingServer: false,
    },

    projects: [
        {
            name: 'chromium',
            use: {...devices['Desktop Chrome']},
        },

        {
            name: 'firefox',
            use: {...devices['Desktop Firefox']},
        },

        {
            name: 'webkit',
            use: {...devices['Desktop Safari']},
        },
    ],
});
