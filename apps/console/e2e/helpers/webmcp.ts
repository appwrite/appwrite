import { expect, type Page } from '@playwright/test'

/** Set to `chrome` to run against Chrome's native WebMCP instead of the in-page registry. */
export const WEBMCP_CHANNEL = process.env.E2E_WEBMCP_CHANNEL || undefined

export type RegisteredToolSummary = {
  name: string
  title: string
  description: string
  inputSchema: {
    type: string
    properties: Record<string, { type: string; description?: string }>
    required?: string[]
  }
  annotations: {
    readOnlyHint: boolean
    untrustedContentHint: boolean
    consequentialHint: boolean
    debugging: boolean
  }
}

/**
 * Chromium without WebMCP (Playwright's bundled build) gets a registry that
 * follows the spec's registerTool / getTools / executeTool contract, so the
 * site's own registration code is what the test exercises either way.
 */
export async function installWebMcpRegistry(page: Page): Promise<void> {
  await page.addInitScript(() => {
    if ('modelContext' in document) return
    const tools = new Map<string, Record<string, unknown>>()
    const modelContext = {
      async registerTool(
        tool: Record<string, unknown>,
        options: { signal?: AbortSignal } = {},
      ) {
        const name = String(tool.name)
        if (!/^[A-Za-z0-9_.-]{1,128}$/.test(name))
          throw new TypeError(`Invalid tool name ${name}`)
        if (typeof tool.execute !== 'function')
          throw new TypeError(`${name} has no execute`)
        if (tools.has(name))
          throw new DOMException(
            `${name} is already registered`,
            'InvalidStateError',
          )
        if (options.signal?.aborted) return
        tools.set(name, tool)
        options.signal?.addEventListener('abort', () => tools.delete(name), {
          once: true,
        })
      },
      async getTools() {
        return [...tools.values()].map((tool) => ({
          name: tool.name,
          title: tool.title ?? '',
          description: tool.description,
          inputSchema: tool.inputSchema,
          annotations: {
            readOnlyHint: false,
            untrustedContentHint: false,
            consequentialHint: false,
            debugging: false,
            ...(tool.annotations as object),
          },
        }))
      },
      async executeTool(registered: { name: string }, input: object = {}) {
        const tool = tools.get(registered.name)
        if (!tool)
          throw new DOMException(
            `${registered.name} is not registered`,
            'NotFoundError',
          )
        const execute = tool.execute as (
          input: object,
          options: { signal: AbortSignal },
        ) => unknown
        return JSON.stringify(
          await execute(input, { signal: new AbortController().signal }),
        )
      },
    }
    Object.defineProperty(document, 'modelContext', { value: modelContext })
  })
}

/** Tools register after hydration; the predicate is async, so poll rather than waitForFunction. */
export async function waitForTools(page: Page, count: number): Promise<void> {
  await expect
    .poll(
      () =>
        page.evaluate(
          async () => ((await document.modelContext?.getTools()) ?? []).length,
        ),
      { timeout: 30_000 },
    )
    .toBeGreaterThanOrEqual(count)
}

/** Native RegisteredTool objects carry a Window reference, so copy the fields an agent reads. */
export async function getTools(page: Page): Promise<RegisteredToolSummary[]> {
  return page.evaluate(async () =>
    (await document.modelContext!.getTools()).map((tool) => ({
      name: tool.name,
      title: tool.title ?? '',
      description: tool.description,
      inputSchema: JSON.parse(JSON.stringify(tool.inputSchema ?? {})),
      // Chrome omits annotations a tool didn't declare; the spec defaults each hint to false.
      annotations: {
        readOnlyHint: false,
        untrustedContentHint: false,
        consequentialHint: false,
        debugging: false,
        ...tool.annotations,
      },
    })),
  ) as Promise<RegisteredToolSummary[]>
}

/** Calls a tool the way an agent does and returns its parsed JSON result. */
export async function callTool<T = Record<string, unknown>>(
  page: Page,
  name: string,
  input: Record<string, unknown> = {},
): Promise<T> {
  const output = await page.evaluate(
    async ([name, input]) => {
      const tool = (await document.modelContext!.getTools()).find(
        (registered) => registered.name === name,
      )
      if (!tool) throw new Error(`${name} is not registered`)
      return document.modelContext!.executeTool(tool, input)
    },
    [name, input] as const,
  )
  return JSON.parse(output) as T
}
