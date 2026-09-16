import { expect, test } from './fixtures'

/**
 * Markdown / LLM text exports (llms.txt standard). Plain HTTP endpoints, no
 * browser rendering. One representative page per unique export type; each
 * type has its own content pipeline (docs splat, blog post, changelog entry,
 * integration, llms indexes), so one page per type covers the route handler
 * and the Markdoc-to-Markdown transform for that content source.
 */

const MARKDOWN_CONTENT_TYPE = /^text\/markdown/
const JSON_CONTENT_TYPE = /application\/json/

/** Leftover custom Markdoc tags would mean the export transform regressed. */
const MARKDOC_TAG = /\{%\s*\/?\w+/

const MARKDOWN_PAGES: Array<{
  name: string
  path: string
  /** Patterns that must appear in the response body. */
  contains: RegExp[]
}> = [
  {
    name: 'docs page',
    path: '/docs/quick-starts/react.md',
    contains: [/^---\n/, /title: Start with React/],
  },
  {
    name: 'blog post',
    path: '/blog/post/appwrite-realtime.md',
    contains: [/^---\n/, /layout: post/],
  },
  {
    name: 'changelog entry',
    path: '/changelog/entry/2023-08-30.md',
    contains: [/^---\n/, /layout: changelog/],
  },
  {
    name: 'integration',
    path: '/integrations/ai-openai.md',
    contains: [/^---\n/, /layout: integration/],
  },
]

test.describe('markdown exports (read-only)', () => {
  for (const pageDef of MARKDOWN_PAGES) {
    test(`${pageDef.name} serves markdown`, async ({ request }) => {
      const response = await request.get(pageDef.path)

      expect(response.status(), `Unexpected HTTP for ${pageDef.path}`).toBe(200)
      expect(response.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)

      const body = await response.text()
      for (const pattern of pageDef.contains) {
        expect(body, `Missing ${pattern} in ${pageDef.path}`).toMatch(pattern)
      }
      expect(
        body,
        `Leftover Markdoc tag in ${pageDef.path}`,
      ).not.toMatch(MARKDOC_TAG)
    })
  }

  test('llms.txt hub serves curated agent index', async ({ request }) => {
    const response = await request.get('/llms.txt')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)

    const body = await response.text()
    expect(body).toMatch(/^# Appwrite\n/)
    expect(body).toContain('## MCP Server')
    expect(body).toContain('## Skills')
    expect(body).toContain('## Documentation')
    expect(body).toContain('/docs/llms.txt')
    expect(body).toContain('/blog.md')
    // Hub stays curated; it must not dump every docs page.
    expect(body.length).toBeLessThan(100_000)
  })

  test('docs/llms.txt serves nested docs index', async ({ request }) => {
    const response = await request.get('/docs/llms.txt')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)

    const body = await response.text()
    expect(body).toMatch(/^# Appwrite Docs\n/)
    expect(body).toMatch(/\(https?:\/\/[^\s)]+\.md\)/)
    expect(body.length).toBeGreaterThan(10_000)
  })

  test('section markdown indexes list content links', async ({ request }) => {
    for (const path of ['/docs.md', '/blog.md', '/changelog.md', '/integrations.md']) {
      const response = await request.get(path)
      expect(response.status(), path).toBe(200)
      expect(response.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)
      const body = await response.text()
      expect(body, path).toMatch(/^# Appwrite /)
      expect(body, path).toMatch(/\(https?:\/\/[^\s)]+\.md\)/)
    }
  })

  test('well-known discovery documents serve MCP Server Card + AI Catalog', async ({
    request,
  }) => {
    const mcp = await request.get('/.well-known/mcp/server-card.json')
    expect(mcp.status()).toBe(200)
    expect(mcp.headers()['content-type']).toMatch(
      /application\/(mcp-server-card\+json|json)/,
    )
    expect(mcp.headers()['access-control-allow-origin']).toBe('*')
    const mcpBody = await mcp.json()
    expect(mcpBody.$schema).toContain('server-card.schema.json')
    expect(mcpBody.name).toBe('io.appwrite/mcp')
    expect(mcpBody.remotes?.[0]?.url).toContain('mcp.appwrite.io')

    const catalog = await request.get('/.well-known/ai-catalog.json')
    expect(catalog.status()).toBe(200)
    expect(catalog.headers()['content-type']).toMatch(
      /application\/(ai-catalog\+json|json)/,
    )
    const catalogBody = await catalog.json()
    expect(catalogBody.entries?.[0]?.type).toBe(
      'application/mcp-server-card+json',
    )
    expect(catalogBody.entries?.[0]?.url).toContain(
      '/.well-known/mcp/server-card.json',
    )

    const skills = await request.get('/.well-known/agent-skills/index.json')
    expect(skills.status()).toBe(200)
    expect(skills.headers()['content-type']).toMatch(JSON_CONTENT_TYPE)
    const skillsBody = await skills.json()
    expect(Array.isArray(skillsBody.skills)).toBe(true)
    expect(skillsBody.skills.length).toBeGreaterThan(0)
  })

  test('well-known change-password redirects to account security', async ({
    request,
  }) => {
    const response = await request.get('/.well-known/change-password', {
      maxRedirects: 0,
    })
    expect(response.status()).toBe(302)
    expect(response.headers()['location']).toMatch(/\/account\/security/)

    const reliability = await request.get(
      '/.well-known/resource-that-should-not-exist-whose-status-code-should-not-be-200',
    )
    expect(reliability.status()).toBe(404)
  })

  test('robots.txt serves plain text with a tracked route', async ({ request }) => {
    const response = await request.get('/robots.txt')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(/text\/plain/)
    const body = await response.text()
    expect(body).toContain('User-agent:')
    expect(body).toContain('Sitemap:')
    expect(body).toContain('https://appwrite.io/sitemap/news.xml')
  })

  test('sitemap index lists section sitemaps', async ({ request }) => {
    const response = await request.get('/sitemap.xml')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(/xml/)
    const body = await response.text()
    expect(body).toContain('<sitemapindex')
    expect(body).toContain('https://appwrite.io/sitemap/pages.xml')
    expect(body).toContain('https://appwrite.io/sitemap/docs.xml')
    expect(body).toContain('https://appwrite.io/sitemap/news.xml')
  })

  test('pages sitemap is a urlset', async ({ request }) => {
    const response = await request.get('/sitemap/pages.xml')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(/xml/)
    const body = await response.text()
    expect(body).toContain('<urlset')
    expect(body).toContain('https://appwrite.io/pricing')
  })

  test('Google News sitemap is valid XML with news tags', async ({ request }) => {
    const response = await request.get('/sitemap/news.xml')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(/xml/)
    const body = await response.text()
    expect(body).toContain('xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"')
    expect(body).toContain('<urlset')
    expect(body).toContain('</urlset>')
  })

  test('llms-full.txt serves aggregated docs markdown', async ({ request }) => {
    const response = await request.get('/llms-full.txt')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)

    const body = await response.text()
    expect(body).toMatch(/URL: https?:\/\/[^\s]+\/docs\//)
    // Full export aggregates all docs; a tiny body means generation broke.
    expect(body.length).toBeGreaterThan(100_000)
  })

  test('missing markdown page returns 404', async ({ request }) => {
    const response = await request.get('/blog/post/this-post-does-not-exist.md')
    expect(response.status()).toBe(404)
  })
})
