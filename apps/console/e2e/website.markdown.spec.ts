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
  {
    name: 'pricing',
    path: '/pricing.md',
    contains: [/^# Appwrite Cloud pricing/, /### Pro: From \$25\/month/],
  },
  {
    name: 'marketing page twin',
    path: '/affiliates.md',
    contains: [
      /^---\ntitle: "Affiliates"\n/,
      /canonical: "https:\/\/appwrite\.io\/affiliates"/,
      /\n# Earn credits by referring developers\n/,
      /\]\(https:\/\/appwrite\.io\/sign-up/,
    ],
  },
  {
    name: 'product page twin',
    path: '/products/auth.md',
    contains: [/^---\n/, /\n# Authentication that ships with your product\n/],
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
    expect(body).toContain(
      'docs MCP server at https://mcp.appwrite.io/docs/mcp',
    )
    expect(body).toContain('## Skills')
    expect(body).toContain('## Documentation')
    expect(body).toContain('/docs/llms.txt')
    expect(body).toContain('/setup.md')
    expect(body).toContain('/blog/llms.txt')
    // Hub stays curated; it must not dump every docs page.
    expect(body.length).toBeLessThan(100_000)
  })

  test('homepage negotiates markdown and advertises agent links', async ({
    request,
  }) => {
    const markdown = await request.get('/', {
      headers: { Accept: 'text/markdown' },
    })
    expect(markdown.status()).toBe(200)
    expect(markdown.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)
    expect(markdown.headers()['vary']).toContain('Accept')
    expect(await markdown.text()).toMatch(/^---\ntitle: "Appwrite"\n[\s\S]*\n---\n\n# Appwrite/)

    const html = await request.get('/', { headers: { Accept: 'text/html' } })
    expect(html.headers()['content-type']).toMatch(/text\/html/)
    expect(html.headers()['vary']).toContain('Accept')
    expect(html.headers()['link']).toContain('rel="api-catalog"')

    const index = await request.get('/index.md')
    expect(index.status()).toBe(200)
    expect(index.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)
  })

  test('setup.md serves the setup and build guide', async ({ request }) => {
    const response = await request.get('/setup.md')
    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)
    const body = await response.text()
    expect(body).toMatch(/^# Set up Appwrite and build with it\n/)
    expect(body).toContain('https://mcp.appwrite.io')
    expect(body).toContain('/setup.md')
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

    for (const path of [
      '/.well-known/ard.json',
      '/.well-known/ai-catalog.json',
    ]) {
      const catalog = await request.get(path)
      expect(catalog.status()).toBe(200)
      expect(catalog.headers()['content-type']).toMatch(JSON_CONTENT_TYPE)
      expect(catalog.headers()['access-control-allow-origin']).toBe('*')
      const catalogBody = await catalog.json()
      expect(catalogBody.host?.displayName).toBe('Appwrite')
      const entry = catalogBody.entries?.[0]
      expect(entry?.identifier).toBe('urn:air:appwrite.io:mcp:appwrite')
      expect(entry?.displayName).toBeTruthy()
      expect(entry?.type).toBe('application/mcp-server-card+json')
      expect(entry?.url).toContain('/.well-known/mcp/server-card.json')
      expect(entry?.data).toBeUndefined()
      const trust = path.endsWith('ard.json')
        ? entry?.trustManifest?.identity
        : entry?.trustManifests?.[0]?.contributor
      expect(trust).toBe('https://appwrite.io')
      expect(catalogBody.entries?.[1]?.url).toContain(
        '/.well-known/mcp/docs/server-card.json',
      )
    }

    const docsCard = await request.get('/.well-known/mcp/docs/server-card.json')
    expect(docsCard.status()).toBe(200)
    const docsCardBody = await docsCard.json()
    expect(docsCardBody.name).toBe('io.appwrite/mcp-docs')
    expect(docsCardBody.remotes?.[0]?.url).toBe(
      'https://mcp.appwrite.io/docs/mcp',
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

  test('well-known security.txt lists the security contact', async ({
    request,
  }) => {
    const response = await request.get('/.well-known/security.txt')
    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(/text\/plain/)
    const body = await response.text()
    expect(body).toContain('Contact: mailto:security@appwrite.io')
    expect(body).toMatch(/^Expires: /m)
  })

  test('robots.txt blocks indexing on non-production hosts', async ({
    request,
  }) => {
    const response = await request.get('/robots.txt')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(/text\/plain/)
    const body = await response.text()
    expect(body).toContain('User-agent:')
    expect(body).toContain('Disallow: /')
    expect(body).not.toContain('Sitemap:')
  })

  test('robots.txt serves production policy on the canonical host', async ({
    request,
  }) => {
    const response = await request.get('/robots.txt', {
      headers: { Host: 'appwrite.io' },
    })

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(/text\/plain/)
    const body = await response.text()
    expect(body).toContain('User-agent:')
    expect(body).toContain('Sitemap:')
    expect(body).toContain('https://appwrite.io/sitemap/news.xml')
    expect(body).toContain('Schemamap: https://appwrite.io/schemamap.xml')

    const schemamap = await request.get('/schemamap.xml')
    expect(schemamap.status()).toBe(200)
    expect(await schemamap.text()).toContain(
      '<sf:contentType>structuredData/rss</sf:contentType>',
    )
  })

  test('NLWeb /ask answers from the docs search index', async ({ request }) => {
    const answer = await request.post('/ask', {
      data: { query: { text: 'oauth login' } },
    })
    expect(answer.status()).toBe(200)
    const body = await answer.json()
    expect(body._meta).toMatchObject({
      response_type: 'answer',
      version: '0.55',
    })
    expect(body.results[0]).toMatchObject({ '@type': 'TechArticle' })
    expect(body.results[0].url).toContain('/docs/')

    const streamed = await request.post('/ask', {
      data: { query: { text: 'deploy function' }, prefer: { streaming: true } },
    })
    expect(streamed.headers()['content-type']).toMatch(/text\/event-stream/)
    const events = await streamed.text()
    expect(events).toMatch(/^event: start\n/)
    expect(events).toContain('event: result\n')
    expect(events).toContain('event: complete\n')

    const empty = await request.post('/ask', { data: { query: { text: '' } } })
    expect((await empty.json()).error.code).toBe('INVALID_QUERY')

    const oversized = await request.post('/ask', {
      data: { query: { text: 'auth '.repeat(101) } },
    })
    expect((await oversized.json()).error.code).toBe('INVALID_QUERY')
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
    expect(body).toContain('<url>')
    expect(body).toContain('<loc>')
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

  test('404 asked for as markdown gets a markdown body', async ({ request }) => {
    const response = await request.get('/this-page-does-not-exist', {
      headers: { Accept: 'text/markdown' },
    })
    expect(response.status()).toBe(404)
    expect(response.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)
    const body = await response.text()
    expect(body).toMatch(/^# Page not found\n/)
    expect(body).toContain('/llms.txt')
  })

  test('auth.md explains how agents get credentials', async ({ request }) => {
    const response = await request.get('/auth.md')
    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)
    const body = await response.text()
    expect(body).toMatch(/^# Appwrite auth\.md\n/)
    for (const section of [
      '## Step 1 — Discover',
      '## Step 2 — Pick a method',
      '## Step 3 — Register',
      '## Step 5 — Exchange',
      '## Step 6 — Use the access_token',
      '## Errors',
      '## Revocation',
    ]) {
      expect(body, section).toContain(section)
    }
    expect(body).toContain(
      'https://mcp.appwrite.io/.well-known/oauth-protected-resource',
    )
    expect(body).toContain('code_challenge_method=S256')
  })

  test('AI agents get markdown when they ask for HTML', async ({ request }) => {
    for (const [userAgent, path, heading] of [
      ['Mozilla/5.0 (compatible; ClaudeBot/1.0)', '/', /^---\ntitle: "Appwrite"/],
      ['Mozilla/5.0 (compatible; ChatGPT-User/1.0)', '/company', /^---\ntitle: /],
    ] as const) {
      const response = await request.get(path, {
        headers: { 'User-Agent': userAgent, Accept: 'text/html' },
      })
      expect(response.status(), path).toBe(200)
      expect(response.headers()['content-type'], path).toMatch(
        MARKDOWN_CONTENT_TYPE,
      )
      expect(response.headers()['vary'], path).toContain('User-Agent')
      expect(await response.text(), path).toMatch(heading)
    }

    const browser = await request.get('/company', {
      headers: {
        'User-Agent':
          'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36',
        Accept: 'text/html',
      },
    })
    expect(browser.headers()['content-type']).toMatch(/text\/html/)
  })

  test('openapi.json declares OAuth 2.0 with named scopes', async ({
    request,
  }) => {
    const response = await request.get('/openapi.json')
    expect(response.status()).toBe(200)
    const spec = await response.json()
    const oauth2 = spec.components.securitySchemes.OAuth2
    expect(oauth2.type).toBe('oauth2')
    expect(oauth2.flows.authorizationCode.authorizationUrl).toMatch(
      /\/oauth2\/console\/authorize$/,
    )
    expect(oauth2.flows.authorizationCode.scopes['project:rows.read']).toBe(
      'Read rows in a project.',
    )
    const listRows = Object.values(spec.paths)
      .flatMap((path) => Object.values(path as Record<string, unknown>))
      .find(
        (operation) =>
          (operation as { operationId?: string }).operationId ===
          'tablesDBListRows',
      ) as { security: Array<Record<string, string[]>> }
    expect(listRows.security).toContainEqual({
      Project: [],
      OAuth2: ['project:rows.read'],
    })
  })

  test('blog llms.txt lists posts', async ({ request }) => {
    const response = await request.get('/blog/llms.txt')
    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)
    const body = await response.text()
    expect(body).toMatch(/^# Appwrite Blog\n/)
    expect(body).toMatch(/\(https?:\/\/[^\s)]+\/blog\/post\/[^\s)]+\.md\)/)
  })
})
