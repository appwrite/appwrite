import { devices, type PlaywrightTestConfig } from '@playwright/test'

/**
 * Standalone config for the mocked OAuth2 consent journeys. Unlike the main
 * e2e config this needs no real backend, no auth setup, and runs against the
 * dev server (started separately with `bun run dev`).
 */
const config: PlaywrightTestConfig = {
  timeout: 60000,
  // The dev server compiles routes on first hit; give cold loads headroom.
  expect: { timeout: 15000 },
  testDir: 'journeys',
  testMatch: '**/oauth2-consent-mock.spec.ts',
  outputDir: 'test-results/oauth2-mock',
  reporter: [['list']],
  use: {
    baseURL: process.env.OAUTH2_BASE_URL ?? 'http://localhost:3000/',
    ...devices['Desktop Chrome'],
  },
}

export default config
