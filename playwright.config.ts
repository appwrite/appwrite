import 'dotenv/config'
import { devices, type PlaywrightTestConfig } from '@playwright/test'
import { env } from './e2e/config/env'
import { E2E_VIEWPORT } from './e2e/config/viewport'
import { websiteAccessStorageState } from './e2e/helpers/website-access'

const isCI = env.CI
const storageState = 'e2e/.auth/auth.json'

const desktopChrome = {
  ...devices['Desktop Chrome'],
  viewport: E2E_VIEWPORT,
  screen: E2E_VIEWPORT,
}

const config: PlaywrightTestConfig = {
  timeout: 120_000,
  expect: {
    timeout: 30_000,
  },
  reportSlowTests: null,
  reporter: isCI
    ? [
        ['github'],
        ['html', { open: 'never', outputFolder: 'playwright-report' }],
      ]
    : [['html', { open: 'never', outputFolder: 'playwright-report' }]],
  retries: isCI ? 2 : 0,
  forbidOnly: isCI,
  workers: isCI ? 2 : undefined,
  testDir: 'e2e',
  outputDir: 'test-results',
  use: {
    baseURL: 'http://localhost:4173/',
    viewport: E2E_VIEWPORT,
    screen: E2E_VIEWPORT,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
      use: {
        ...desktopChrome,
        // Soft-launch gate: unlock /sign-in before capturing auth state.
        storageState: websiteAccessStorageState,
      },
    },
    {
      name: 'website',
      testMatch: /website\..*\.spec\.ts/,
      use: {
        ...desktopChrome,
        storageState: websiteAccessStorageState,
      },
    },
    {
      name: 'console',
      dependencies: ['setup'],
      // Smoke / read-only console specs only (exclude MySQL write suites).
      testMatch: /console\.(?!mysql\.).*\.spec\.ts/,
      use: {
        ...desktopChrome,
        storageState,
      },
    },
    {
      name: 'console-mysql',
      dependencies: ['setup'],
      testMatch: /console\.mysql\..*\.spec\.ts/,
      timeout: 15 * 60_000,
      // One worker: each suite provisions a dedicated MySQL instance.
      fullyParallel: false,
      workers: 1,
      use: {
        ...desktopChrome,
        storageState,
        // Keep videos for completed MySQL coverage runs.
        video: {
          mode: 'on',
          size: E2E_VIEWPORT,
        },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        actionTimeout: 60_000,
        navigationTimeout: 90_000,
      },
    },
  ],
  webServer: {
    reuseExistingServer: !isCI,
    timeout: 180_000,
    env: {
      VITE_APPWRITE_ENDPOINT: env.VITE_APPWRITE_ENDPOINT,
      PUBLIC_CONSOLE_MODE: process.env.PUBLIC_CONSOLE_MODE || '',
      PUBLIC_APPWRITE_MULTI_REGION:
        process.env.PUBLIC_APPWRITE_MULTI_REGION || '',
      VITE_STRIPE_PUBLISHABLE_KEY:
        process.env.VITE_STRIPE_PUBLISHABLE_KEY || '',
      // Cloud profile enables native MySQL / dedicated DB feature flags.
      VITE_CONSOLE_PROFILE: process.env.VITE_CONSOLE_PROFILE || 'cloud',
      VITE_CONSOLE_FINGERPRINT_KEY:
        process.env.VITE_CONSOLE_FINGERPRINT_KEY || '',
    },
    // Set E2E_SKIP_BUILD=1 to reuse an existing `dist/` (faster local iteration).
    command:
      process.env.E2E_SKIP_BUILD === '1'
        ? 'PORT=4173 bun run start'
        : 'bun run build && PORT=4173 bun run start',
    port: 4173,
  },
}

export default config
