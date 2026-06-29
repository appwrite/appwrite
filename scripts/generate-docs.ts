/**
 * Regenerates docs manifest, section nav, LLM exports, and sitemap.
 * Run: bun run generate:docs
 */
import { spawnSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ROOT = join(__dirname, '..')

function runScript(relativePath: string) {
  const result = spawnSync('bun', ['run', join(__dirname, relativePath)], {
    cwd: ROOT,
    stdio: 'inherit',
  })
  if (result.status !== 0) process.exit(result.status ?? 1)
}

function runTask(task: string) {
  const result = spawnSync('bun', ['run', task], {
    cwd: ROOT,
    stdio: 'inherit',
  })
  if (result.status !== 0) process.exit(result.status ?? 1)
}

runScript('lib/generate-docs-manifest.ts')
runScript('generate-docs-nav.ts')
runScript('generate-docs-exports.ts')
runTask('generate:sitemap')
