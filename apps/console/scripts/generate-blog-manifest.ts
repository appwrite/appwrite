/**
 * Regenerates blog post metadata manifest.
 * Run: bun run generate:blog-manifest
 */
import { spawnSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ROOT = join(__dirname, '..')

const result = spawnSync('bun', ['run', join(__dirname, 'lib', 'generate-blog-manifest.ts')], {
  cwd: ROOT,
  stdio: 'inherit',
})

if (result.status !== 0) process.exit(result.status ?? 1)
