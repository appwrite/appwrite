import { test as base } from '@playwright/test'
import {
  installCommunitySupportWizardHandler,
  seedAcceptedCookieConsent,
} from './helpers/cookie-banner'

/**
 * Default e2e test fixture. Cookie consent is accepted before page scripts
 * run, and the community-support wizard is auto-dismissed if it appears, so
 * neither overlay blocks the console.
 */
export const test = base.extend({
  page: async ({ page, context }, use) => {
    await seedAcceptedCookieConsent(context)
    await installCommunitySupportWizardHandler(page)
    await use(page)
  },
})

export { expect } from '@playwright/test'
