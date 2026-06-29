/**
 * Fail early if the SSR entry is missing. Appwrite's post-build find lists
 * `./server/server.js`, but utopia-php/detector expects an exact `server/server.js`
 * line; emit-ssr-detection.ts prints that path in the first log block.
 *
 * Also removes accidental prerendered thread HTML under dist/client/threads so
 * stale build cache cannot bloat the artifact or override SSR routes.
 */
import { access, readdir, rm, stat } from 'node:fs/promises'
import { join } from 'node:path'

const ROOT = join(import.meta.dirname, '..')
const OUTPUT_DIR = join(ROOT, 'dist')
const THREADS_CLIENT_DIR = join(OUTPUT_DIR, 'client', 'threads')
const SSR_CANDIDATES = [
  join(OUTPUT_DIR, 'server', 'server.js'),
  join(OUTPUT_DIR, 'server', 'index.mjs'),
]

async function countThreadPrerenderHtmlFiles(): Promise<number> {
  try {
    const entries = await readdir(THREADS_CLIENT_DIR, { withFileTypes: true })
    let count = 0

    for (const entry of entries) {
      if (entry.isFile() && entry.name.endsWith('.html')) {
        count += 1
        continue
      }

      if (entry.isDirectory() && entry.name === 'authors') {
        const authorEntries = await readdir(join(THREADS_CLIENT_DIR, 'authors'))
        count += authorEntries.filter((name) => name.endsWith('.html')).length
      }
    }

    return count
  } catch {
    return 0
  }
}

async function stripAccidentalThreadPrerenderHtml(): Promise<number> {
  try {
    await stat(THREADS_CLIENT_DIR)
  } catch {
    return 0
  }

  const count = await countThreadPrerenderHtmlFiles()
  if (count === 0) return 0

  await rm(THREADS_CLIENT_DIR, { recursive: true, force: true })
  console.log(
    `Removed dist/client/threads (${String(count)} prerendered HTML files; threads stay SSR-only).`,
  )
  return count
}

async function run() {
  await stripAccidentalThreadPrerenderHtml()

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
