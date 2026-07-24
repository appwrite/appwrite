import 'dotenv/config'
import { devices, type PlaywrightTestConfig } from '@playwright/test'
import { env } from './e2e/config/env'

const isCI = env.CI
const storageState = 'e2e/.auth/auth.json'

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
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'website',
      testMatch: /website\..*\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'console',
      dependencies: ['setup'],
      testMatch: /console\..*\.spec\.ts/,
      use: {
        ...devices['Desktop Chrome'],
        storageState,
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
