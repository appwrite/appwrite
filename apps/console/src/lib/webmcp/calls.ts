import { trackEvent } from '@/lib/analytics'

/**
 * What a tool resolves with when it can't do its job. Resolving instead of
 * throwing lets the agent see the reason; a rejection reaches it as a bare
 * UnknownError.
 */
export type ToolError = { error: string; code: string; retryable: boolean }

export type ToolHandler = (
  input: Record<string, unknown>,
  options: WebMCP.ToolExecuteCallbackOptions,
) => Promise<unknown>

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

/** Wraps each handler so every call is a `WebMCP Tool Called` analytics event: the tool and how it ended, never its input. */
export function recordCalls(
  handlers: Record<string, ToolHandler>,
): Record<string, ToolHandler> {
  return Object.fromEntries(
    Object.entries(handlers).map(([tool, handler]) => [
      tool,
      async (input, options) => {
        try {
          const result = await handler(input, options)
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
      },
    ]),
  )
}
