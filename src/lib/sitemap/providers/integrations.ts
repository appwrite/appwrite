import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { getFrontmatterDate, parseIntegrationFrontmatter } from '@/lib/integrations/frontmatter'
import type { SitemapChangeFreq, SitemapEntry } from '../types'

const packageRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../../..',
)

const integrationsDirectory = path.join(
  packageRoot,
  'src/content/integrations',
)

function toIsoDate(value: string | undefined): string | undefined {
  if (!value) return undefined
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return undefined
  return parsed.toISOString().slice(0, 10)
}

function readIntegrationLastmodBySlug(): Map<string, string> {
  const lastmodBySlug = new Map<string, string>()
  if (!fs.existsSync(integrationsDirectory)) return lastmodBySlug

  for (const filename of fs.readdirSync(integrationsDirectory)) {
    if (!filename.endsWith('.markdoc')) continue
    const slug = filename.replace(/\.markdoc$/, '')
    const raw = fs.readFileSync(path.join(integrationsDirectory, filename), 'utf8')
    const { frontmatter } = parseIntegrationFrontmatter(raw)
    const lastmod = toIsoDate(getFrontmatterDate(frontmatter, 'date'))
    if (lastmod) lastmodBySlug.set(slug, lastmod)
  }

  return lastmodBySlug
}

export function getIntegrationsSitemapEntries(): SitemapEntry[] {
  const lastmodBySlug = readIntegrationLastmodBySlug()
  const slugs = [...lastmodBySlug.keys()].sort()

  const entries: SitemapEntry[] = [
    {
      path: '/integrations',
      priority: 0.8,
      changefreq: 'weekly',
    },
    ...slugs.map((slug) => ({
      path: `/integrations/${slug}`,
      lastmod: lastmodBySlug.get(slug),
      priority: 0.7,
      changefreq: 'monthly' as SitemapChangeFreq,
    })),
  ]

  return entries.sort((a, b) => a.path.localeCompare(b.path))
}
