/**
 * Appwrite Sites parses the first `{APPWRITE_DETECTION_SEPARATOR_*}` block in build
 * logs and classifies SSR when a line exactly matches `server/server.js` for
 * framework `tanstack-start`.
 *
 * Post-build `find .` prints `./server/server.js`, which does not match, so SSR
 * sites fail with "Adapter mismatch: static vs ssr" even when the file exists.
 * Emit the expected path after the build finishes (last step in build:node).
 */
import { access } from 'node:fs/promises'
import { join } from 'node:path'

const ROOT = join(import.meta.dirname, '..')
const OUTPUT_DIR = join(ROOT, 'dist')
const SSR_CANDIDATES = [
  join(OUTPUT_DIR, 'server', 'server.js'),
  join(OUTPUT_DIR, 'server', 'index.mjs'),
]

const START = '{APPWRITE_DETECTION_SEPARATOR_START}'
const END = '{APPWRITE_DETECTION_SEPARATOR_END}'

async function run() {
  for (const entry of SSR_CANDIDATES) {
    try {
      await access(entry)
      console.log(START)
      console.log('server/server.js')
      console.log(END)
      return
    } catch {
      // try next candidate
    }
  }

  console.error(
    'Cannot emit Appwrite SSR detection markers: dist/server/server.js is missing.',
  )
  process.exit(1)
}

await run()
