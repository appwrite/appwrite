import { trackEvent } from '@/lib/analytics'

declare global {
  interface Navigator {
    /** Pre-spec Chrome builds expose WebMCP here instead of on `document`. */
    readonly modelContext?: WebMCP.ModelContext
  }
}

/**
 * What a tool resolves with when it can't do its job. Resolving instead of
 * throwing lets the agent see the reason; a rejection reaches it as a bare
 * UnknownError.
 */
export type ToolError = { error: string; code: string; retryable: boolean }

export function toolError(
  code: string,
  error: string,
  retryable = false,
): ToolError {
  return { error, code, retryable }
}

function isToolError(result: unknown): result is ToolError {
  return (
    typeof result === 'object' &&
    result !== null &&
    'error' in result &&
    'code' in result
  )
}

/** Records each call as a `WebMCP Tool Called` analytics event: the tool and how it ended, never its input. */
function tracked(
  tool: string,
  execute: WebMCP.ToolExecuteCallback,
): WebMCP.ToolExecuteCallback {
  return async (input, options) => {
    try {
      const result = await execute(input, options)
      trackEvent(
        'WebMCP Tool Called',
        isToolError(result)
          ? { tool, status: 'error', code: result.code }
          : { tool, status: 'success' },
      )
      return result
    } catch (error) {
      trackEvent('WebMCP Tool Called', { tool, status: 'exception' })
      throw error
    }
  }
}

/**
 * Registers a set of WebMCP tools and returns the cleanup that unregisters
 * them. `define` calls `modelContext.registerTool({...}, options)` once per
 * tool, passing `options` through so the set shares one lifetime. Every call
 * an agent makes is recorded as a `WebMCP Tool Called` analytics event.
 *
 * Does nothing (and the cleanup is a no-op) when the browser has no WebMCP.
 * Prefers `document.modelContext` per the spec, falling back to the older
 * `navigator.modelContext`. A registration the browser rejects is logged
 * rather than thrown, so one bad tool never takes the page down.
 */
export function registerTools(
  define: (
    modelContext: Pick<WebMCP.ModelContext, 'registerTool'>,
    options: WebMCP.ModelContextRegisterToolOptions,
  ) => Promise<void>[],
): () => void {
  const modelContext = document.modelContext ?? navigator.modelContext
  if (!modelContext) return () => {}

  const recording = {
    registerTool: (
      tool: WebMCP.ModelContextTool,
      options?: WebMCP.ModelContextRegisterToolOptions,
    ) =>
      modelContext.registerTool(
        { ...tool, execute: tracked(tool.name, tool.execute) },
        options,
      ),
  } as Pick<WebMCP.ModelContext, 'registerTool'>

  const controller = new AbortController()
  for (const registration of define(recording, {
    signal: controller.signal,
  })) {
    registration.catch((error: unknown) => {
      if (!controller.signal.aborted) {
        console.warn('WebMCP tool registration failed', error)
      }
    })
  }
  return () => controller.abort()
}
