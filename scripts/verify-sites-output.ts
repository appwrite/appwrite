/**
 * Fail early if the SSR entry is missing. Appwrite's post-build find lists
 * `./server/server.js`, but utopia-php/detector expects an exact `server/server.js`
 * line; emit-ssr-detection.ts prints that path in the first log block.
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
  console.error(
    'On 4GB build workers use default SITES_PRERENDER_SCOPE=core (omit env). Set SITES_PRERENDER_SCOPE=full only on larger build specs.',
  )
  process.exit(1)
}

await run()
