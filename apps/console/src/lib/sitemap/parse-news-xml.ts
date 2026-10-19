import {
  NEWS_SITEMAP_MAX_URLS,
  NEWS_SITEMAP_XMLNS,
  SITEMAP_XMLNS,
  isW3cNewsPublicationDate,
  type NewsSitemapEntry,
} from './news'

export type ParsedNewsSitemap = {
  sitemapXmlns: string
  newsXmlns: string
  entries: NewsSitemapEntry[]
}

function requireMatch(xml: string, pattern: RegExp, label: string): RegExpMatchArray {
  const match = xml.match(pattern)
  if (!match) {
    throw new Error(`News sitemap is missing ${label}`)
  }
  return match
}

function decodeXml(value: string): string {
  return value
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'")
    .replace(/&amp;/g, '&')
}

function innerText(block: string, tag: string): string {
  const match = block.match(
    new RegExp(`<${tag}>([\\s\\S]*?)</${tag}>`),
  )
  if (!match?.[1]) {
    throw new Error(`News sitemap URL is missing <${tag}>`)
  }
  return decodeXml(match[1].trim())
}

/**
 * Parse and validate a Google News sitemap. Rejects documents that are not
 * well-formed enough to extract required `news:` tags, or that violate
 * Google's news sitemap constraints (namespaces, required fields, 1,000 cap).
 */
export function parseNewsSitemapXml(xml: string): ParsedNewsSitemap {
  if (xml.includes('<parsererror')) {
    throw new Error('News sitemap contains an XML parser error')
  }

  requireMatch(xml, /^<\?xml version="1.0" encoding="UTF-8"\?>/, 'XML declaration')

  const urlsetOpen = requireMatch(
    xml,
    /<urlset\b([^>]*)>/,
    '<urlset>',
  )[1]

  const sitemapXmlns =
    urlsetOpen.match(/\bxmlns="([^"]+)"/)?.[1] ??
    urlsetOpen.match(/\bxmlns:sitemap="([^"]+)"/)?.[1]
  const newsXmlns = urlsetOpen.match(/\bxmlns:news="([^"]+)"/)?.[1]

  if (sitemapXmlns !== SITEMAP_XMLNS) {
    throw new Error(
      `News sitemap urlset xmlns must be ${SITEMAP_XMLNS}`,
    )
  }
  if (newsXmlns !== NEWS_SITEMAP_XMLNS) {
    throw new Error(
      `News sitemap must declare xmlns:news="${NEWS_SITEMAP_XMLNS}"`,
    )
  }

  if (!xml.trimEnd().endsWith('</urlset>')) {
    throw new Error('News sitemap is missing a closing </urlset>')
  }

  const urlBlocks = [...xml.matchAll(/<url>([\s\S]*?)<\/url>/g)].map(
    (match) => match[1] ?? '',
  )

  if (urlBlocks.length > NEWS_SITEMAP_MAX_URLS) {
    throw new Error(
      `News sitemap exceeds ${NEWS_SITEMAP_MAX_URLS} URLs (${urlBlocks.length})`,
    )
  }

  const entries = urlBlocks.map((block) => {
    if ((block.match(/<news:news>/g) ?? []).length !== 1) {
      throw new Error('Each news sitemap <url> must contain exactly one <news:news> block')
    }

    const loc = innerText(block, 'loc')
    if (!/^https?:\/\//.test(loc)) {
      throw new Error(`News sitemap loc is not an absolute URL: ${loc}`)
    }

    const name = innerText(block, 'news:name')
    const language = innerText(block, 'news:language')
    const publicationDate = innerText(block, 'news:publication_date')
    const title = innerText(block, 'news:title')

    if (!name) {
      throw new Error('news:name must not be empty')
    }
    if (!/^[a-z]{2,3}(?:-[a-z]{2})?$/i.test(language)) {
      throw new Error(`news:language is not an ISO 639 code: ${language}`)
    }
    if (!isW3cNewsPublicationDate(publicationDate)) {
      throw new Error(
        `news:publication_date is not a W3C date: ${publicationDate}`,
      )
    }
    if (!title) {
      throw new Error('news:title must not be empty')
    }

    return {
      loc,
      title,
      publicationDate,
      publicationName: name,
      language,
    } satisfies NewsSitemapEntry
  })

  return {
    sitemapXmlns,
    newsXmlns,
    entries,
  }
}
