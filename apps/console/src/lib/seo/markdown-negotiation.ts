import {
  APPWRITE_API_CATALOG_PATH,
  APPWRITE_OPENAPI_PATH,
} from '@/lib/seo/agent-discovery'

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
