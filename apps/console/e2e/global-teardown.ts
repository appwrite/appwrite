import 'dotenv/config'
import { cleanupE2eProjects } from './helpers/e2e-project-cleanup'

/**
 * Always run after the Playwright run so disposable projects are deleted even
 * when a worker fixture times out or a test fails before UI teardown.
 */
export default async function globalTeardown(): Promise<void> {
  await cleanupE2eProjects({ includeStaleLeftovers: true })
}
