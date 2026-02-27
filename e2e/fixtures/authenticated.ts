import fs from 'node:fs'
import { test as base, expect } from './base'

const STORAGE_STATE_PATH = 'e2e/.auth/auth.json'

export const test = base.extend({
  page: async ({ browser }, use) => {
    if (!fs.existsSync(STORAGE_STATE_PATH)) {
      throw new Error(
        `Storage state file not found at: ${STORAGE_STATE_PATH}\n` +
          'Run the setup project to create it:\n' +
          '  bun run e2e --project=setup',
      )
    }

    const context = await browser.newContext({
      storageState: STORAGE_STATE_PATH,
    })
    const page = await context.newPage()
    await use(page)
    await context.close()
  },
})

export { expect }
