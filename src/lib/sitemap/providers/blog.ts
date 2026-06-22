import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { getBlogPrerenderPaths } from '@/lib/blog/prerender-paths'
import {
  getFrontmatterDate,
  parseBlogFrontmatter,
} from '@/lib/blog/frontmatter'
import type { SitemapEntry } from '../types'

const packageRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../../..',
)

const postsDirectories = [
  path.join(packageRoot, 'src/content/blog/posts'),
  path.join(packageRoot, 'src/content/blog-local/posts'),
]

function toIsoDate(value: string | undefined): string | undefined {
  if (!value) return undefined
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return undefined
  return parsed.toISOString().slice(0, 10)
}

function readPostLastmodBySlug(): Map<string, string> {
  const lastmodBySlug = new Map<string, string>()

  for (const postsDirectory of postsDirectories) {
    if (!fs.existsSync(postsDirectory)) continue

    for (const filename of fs.readdirSync(postsDirectory)) {
      if (!filename.endsWith('.markdoc')) continue
      const slug = filename.replace(/\.markdoc$/, '')
      const raw = fs.readFileSync(path.join(postsDirectory, filename), 'utf8')
      const { frontmatter } = parseBlogFrontmatter(raw)
      const lastUpdated = getFrontmatterDate(frontmatter, 'lastUpdated')
      const date = getFrontmatterDate(frontmatter, 'date')
      const lastmod = toIsoDate(lastUpdated || date)
      if (lastmod) lastmodBySlug.set(slug, lastmod)
    }
  }

  return lastmodBySlug
}

function blogPriority(pathname: string): number {
  if (pathname === '/blog') return 0.8
  if (pathname.startsWith('/blog/post/')) return 0.7
  if (pathname.startsWith('/blog/category/')) return 0.6
  if (pathname.startsWith('/blog/author/')) return 0.5
  if (/^\/blog\/\d+$/.test(pathname)) return 0.5
  return 0.6
}

function blogChangeFreq(pathname: string): SitemapEntry['changefreq'] {
  if (pathname.startsWith('/blog/post/')) return 'monthly'
  if (pathname === '/blog' || /^\/blog\/\d+$/.test(pathname)) return 'weekly'
  return 'monthly'
}

export function getBlogSitemapEntries(): SitemapEntry[] {
  const lastmodBySlug = readPostLastmodBySlug()

  return getBlogPrerenderPaths()
    .map((pathname) => {
      const postSlug = pathname.match(/^\/blog\/post\/(.+)$/)?.[1]
      return {
        path: pathname,
        lastmod: postSlug ? lastmodBySlug.get(postSlug) : undefined,
        priority: blogPriority(pathname),
        changefreq: blogChangeFreq(pathname),
      } satisfies SitemapEntry
    })
    .sort((a, b) => a.path.localeCompare(b.path))
}
