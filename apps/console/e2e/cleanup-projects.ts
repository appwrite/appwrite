import 'dotenv/config'
import { cleanupE2eProjects } from './helpers/e2e-project-cleanup'

/**
 * Delete leftover disposable e2e projects in E2E_ORG_ID.
 *
 * Usage: bun run e2e:cleanup-projects
 *
 * Do not run this while another database e2e suite is still using the org:
 * `--all` deletes every matching `e2e-*` project, not only stale ones.
 */
const deleteAll = process.argv.includes('--all')

const result = await cleanupE2eProjects({
  includeStaleLeftovers: true,
  staleAfterMs: deleteAll ? 0 : undefined,
})

if (result.deleted.length === 0 && result.failed.length === 0) {
  console.error('[e2e] No leftover disposable e2e projects found')
}

if (result.failed.length > 0) {
  process.exitCode = 1
}
