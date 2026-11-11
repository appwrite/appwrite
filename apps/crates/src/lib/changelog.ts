import { parseFrontmatter, parsePackages } from '@/lib/changelog/frontmatter'

/** A dated note on this site. Newest first. Body is Markdown after the frontmatter. */
export interface Entry {
  slug: string
  date: string
  title: string
  tag?: string
  /** Library slugs and site scopes this update touches (`auth`, `appwrite`, `docs`, …). */
  packages: string[]
  body: string
}

const EXTRA_PACKAGE_LABELS: Record<string, string> = {
  appwrite: 'Appwrite migration',
  docs: 'Docs',
}

const loaders = import.meta.glob('../../content/changelog/*.md', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

function slugFromPath(path: string): string {
  const match = path.match(/\/content\/changelog\/(.+)\.md$/)
  return match?.[1] ?? path
}

function buildEntry(path: string, raw: string): Entry {
  const slug = slugFromPath(path)
  const { frontmatter, body } = parseFrontmatter(raw)
  return {
    slug,
    date: frontmatter.date ?? '',
    title: frontmatter.title ?? slug,
    tag: frontmatter.tag || undefined,
    packages: parsePackages(frontmatter.packages),
    body,
  }
}

export const ENTRIES: Entry[] = Object.entries(loaders)
  .map(([path, raw]) => buildEntry(path, raw))
  .sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime())

/** Labels for filter chips: library titles from the index, plus fixed site scopes. */
export function packageLabel(slug: string, libraryTitle?: string): string {
  return libraryTitle ?? EXTRA_PACKAGE_LABELS[slug] ?? slug
}

/** Package slugs referenced by at least one entry, sorted by label. */
export function packagesWithUpdates(
  entries: Entry[],
  titleBySlug: Map<string, string>,
): { slug: string; label: string }[] {
  const slugs = new Set<string>()
  for (const entry of entries) {
    for (const slug of entry.packages) slugs.add(slug)
  }
  return [...slugs]
    .map((slug) => ({ slug, label: packageLabel(slug, titleBySlug.get(slug)) }))
    .sort((a, b) => a.label.localeCompare(b.label))
}

export function entryMatchesPackage(entry: Entry, pkg: string | undefined): boolean {
  if (!pkg) return true
  return entry.packages.includes(pkg)
}
