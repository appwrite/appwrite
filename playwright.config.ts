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

const consoleDatabaseUse = {
  ...desktopChrome,
  storageState,
  video: {
    mode: 'retain-on-failure' as const,
    size: E2E_VIEWPORT,
  },
  trace: 'retain-on-failure' as const,
  screenshot: 'only-on-failure' as const,
  actionTimeout: 20_000,
  navigationTimeout: 45_000,
}

const config: PlaywrightTestConfig = {
  timeout: 90_000,
  expect: {
    timeout: 15_000,
  },
  reportSlowTests: null,
  reporter: isCI
    ? [
        ['list'],
        ['github'],
        ['html', { open: 'never', outputFolder: 'playwright-report' }],
      ]
    : [['html', { open: 'never', outputFolder: 'playwright-report' }]],
  retries: isCI ? 2 : 0,
  forbidOnly: isCI,
  workers: isCI ? 2 : undefined,
  testDir: 'e2e',
  outputDir: 'test-results',
  globalTeardown: './e2e/global-teardown.ts',
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
      timeout: 20 * 60_000,
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
      // Smoke / read-only console specs only (exclude write database suites).
      testMatch:
        /console\.(?!mysql\.|postgres\.|tablesdb\.|documentsdb\.|vectorsdb\.|usage\.).*\.spec\.ts/,
      use: {
        ...desktopChrome,
        storageState,
      },
    },
    {
      name: 'console-usage',
      dependencies: ['setup'],
      testMatch: /console\.usage\.spec\.ts/,
      use: {
        ...desktopChrome,
        storageState,
      },
    },
    {
      // Local-only, like console-usage-live: it needs a self-hosted *backend*,
      // not just a self-hosted build. CI only has the cloud endpoint, and a
      // self-hosted bundle pointed at cloud cannot even get through auth.setup.
      // Run with `bun run e2e:usage-self-hosted` against a local instance.
      name: 'self-hosted-usage',
      dependencies: ['setup'],
      testMatch: /self-hosted\.usage\.spec\.ts/,
      use: {
        ...desktopChrome,
        storageState,
      },
    },
    {
      name: 'console-usage-live',
      dependencies: ['setup'],
      testMatch: /console\.usage\.live\.spec\.ts/,
      fullyParallel: false,
      workers: 1,
      use: consoleDatabaseUse,
    },
    {
      name: 'console-mysql',
      dependencies: ['setup'],
      testMatch: /console\.mysql\.spec\.ts/,
      timeout: 25 * 60_000,
      fullyParallel: false,
      workers: 1,
      use: consoleDatabaseUse,
    },
    {
      name: 'console-postgres',
      dependencies: ['setup'],
      testMatch: /console\.postgres\.spec\.ts/,
      timeout: 25 * 60_000,
      fullyParallel: false,
      workers: 1,
      use: consoleDatabaseUse,
    },
    {
      name: 'console-tablesdb',
      dependencies: ['setup'],
      testMatch: /console\.tablesdb\.spec\.ts/,
      timeout: 20 * 60_000,
      fullyParallel: false,
      workers: 1,
      use: consoleDatabaseUse,
    },
    {
      name: 'console-documentsdb',
      dependencies: ['setup'],
      testMatch: /console\.documentsdb\.spec\.ts/,
      timeout: 25 * 60_000,
      fullyParallel: false,
      workers: 1,
      use: consoleDatabaseUse,
    },
    {
      name: 'console-vectorsdb',
      dependencies: ['setup'],
      testMatch: /console\.vectorsdb\.spec\.ts/,
      timeout: 25 * 60_000,
      fullyParallel: false,
      workers: 1,
      use: consoleDatabaseUse,
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
      // Cloud profile enables native / dedicated database feature flags.
      VITE_CONSOLE_PROFILE: process.env.VITE_CONSOLE_PROFILE || 'cloud',
      VITE_CONSOLE_FINGERPRINT_KEY:
        process.env.VITE_CONSOLE_FINGERPRINT_KEY || '',
      // Left empty so the backend's `_APP_USAGE_STATS` decides, which is what
      // the self-hosted lane asserts against.
      VITE_CONSOLE_USAGE_STATS: process.env.VITE_CONSOLE_USAGE_STATS || '',
      VITE_CONSOLE_WEBSITE_ACCESS:
        process.env.VITE_CONSOLE_WEBSITE_ACCESS || '',
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
