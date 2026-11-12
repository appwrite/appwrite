import { expect, test } from './fixtures'
import {
  WEBMCP_CHANNEL,
  callTool,
  getTools,
  installWebMcpRegistry,
  waitForTools,
} from './helpers/webmcp'

/**
 * WebMCP tools on appwrite.io, called the way a browser agent calls them:
 * getTools() to discover, executeTool() to run. Runs against Chrome's native
 * API with E2E_WEBMCP_CHANNEL=chrome, otherwise against a spec-shaped registry.
 */
test.use({
  channel: WEBMCP_CHANNEL,
  launchOptions: { args: ['--enable-features=WebMCP'] },
})

const READ_ONLY_TOOLS = [
  'search_docs',
  'get_doc_page',
  'get_pricing',
  'get_product_overview',
  'list_changelog',
  'search_community_threads',
]
const NAVIGATION_TOOLS = ['navigate_to_page', 'open_sign_up']

type ToolError = { error: string; code: string; retryable: boolean }
type MarkdownPage = { url: string; markdown: string; truncated: boolean }

test.describe('WebMCP tools', () => {
  test.beforeEach(async ({ page }) => {
    await installWebMcpRegistry(page)
    await page.goto('/home')
    await waitForTools(page, READ_ONLY_TOOLS.length + NAVIGATION_TOOLS.length)
  })

  test('registers described, annotated tools', async ({ page }) => {
    const tools = await getTools(page)
    const byName = new Map(tools.map((tool) => [tool.name, tool]))

    expect([...byName.keys()].sort()).toEqual(
      [...READ_ONLY_TOOLS, ...NAVIGATION_TOOLS].sort(),
    )
    for (const tool of tools) {
      expect(tool.name, tool.name).toMatch(/^[a-z]+(_[a-z]+)+$/)
      expect(tool.title, tool.name).not.toBe('')
      expect(tool.description.length, tool.name).toBeGreaterThan(30)
      expect(tool.description.length, tool.name).toBeLessThan(200)
      expect(tool.inputSchema.type, tool.name).toBe('object')
      for (const [key, property] of Object.entries(
        tool.inputSchema.properties,
      )) {
        expect(property.description, `${tool.name}.${key}`).toBeTruthy()
      }
      expect(tool.annotations.consequentialHint, tool.name).toBe(false)
      expect(tool.annotations.debugging, tool.name).toBe(false)
    }
    for (const name of READ_ONLY_TOOLS) {
      expect(byName.get(name)?.annotations.readOnlyHint, name).toBe(true)
    }
    for (const name of NAVIGATION_TOOLS) {
      expect(byName.get(name)?.annotations.readOnlyHint, name).toBe(false)
    }
    expect(
      tools
        .filter((tool) => tool.annotations.untrustedContentHint)
        .map((tool) => tool.name),
    ).toEqual(['search_community_threads'])
  })

  test('search_docs returns matching docs pages', async ({ page }) => {
    const result = await callTool<{
      results: Array<{ title: string; url: string; markdownUrl: string }>
    }>(page, 'search_docs', { query: 'create a row', limit: 3 })

    expect(result.results.length).toBeGreaterThan(0)
    expect(result.results.length).toBeLessThanOrEqual(3)
    for (const entry of result.results) {
      expect(entry.url).toMatch(/\/docs\//)
      expect(entry.markdownUrl).toBe(`${entry.url}.md`)
    }
  })

  test('get_doc_page returns docs Markdown and rejects other paths', async ({
    page,
  }) => {
    const doc = await callTool<MarkdownPage>(page, 'get_doc_page', {
      path: '/docs/quick-starts/react',
    })
    expect(doc.url).toMatch(/\/docs\/quick-starts\/react\.md$/)
    expect(doc.markdown).toContain('title: Start with React')

    const outside = await callTool<ToolError>(page, 'get_doc_page', {
      path: '/blog/post/appwrite-realtime',
    })
    expect(outside).toEqual({
      error: expect.any(String),
      code: 'invalid_path',
      retryable: false,
    })

    const missing = await callTool<ToolError>(page, 'get_doc_page', {
      path: '/docs/this-page-does-not-exist',
    })
    expect(missing.code).toBe('not_found')
  })

  test('get_pricing and get_product_overview return Markdown', async ({
    page,
  }) => {
    const pricing = await callTool<MarkdownPage>(page, 'get_pricing')
    expect(pricing.markdown).toMatch(/^# Appwrite Cloud pricing/)

    const auth = await callTool<
      MarkdownPage & { name: string; docsUrl: string }
    >(page, 'get_product_overview', { product: 'auth' })
    expect(auth.name).toBe('Auth')
    expect(auth.docsUrl).toMatch(/\/docs\/products\/auth$/)
    expect(auth.markdown).toMatch(/^---\ntitle: "Auth"/)
  })

  test('list_changelog returns the newest entries', async ({ page }) => {
    const { entries } = await callTool<{
      entries: Array<{ title: string; url: string; markdownUrl: string }>
    }>(page, 'list_changelog', { limit: 3 })

    expect(entries).toHaveLength(3)
    for (const entry of entries) {
      expect(entry.title).not.toBe('')
      expect(entry.url).toMatch(/\/changelog\/entry\//)
    }
  })

  test('search_community_threads returns threads or a retryable error', async ({
    page,
  }) => {
    const result = await callTool<
      { threads: Array<{ title: string; url: string }> } | ToolError
    >(page, 'search_community_threads', { query: 'cors', limit: 2 })

    if ('code' in result) {
      expect(result).toMatchObject({ code: 'search_failed', retryable: true })
      return
    }
    expect(result.threads.length).toBeLessThanOrEqual(2)
    for (const thread of result.threads) {
      expect(thread.url).toMatch(/\/threads\//)
    }
  })

  test('navigate_to_page and open_sign_up move the tab on this site only', async ({
    page,
  }) => {
    const offsite = await callTool<ToolError>(page, 'navigate_to_page', {
      path: 'https://example.com',
    })
    expect(offsite.code).toBe('invalid_path')
    const protocolRelative = await callTool<ToolError>(
      page,
      'navigate_to_page',
      {
        path: '//example.com/docs',
      },
    )
    expect(protocolRelative.code).toBe('invalid_path')
    await expect(page).toHaveURL(/\/home$/)

    const pricing = await callTool<{ navigated: boolean }>(
      page,
      'navigate_to_page',
      {
        path: '/pricing',
      },
    )
    expect(pricing.navigated).toBe(true)
    await expect(page).toHaveURL(/\/pricing$/)
    await expect(page).toHaveTitle(/Pricing/)

    await callTool(page, 'open_sign_up')
    await expect(page).toHaveURL(/\/sign-up/)
  })
})
