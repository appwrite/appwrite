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

/**
 * Registers a set of WebMCP tools and returns the cleanup that unregisters
 * them. `define` calls `modelContext.registerTool({...}, options)` once per
 * tool, passing `options` through so the set shares one lifetime.
 *
 * Does nothing (and the cleanup is a no-op) when the browser has no WebMCP.
 * Prefers `document.modelContext` per the spec, falling back to the older
 * `navigator.modelContext`. A registration the browser rejects is logged
 * rather than thrown, so one bad tool never takes the page down.
 */
export function registerTools(
  define: (
    modelContext: WebMCP.ModelContext,
    options: WebMCP.ModelContextRegisterToolOptions,
  ) => Promise<void>[],
): () => void {
  const modelContext = document.modelContext ?? navigator.modelContext
  if (!modelContext) return () => {}

  const controller = new AbortController()
  for (const registration of define(modelContext, {
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
