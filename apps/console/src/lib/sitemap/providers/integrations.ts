import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { getFrontmatterDate, parseIntegrationFrontmatter } from '@/lib/integrations/frontmatter'
import { getIntegrationPrerenderPaths } from '@/lib/integrations/prerender-paths'
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

function integrationsPriority(pathname: string): number {
  return pathname === '/integrations' ? 0.8 : 0.7
}

function integrationsChangeFreq(pathname: string): SitemapChangeFreq {
  return pathname === '/integrations' ? 'weekly' : 'monthly'
}

export function getIntegrationsSitemapEntries(): SitemapEntry[] {
  const lastmodBySlug = readIntegrationLastmodBySlug()

  return getIntegrationPrerenderPaths()
    .map((pathname) => {
      const slug = pathname.match(/^\/integrations\/(.+)$/)?.[1]
      return {
        path: pathname,
        lastmod: slug ? lastmodBySlug.get(slug) : undefined,
        priority: integrationsPriority(pathname),
        changefreq: integrationsChangeFreq(pathname),
      } satisfies SitemapEntry
    })
    .sort((a, b) => a.path.localeCompare(b.path))
}
