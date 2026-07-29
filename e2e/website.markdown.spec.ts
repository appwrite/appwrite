import { expect, test } from '@playwright/test'
import {
  WEBSITE_ACCESS_COOKIE_NAME,
} from '../src/lib/website-access'

/**
 * Markdown / LLM text exports (llms.txt standard). Plain HTTP endpoints, no
 * browser rendering. One representative page per unique export type; each
 * type has its own content pipeline (docs splat, blog post, changelog entry,
 * integration, llms indexes), so one page per type covers the route handler
 * and the Markdoc-to-Markdown transform for that content source.
 */

/** Soft-launch gate accepts any non-empty cookie value (see src/lib/website-access). */
const ACCESS_COOKIE = `${WEBSITE_ACCESS_COOKIE_NAME}=1`

const MARKDOWN_CONTENT_TYPE = /^text\/markdown/

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
      const response = await request.get(pageDef.path, {
        headers: { Cookie: ACCESS_COOKIE },
      })

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

  test('llms.txt index serves markdown link index', async ({ request }) => {
    const response = await request.get('/llms.txt', {
      headers: { Cookie: ACCESS_COOKIE },
    })

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)

    const body = await response.text()
    expect(body).toMatch(/^# Appwrite\n/)
    expect(body).toContain('## Docs')
    expect(body).toContain('## Blog')
    // Index links point at .md variants so LLMs land on markdown pages.
    expect(body).toMatch(/\(https?:\/\/[^\s)]+\.md\)/)
  })

  test('llms-full.txt serves aggregated docs markdown', async ({ request }) => {
    const response = await request.get('/llms-full.txt', {
      headers: { Cookie: ACCESS_COOKIE },
    })

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(MARKDOWN_CONTENT_TYPE)

    const body = await response.text()
    expect(body).toMatch(/URL: https?:\/\/[^\s]+\/docs\//)
    // Full export aggregates all docs; a tiny body means generation broke.
    expect(body.length).toBeGreaterThan(100_000)
  })

  test('missing markdown page returns 404', async ({ request }) => {
    const response = await request.get('/blog/post/this-post-does-not-exist.md', {
      headers: { Cookie: ACCESS_COOKIE },
    })
    expect(response.status()).toBe(404)
  })
})
