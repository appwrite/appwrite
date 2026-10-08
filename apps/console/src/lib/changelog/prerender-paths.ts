import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const packageRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../..',
)

const changelogEntriesDirectory = path.join(
  packageRoot,
  'src/content/changelog/entries',
)

function readChangelogEntryPathsFromDirectories(
  directories: string[],
): string[] {
  const slugs = new Set<string>()

  for (const directory of directories) {
    if (!fs.existsSync(directory)) continue

    for (const filename of fs.readdirSync(directory)) {
      if (!filename.endsWith('.markdoc') && !filename.endsWith('.html')) continue
      slugs.add(filename.replace(/\.(markdoc|html)$/, ''))
    }
  }

  return [...slugs].sort().map((slug) => `/changelog/entry/${slug}`)
}

/** Build-time paths from source markdoc files. */
export function getChangelogEntryPrerenderPaths(): string[] {
  return readChangelogEntryPathsFromDirectories([changelogEntriesDirectory])
}

/** Runtime paths from prerendered client HTML output. */
export function getChangelogEntryPrerenderPathsFromClient(
  clientDirectory: string,
): string[] {
  return readChangelogEntryPathsFromDirectories([
    path.join(clientDirectory, 'changelog/entry'),
  ])
}
