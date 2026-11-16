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
async function installRegistry(page: Page): Promise<void> {
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
        tools.set(name, { ...tool, signal: options.signal })
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
      async executeTool(
        registered: { name: string },
        input: object = {},
        options: { signal?: AbortSignal } = {},
      ) {
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
        // Chrome never delivers the result of a tool unregistered mid-call;
        // fail fast here so CI catches it instead of hanging.
        const signal = tool.signal as AbortSignal | undefined
        const unregistered = new Promise<never>((_, reject) =>
          signal?.addEventListener(
            'abort',
            () =>
              reject(
                new DOMException(
                  `${registered.name} was unregistered before it returned`,
                  'AbortError',
                ),
              ),
            { once: true },
          ),
        )
        return JSON.stringify(
          await Promise.race([
            execute(input, {
              signal: options.signal ?? new AbortController().signal,
            }),
            unregistered,
          ]),
        )
      },
    }
    Object.defineProperty(document, 'modelContext', { value: modelContext })
  })
}

/** Tools register after hydration; the predicate is async, so poll rather than waitForFunction. */
async function waitForTools(page: Page, count: number): Promise<void> {
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
async function getTools(page: Page): Promise<RegisteredToolSummary[]> {
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

async function callTool<T = Record<string, unknown>>(
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

/** A browser agent's view of the page: discover tools, then call them. */
export type WebMcpAgent = {
  tools(): Promise<RegisteredToolSummary[]>
  /** Calls a tool through executeTool() and returns its parsed JSON result. */
  call<T = Record<string, unknown>>(
    name: string,
    input?: Record<string, unknown>,
  ): Promise<T>
}

/**
 * Opens `path` as a browser agent would and waits until `tools` tools are
 * registered. Uses Chrome's native WebMCP when the test runs on a channel
 * that has it, otherwise a registry that follows the spec.
 */
export async function openAsAgent(
  page: Page,
  path: string,
  tools: number,
): Promise<WebMcpAgent> {
  await installRegistry(page)
  await page.goto(path)
  await waitForTools(page, tools)
  return {
    tools: () => getTools(page),
    call: (name, input) => callTool(page, name, input),
  }
}
