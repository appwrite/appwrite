/**
 * Appwrite Sites SSR detection scans the configured output directory for
 * `server/server.js` (or `server/index.mjs`). TanStack Start framework presets
 * default to `./.output`, but this repo builds to `dist/` via Vite.
 *
 * Link `.output` -> `dist` after build so bundle helpers and Appwrite's post-build
 * `find` (when outputDirectory is `.output`) see `server/server.js`.
 *
 * Appwrite build caches can restore a real `.output/` tree without an SSR entry;
 * leaving that directory in place causes "Adapter mismatch: static vs ssr".
 *
 * Set the site output directory to `dist` when possible. Keep this script for
 * sites still using the TanStack default `.output`.
 *
 * Run: node --experimental-strip-types scripts/prepare-appwrite-sites-output.ts
 */
import { lstat, rm, symlink } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUTPUT_LINK = join(ROOT, '.output')

async function run() {
  // verify-appwrite-sites-output.ts runs immediately before this script in build:node

  try {
    const stat = await lstat(OUTPUT_LINK)
    if (stat.isSymbolicLink()) {
      await rm(OUTPUT_LINK)
    } else {
      console.log(
        'Removing stale .output directory so Appwrite SSR detection can use dist/server/server.js',
      )
      await rm(OUTPUT_LINK, { recursive: true, force: true })
    }
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
      throw error
    }
  }

  await symlink('dist', OUTPUT_LINK)
  console.log(
    'Linked .output -> dist for Appwrite Sites SSR detection (server/server.js)',
  )
}

await run()
