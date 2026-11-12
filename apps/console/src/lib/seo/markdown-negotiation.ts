import {
  APPWRITE_API_CATALOG_PATH,
  APPWRITE_OPENAPI_PATH,
} from '@/lib/seo/agent-discovery'
import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { APPWRITE_DESCRIPTION } from '@/lib/seo/organization'

/** RFC 8288 links advertised on the homepage for agents. */
export const HOME_LINK_HEADER = [
  '</llms.txt>; rel="alternate"; type="text/markdown"',
  '</sitemap.xml>; rel="sitemap"; type="application/xml"',
  `<${APPWRITE_API_CATALOG_PATH}>; rel="api-catalog"`,
  `<${APPWRITE_OPENAPI_PATH}>; rel="service-desc"; type="application/json"`,
].join(', ')

function quality(accept: string, type: string): number {
  let best = 0
  for (const range of accept.split(',')) {
    const [media, ...params] = range.split(';').map((part) => part.trim())
    if (media !== type) continue
    const q = params.find((param) => param.startsWith('q='))
    best = Math.max(best, q ? Number.parseFloat(q.slice(2)) || 0 : 1)
  }
  return best
}

/** True when the client ranks text/markdown above text/html (RFC 9110 §12.5.1). */
export function prefersMarkdown(accept: string | null): boolean {
  if (!accept) return false
  const markdown = quality(accept, 'text/markdown')
  return markdown > 0 && markdown > quality(accept, 'text/html')
}

/** YAML frontmatter block; values are JSON strings, which YAML reads as double-quoted scalars. */
export function markdownFrontmatter(fields: Record<string, string>): string {
  const lines = Object.entries(fields).map(
    ([key, value]) => `${key}: ${JSON.stringify(value)}`,
  )
  return ['---', ...lines, '---', '', ''].join('\n')
}

/** Metadata for the homepage's markdown twin (`/index.md`, or `/` asking for markdown). */
export const HOME_MARKDOWN_FRONTMATTER = markdownFrontmatter({
  title: 'Appwrite',
  description: APPWRITE_DESCRIPTION,
  canonical: `${MARKETING_SITE_ORIGIN}/`,
})

/** Body of a 404 for clients that asked for markdown. */
export function notFoundMarkdown(pathname: string): string {
  return [
    '# Page not found',
    '',
    `Nothing exists at \`${pathname}\` on appwrite.io.`,
    '',
    `- [Site index for agents](${MARKETING_SITE_ORIGIN}/llms.txt)`,
    `- [Documentation](${MARKETING_SITE_ORIGIN}/docs.md)`,
    `- [Sitemap](${MARKETING_SITE_ORIGIN}/sitemap.xml)`,
    '',
  ].join('\n')
}
