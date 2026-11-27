/**
 * Records when each content source last changed in git, for sitemap <lastmod>.
 * Run: bun run generate:content-dates
 *
 * The Docker build has no .git, so CI runs this on the checkout before the
 * image build and the JSON travels in the build context. Without git history
 * (no repository, or a shallow clone that would date every file to the tip
 * commit) the script keeps whatever file is already there.
 */
import { spawnSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  CONTENT_DATE_SOURCES,
  CONTENT_DATES_PATH,
} from '../src/lib/sitemap/content-dates'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

/**
 * apps/console was imported into this repository on 2026-10-08 without its
 * earlier history, so that merge dates every untouched file. Changes on or
 * before it are unknown and get no date.
 */
const HISTORY_START = '2026-10-08'

function git(args: string[]) {
  return spawnSync('git', args, {
    cwd: ROOT,
    encoding: 'utf8',
    maxBuffer: 256 * 1024 * 1024,
  })
}

const shallow = git(['rev-parse', '--is-shallow-repository'])
if (shallow.status !== 0) {
  console.log('No git repository; keeping the existing content dates.')
  process.exit(0)
}
if (shallow.stdout.trim() === 'true') {
  console.log('Shallow clone; keeping the existing content dates.')
  process.exit(0)
}

// Main's own history, newest first: each merge dates the files it changed,
// so the first date seen for a file is when its last change landed.
const log = git([
  'log',
  '--first-parent',
  '-m',
  '--format=%x00%cs',
  '--name-only',
  '--no-renames',
  '--relative',
  '--',
  ...CONTENT_DATE_SOURCES,
])
if (log.status !== 0) {
  console.error(log.stderr)
  process.exit(1)
}

const files: Record<string, string> = {}
let date = ''
for (const line of log.stdout.split('\n')) {
  if (line.startsWith('\0')) {
    date = line.slice(1)
  } else if (line && date && !(line in files)) {
    files[line] = date
  }
}
for (const [file, changed] of Object.entries(files)) {
  if (changed <= HISTORY_START) delete files[file]
}

const output = join(ROOT, CONTENT_DATES_PATH)
mkdirSync(dirname(output), { recursive: true })
writeFileSync(output, `${JSON.stringify(files)}\n`, 'utf8')
console.log(`Wrote last-change dates for ${Object.keys(files).length} files`)
