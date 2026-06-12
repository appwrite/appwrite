import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const packageRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../..',
)

function readChangelogEntryPathsFromDirectory(directory: string): string[] {
  if (!fs.existsSync(directory)) return []

  return fs
    .readdirSync(directory)
    .filter((filename) => filename.endsWith('.markdoc') || filename.endsWith('.html'))
    .map((filename) => filename.replace(/\.(markdoc|html)$/, ''))
    .sort()
    .map((slug) => `/changelog/entry/${slug}`)
}

/** Build-time paths from source markdoc files. */
export function getChangelogEntryPrerenderPaths(): string[] {
  return readChangelogEntryPathsFromDirectory(
    path.join(packageRoot, 'src/content/changelog/entries'),
  )
}

/** Runtime paths from prerendered client HTML output. */
export function getChangelogEntryPrerenderPathsFromClient(
  clientDirectory: string,
): string[] {
  return readChangelogEntryPathsFromDirectory(
    path.join(clientDirectory, 'changelog/entry'),
  )
}
