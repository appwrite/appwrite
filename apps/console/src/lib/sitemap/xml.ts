import type { SitemapEntry, SitemapSection } from './types'

export function escapeXml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}

function formatPriority(priority: number | undefined): string | null {
  if (priority == null) return null
  const clamped = Math.min(1, Math.max(0, priority))
  return clamped.toFixed(1)
}

function renderUrlEntry(origin: string, entry: SitemapEntry): string {
  const loc = `${origin}${entry.path === '/' ? '' : entry.path}`
  const lines = [`    <loc>${escapeXml(loc)}</loc>`]

  if (entry.lastmod) {
    lines.push(`    <lastmod>${escapeXml(entry.lastmod)}</lastmod>`)
  }
  if (entry.changefreq) {
    lines.push(`    <changefreq>${entry.changefreq}</changefreq>`)
  }

  const priority = formatPriority(entry.priority)
  if (priority) {
    lines.push(`    <priority>${priority}</priority>`)
  }

  return `  <url>\n${lines.join('\n')}\n  </url>`
}

export function renderUrlsetXml(origin: string, entries: SitemapEntry[]): string {
  const body = entries.map((entry) => renderUrlEntry(origin, entry)).join('\n')

  return `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${body}
</urlset>
`
}

export function renderSitemapIndexXml(
  origin: string,
  sections: SitemapSection[],
  lastmod?: string,
): string {
  const body = sections
    .map((section) => {
      const loc = `${origin}/sitemap/${section.id}.xml`
      const lines = [`    <loc>${escapeXml(loc)}</loc>`]
      if (lastmod) {
        lines.push(`    <lastmod>${escapeXml(lastmod)}</lastmod>`)
      }
      return `  <sitemap>\n${lines.join('\n')}\n  </sitemap>`
    })
    .join('\n')

  return `<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${body}
</sitemapindex>
`
}
