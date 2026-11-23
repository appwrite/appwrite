import { test as base } from '../fixtures'
import { seedDatabaseFeatureFlags } from '../helpers/feature-flags'

/**
 * Database e2e pages start with DocumentsDB / VectorsDB / native engine flags
 * on, before the first navigation. Canonical cloud profile leaves those off.
 */
export const test = base.extend({
  page: async ({ page }, use) => {
    await seedDatabaseFeatureFlags(page.context())
    await use(page)
  },
})

export { expect } from '../fixtures'
