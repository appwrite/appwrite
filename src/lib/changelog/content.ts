import { markdocToMarkdown } from '@/lib/seo/markdoc-to-markdown'
import { resolveChangelogAssetUrl } from './assets'
import { parseChangelogFrontmatter } from './frontmatter'
import type { ChangelogEntry, ChangelogEntryMeta } from './types'

const PER_PAGE = 5

const importedLoaders = import.meta.glob(
  '/src/content/changelog/entries/*.markdoc',
  {
    query: '?raw',
    import: 'default',
    eager: true,
  },
) as Record<string, string>

const localLoaders = import.meta.glob(
  '/src/content/changelog-local/entries/*.markdoc',
  {
    query: '?raw',
    import: 'default',
    eager: true,
  },
) as Record<string, string>

function slugFromModulePath(modulePath: string): string {
  const match = modulePath.match(
    /\/src\/content\/changelog(?:-local)?\/entries\/(.+)\.markdoc$/,
  )
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

function collectChangelogEntries(): ChangelogEntry[] {
  const entriesBySlug = new Map<string, ChangelogEntry>()

  for (const [modulePath, raw] of Object.entries(importedLoaders)) {
    const entry = buildChangelogEntry(modulePath, raw)
    entriesBySlug.set(entry.slug, entry)
  }

  for (const [modulePath, raw] of Object.entries(localLoaders)) {
    const entry = buildChangelogEntry(modulePath, raw)
    entriesBySlug.set(entry.slug, entry)
  }

  return [...entriesBySlug.values()].sort(
    (a, b) => new Date(b.date).getTime() - new Date(a.date).getTime(),
  )
}

const allChangelogEntries = collectChangelogEntries()

export const changelogCount = allChangelogEntries.length

export function getAllChangelogEntries(): ChangelogEntry[] {
  return allChangelogEntries
}

export function getChangelogEntry(slug: string): ChangelogEntry | null {
  return allChangelogEntries.find((entry) => entry.slug === slug) ?? null
}

function getRawForSlug(slug: string): string | null {
  const localPath = Object.keys(localLoaders).find(
    (path) => slugFromModulePath(path) === slug,
  )
  if (localPath) return localLoaders[localPath] ?? null

  const importedPath = Object.keys(importedLoaders).find(
    (path) => slugFromModulePath(path) === slug,
  )
  if (importedPath) return importedLoaders[importedPath] ?? null

  return null
}

/** Plain-markdown source (including frontmatter) for the .md export endpoint. */
export function getChangelogMarkdownExport(slug: string): string | null {
  if (!getChangelogEntry(slug)) return null
  const raw = getRawForSlug(slug)
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
