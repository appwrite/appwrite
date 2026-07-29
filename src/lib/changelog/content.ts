import { markdocToMarkdown } from '@/lib/seo/markdoc-to-markdown'
import { resolveChangelogAssetUrl } from './assets'
import { parseChangelogFrontmatter } from './frontmatter'
import type { ChangelogEntry, ChangelogEntryMeta } from './types'

const PER_PAGE = 5

const contentLoaders = import.meta.glob('/src/content/changelog/entries/*.markdoc', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

function slugFromModulePath(modulePath: string): string {
  const match = modulePath.match(/\/src\/content\/changelog\/entries\/(.+)\.markdoc$/)
  return match?.[1] ?? ''
}

function buildChangelogEntry(modulePath: string, raw: string): ChangelogEntry {
  const slug = slugFromModulePath(modulePath)
  const { frontmatter, body } = parseChangelogFrontmatter(raw)

  return {
    slug,
    href: `/changelog/entry/${slug}`,
    title: frontmatter.title ?? slug,
    date: frontmatter.date ?? '',
    description: frontmatter.description,
    cover: resolveChangelogAssetUrl(frontmatter.cover),
    content: body.trim(),
  }
}

const allChangelogEntries = Object.entries(contentLoaders)
  .map(([modulePath, raw]) => buildChangelogEntry(modulePath, raw))
  .sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime())

export const changelogCount = allChangelogEntries.length

export function getAllChangelogEntries(): ChangelogEntry[] {
  return allChangelogEntries
}

export function getChangelogEntry(slug: string): ChangelogEntry | null {
  return allChangelogEntries.find((entry) => entry.slug === slug) ?? null
}

/** Plain-markdown source (including frontmatter) for the .md export endpoint. */
export function getChangelogMarkdownExport(slug: string): string | null {
  if (!getChangelogEntry(slug)) return null

  const modulePath = Object.keys(contentLoaders).find(
    (path) => slugFromModulePath(path) === slug,
  )
  if (!modulePath) return null
  const raw = contentLoaders[modulePath]
  return raw ? markdocToMarkdown(raw) : null
}

export function getChangelogEntriesPage(page: number): {
  entries: ChangelogEntry[]
  nextPage: number | null
} {
  const safePage = Math.max(1, page)
  const entries = allChangelogEntries.slice(0, safePage * PER_PAGE)
  const totalPages = Math.ceil(changelogCount / PER_PAGE)

  return {
    entries,
    nextPage: safePage < totalPages ? safePage + 1 : null,
  }
}

export function toChangelogEntryMeta(entry: ChangelogEntry): ChangelogEntryMeta {
  return {
    slug: entry.slug,
    href: entry.href,
    title: entry.title,
    date: entry.date,
    description: entry.description,
    cover: entry.cover,
  }
}
