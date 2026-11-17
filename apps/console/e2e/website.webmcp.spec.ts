import type { ToolError } from '../src/lib/webmcp/calls'
import type { Page } from '@playwright/test'
import { RUNTIME_CONFIG_WINDOW_KEY } from '../src/lib/runtime-config-shared'
import { expect, test } from './fixtures'
import { WEBMCP_CHANNEL, openAsAgent, type WebMcpAgent } from './helpers/webmcp'

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
const NAVIGATION_TOOLS = ['navigate_to_page', 'navigate_to_sign_up']

type MarkdownPage = { url: string; markdown: string; truncated: boolean }

/** Turns analytics on with a Plausible stub that records calls instead of sending them. */
async function captureAnalytics(page: Page): Promise<void> {
  await page.route('https://plausible.test/**', (route) =>
    route.fulfill({ contentType: 'text/javascript', body: '' }),
  )
  await page.addInitScript((key) => {
    const events: Array<[string, Record<string, unknown> | undefined]> = []
    let config: Record<string, unknown> | undefined
    Object.defineProperty(window, key, {
      configurable: true,
      get: () => config,
      set: (value: Record<string, unknown>) => {
        config = {
          ...value,
          plausibleScriptSrc: 'https://plausible.test/js/script.js',
        }
      },
    })
    Object.assign(window, {
      __analytics: events,
      plausible: (
        name: string,
        options?: { props?: Record<string, unknown> },
      ) => events.push([name, options?.props]),
    })
  }, RUNTIME_CONFIG_WINDOW_KEY)
}

test.describe('WebMCP tools', () => {
  let agent: WebMcpAgent

  test.beforeEach(async ({ page }) => {
    await captureAnalytics(page)
    agent = await openAsAgent(
      page,
      '/home',
      READ_ONLY_TOOLS.length + NAVIGATION_TOOLS.length,
    )
  })

  test('registers described, annotated tools', async () => {
    const tools = await agent.tools()
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

  test('search_docs returns matching docs pages', async () => {
    const result = await agent.call<{
      results: Array<{ title: string; url: string; markdownUrl: string }>
    }>('search_docs', { query: 'create a row', limit: 3 })

    expect(result.results.length).toBeGreaterThan(0)
    expect(result.results.length).toBeLessThanOrEqual(3)
    for (const entry of result.results) {
      expect(entry.url).toMatch(/\/docs\//)
      expect(entry.markdownUrl).toBe(`${entry.url}.md`)
    }
  })

  test('get_doc_page returns docs Markdown and rejects other paths', async () => {
    const doc = await agent.call<MarkdownPage>('get_doc_page', {
      path: '/docs/quick-starts/react',
    })
    expect(doc.url).toMatch(/\/docs\/quick-starts\/react\.md$/)
    expect(doc.markdown).toContain('title: Start with React')

    const outside = await agent.call<ToolError>('get_doc_page', {
      path: '/blog/post/appwrite-realtime',
    })
    expect(outside).toEqual({
      error: expect.any(String),
      code: 'invalid_path',
      retryable: false,
    })

    const missing = await agent.call<ToolError>('get_doc_page', {
      path: '/docs/this-page-does-not-exist',
    })
    expect(missing.code).toBe('not_found')
  })

  test('get_pricing and get_product_overview return Markdown', async () => {
    const pricing = await agent.call<MarkdownPage>('get_pricing')
    expect(pricing.markdown).toMatch(/^# Appwrite Cloud pricing/)

    const auth = await agent.call<
      MarkdownPage & { name: string; docsUrl: string }
    >('get_product_overview', { product: 'auth' })
    expect(auth.name).toBe('Auth')
    expect(auth.docsUrl).toMatch(/\/docs\/products\/auth$/)
    expect(auth.markdown).toMatch(/^---\ntitle: "Auth"/)
  })

  test('list_changelog returns the newest entries', async () => {
    const { entries } = await agent.call<{
      entries: Array<{ title: string; url: string; markdownUrl: string }>
    }>('list_changelog', { limit: 3 })

    expect(entries).toHaveLength(3)
    for (const entry of entries) {
      expect(entry.title).not.toBe('')
      expect(entry.url).toMatch(/\/changelog\/entry\//)
    }
  })

  test('search_community_threads returns threads or a retryable error', async () => {
    const result = await agent.call<
      { threads: Array<{ title: string; url: string }> } | ToolError
    >('search_community_threads', { query: 'cors', limit: 2 })

    if ('code' in result) {
      expect(result).toMatchObject({ code: 'search_failed', retryable: true })
      return
    }
    expect(result.threads.length).toBeLessThanOrEqual(2)
    for (const thread of result.threads) {
      expect(thread.url).toMatch(/\/threads\//)
    }
  })

  test('records each call as an analytics event, without its input', async ({
    page,
  }) => {
    const inputs = ['create a row', '/blog/post/appwrite-realtime']
    await agent.call('search_docs', { query: inputs[0] })
    await agent.call('get_doc_page', { path: inputs[1] })

    const events = () =>
      page.evaluate(() =>
        (
          window as unknown as {
            __analytics: Array<[string, Record<string, unknown>]>
          }
        ).__analytics
          .filter(([name]) => name === 'WebMCP Tool Called')
          .map(([, props]) => props),
      )
    await expect
      .poll(async () =>
        (await events()).map(({ tool, status, code }) => ({
          tool,
          status,
          code,
        })),
      )
      .toEqual([
        { tool: 'search_docs', status: 'success' },
        { tool: 'get_doc_page', status: 'error', code: 'invalid_path' },
      ])
    const sent = JSON.stringify(await events())
    for (const input of inputs) {
      expect(sent, input).not.toContain(input)
    }
  })

  test('navigate_to_page and navigate_to_sign_up move the tab on this site only', async ({
    page,
  }) => {
    const offsite = await agent.call<ToolError>('navigate_to_page', {
      path: 'https://example.com',
    })
    expect(offsite.code).toBe('invalid_path')
    const protocolRelative = await agent.call<ToolError>('navigate_to_page', {
      path: '//example.com/docs',
    })
    expect(protocolRelative.code).toBe('invalid_path')
    // Dot segments normalize /..//example.com to the protocol-relative //example.com.
    const dotSegments = await agent.call<ToolError>('navigate_to_page', {
      path: '/..//example.com',
    })
    expect(dotSegments.code).toBe('invalid_path')
    await expect(page).toHaveURL(/\/home$/)

    const pricing = await agent.call<{ navigating: boolean }>(
      'navigate_to_page',
      {
        path: '/pricing',
      },
    )
    expect(pricing.navigating).toBe(true)
    await expect(page).toHaveURL(/\/pricing$/)
    await expect(page).toHaveTitle(/Pricing/)

    // Sign-up is outside the public-site layouts: the call still answers, then
    // the tools unregister once the tab leaves.
    const signUp = await agent.call<{ navigating: boolean; url: string }>(
      'navigate_to_sign_up',
    )
    expect(signUp.url).toMatch(/\/sign-up$/)
    await expect(page).toHaveURL(/\/sign-up/)
    await expect.poll(async () => (await agent.tools()).length).toBe(0)
  })
})

// This file launches Chromium with the flag that exposes navigator.modelContext,
// so this test starts its own browser without it.
test('a browser without WebMCP never downloads the tool actions', async ({
  playwright,
  baseURL,
}) => {
  const browser = await playwright.chromium.launch({ args: [] })
  try {
    const page = await browser.newPage({ baseURL })
    const requested: string[] = []
    page.on('request', (request) => {
      const url = new URL(request.url())
      if (
        /\/assets\/actions-[^/]+\.js$/.test(url.pathname) ||
        url.pathname.endsWith('/lib/webmcp/site/actions.ts')
      ) {
        requested.push(url.pathname)
      }
    })

    await page.goto('/home')
    await expect(page.locator('main')).toBeVisible()
    expect(
      await page.evaluate(() => window.__appwriteSiteTools === undefined),
    ).toBe(true)
    await page
      .waitForLoadState('networkidle', { timeout: 10_000 })
      .catch(() => {})
    expect(requested).toEqual([])
  } finally {
    await browser.close()
  }
})

test('a call cancelled before the page connects its handlers is answered', async ({
  page,
}) => {
  // Hold back the handlers so the call waits on the inline tools.
  await page.route(
    /\/(assets\/actions-[^/]+\.js|lib\/webmcp\/site\/actions\.ts)(\?.*)?$/,
    () => {},
  )
  await openAsAgent(
    page,
    '/home',
    READ_ONLY_TOOLS.length + NAVIGATION_TOOLS.length,
  )

  const result = await page.evaluate(async () => {
    const tool = (await document.modelContext!.getTools()).find(
      (registered) => registered.name === 'search_docs',
    )!
    const controller = new AbortController()
    const call = document.modelContext!.executeTool(
      tool,
      { query: 'create a row' },
      { signal: controller.signal },
    )
    setTimeout(() => controller.abort(), 500)
    return call.then(
      (output) => JSON.parse(output) as { code?: string },
      (error: Error) => ({ code: error.name }),
    )
  })
  // Chrome rejects an aborted executeTool() itself; the registry passes the
  // page's own answer through, which shows the waiting call was released.
  expect(result.code).toBe(WEBMCP_CHANNEL ? 'AbortError' : 'cancelled')
})

test('a self-hosted console registers no Appwrite Cloud tools', async ({
  page,
}) => {
  // Prerendered pages are shared by both profiles, so the inline script has
  // to read the profile from the runtime config at load.
  await page.addInitScript((key) => {
    let config: Record<string, unknown> | undefined
    Object.defineProperty(window, key, {
      configurable: true,
      get: () => config,
      set: (value: Record<string, unknown>) => {
        config = { ...value, consoleProfile: 'self-hosted' }
      },
    })
  }, RUNTIME_CONFIG_WINDOW_KEY)
  await openAsAgent(page, '/home', 0)
  await page.waitForLoadState('load')
  expect(
    await page.evaluate(async () => ({
      bridge: window.__appwriteSiteTools === undefined,
      tools: (await document.modelContext!.getTools()).length,
    })),
  ).toEqual({ bridge: true, tools: 0 })
})
