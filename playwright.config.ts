import 'dotenv/config'
import { devices, type PlaywrightTestConfig } from '@playwright/test'
import { env } from './e2e/config/env'

const isCI = env.CI

const config: PlaywrightTestConfig = {
  timeout: 120000,
  reportSlowTests: null,
  reporter: isCI
    ? [
        ['github'],
        ['html', { open: 'never', outputFolder: 'e2e/playwright-report' }],
      ]
    : [['html', { open: 'never', outputFolder: 'e2e/playwright-report' }]],
  retries: isCI ? 2 : 0,
  forbidOnly: isCI,
  workers: undefined,
  testDir: 'e2e',
  outputDir: 'e2e/test-results',
  use: {
    baseURL: 'http://localhost:4173/',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'setup',
      testMatch: '**/*.setup.ts',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'chromium',
      dependencies: ['setup'],
      testIgnore: '**/*.setup.ts',
      use: {
        ...devices['Desktop Chrome'],
        storageState: 'e2e/.auth/auth.json',
      },
    },
  ],
  webServer: {
    reuseExistingServer: !env.CI,
    timeout: 120000,
    env: {
      VITE_APPWRITE_ENDPOINT: env.VITE_APPWRITE_ENDPOINT,
      PUBLIC_CONSOLE_MODE: process.env.PUBLIC_CONSOLE_MODE || '',
      PUBLIC_APPWRITE_MULTI_REGION:
        process.env.PUBLIC_APPWRITE_MULTI_REGION || '',
      VITE_STRIPE_PUBLISHABLE_KEY:
        process.env.VITE_STRIPE_PUBLISHABLE_KEY || '',
    },
    command: 'bun run build && PORT=4173 bun run start',
    port: 4173,
  },
}

export default config
