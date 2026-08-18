import { test as base } from '@playwright/test'
import { seedAcceptedCookieConsent } from './helpers/cookie-banner'

/**
 * Default e2e test fixture. Cookie consent is accepted before page scripts
 * run so the GDPR banner does not overlay the app.
 */
export const test = base.extend({
  page: async ({ page, context }, use) => {
    await seedAcceptedCookieConsent(context)
    await use(page)
  },
})

export { expect } from '@playwright/test'
