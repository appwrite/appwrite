/**
 * Fail the build if Appwrite Sites SSR detection would classify the output as static.
 * Appwrite looks for `server/server.js` (or `server/index.mjs`) inside the output dir.
 */
import { access } from 'node:fs/promises'
import { join } from 'node:path'

const ROOT = join(import.meta.dirname, '..')
const OUTPUT_DIR = join(ROOT, 'dist')
const SSR_CANDIDATES = [
  join(OUTPUT_DIR, 'server', 'server.js'),
  join(OUTPUT_DIR, 'server', 'index.mjs'),
]

async function run() {
  for (const entry of SSR_CANDIDATES) {
    try {
      await access(entry)
      console.log(`Appwrite Sites SSR entry found: ${entry.replace(`${ROOT}/`, '')}`)
      return
    } catch {
      // try next candidate
    }
  }

  console.error(
    'Appwrite Sites SSR build is missing dist/server/server.js (and dist/server/index.mjs).',
  )
  console.error(
    'Use "pnpm run build:node" for Sites deploys and set output directory to "dist".',
  )
  process.exit(1)
}

await run()
