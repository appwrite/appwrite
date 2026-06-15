import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { getChangelogEntryPrerenderPaths } from '@/lib/changelog/prerender-paths'
import { parseChangelogFrontmatter } from '@/lib/changelog/frontmatter'
import type { SitemapEntry } from '../types'

const packageRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../../..',
)

const entriesDirectory = path.join(
  packageRoot,
  'src/content/changelog/entries',
)

function toIsoDate(value: string | undefined): string | undefined {
  if (!value) return undefined
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return undefined
  return parsed.toISOString().slice(0, 10)
}

function readEntryLastmodBySlug(): Map<string, string> {
  const lastmodBySlug = new Map<string, string>()
  if (!fs.existsSync(entriesDirectory)) return lastmodBySlug

  for (const filename of fs.readdirSync(entriesDirectory)) {
    if (!filename.endsWith('.markdoc')) continue
    const slug = filename.replace(/\.markdoc$/, '')
    const raw = fs.readFileSync(path.join(entriesDirectory, filename), 'utf8')
    const { frontmatter } = parseChangelogFrontmatter(raw)
    const lastmod = toIsoDate(frontmatter.date)
    if (lastmod) lastmodBySlug.set(slug, lastmod)
  }

  return lastmodBySlug
}

export function getChangelogSitemapEntries(): SitemapEntry[] {
  const lastmodBySlug = readEntryLastmodBySlug()

  return getChangelogEntryPrerenderPaths()
    .map((pathname) => {
      const slug = pathname.match(/^\/changelog\/entry\/(.+)$/)?.[1]
      return {
        path: pathname,
        lastmod: slug ? lastmodBySlug.get(slug) : undefined,
        priority: 0.6,
        changefreq: 'monthly',
      } satisfies SitemapEntry
    })
    .sort((a, b) => a.path.localeCompare(b.path))
}
