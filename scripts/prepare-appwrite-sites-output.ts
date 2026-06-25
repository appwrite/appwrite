/**
 * Appwrite Sites SSR detection scans the configured output directory for
 * `server/server.js` (or `server/index.mjs`). TanStack Start framework presets
 * default to `./.output`, but this repo builds to `dist/` via Vite.
 *
 * Link `.output` -> `dist` after build so bundle helpers find the SSR server entry
 * when the site still uses the framework default path (`.output`).
 *
 * Adapter type (ssr vs static) is detected separately from vite.config.ts; see the
 * `appwriteAdapterHint` in vite.config.ts when using partial marketing prerender.
 *
 * Run: bun run scripts/prepare-appwrite-sites-output.ts
 */
import { access, lstat, rm, symlink } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const SSR_ENTRY = join(ROOT, 'dist', 'server', 'server.js')
const OUTPUT_LINK = join(ROOT, '.output')

async function run() {
  try {
    await access(SSR_ENTRY)
  } catch {
    console.log(
      'Skipping .output link: dist/server/server.js not found (not an SSR build)',
    )
    return
  }

  try {
    const stat = await lstat(OUTPUT_LINK)
    if (stat.isSymbolicLink()) {
      await rm(OUTPUT_LINK)
    } else {
      console.warn(
        '.output exists and is not a symlink; leaving it unchanged for Appwrite Sites detection',
      )
      return
    }
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
      throw error
    }
  }

  await symlink('dist', OUTPUT_LINK)
  console.log('Linked .output -> dist for Appwrite Sites SSR detection')
}

await run()
