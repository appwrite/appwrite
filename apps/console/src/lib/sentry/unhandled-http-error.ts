/**
 * h3 turns a thrown Error into an HTTPError, sets `unhandled`, copies the
 * original stack, and `console.error`s it while returning HTTP 500. That
 * rejection never reaches Bun.serve or `server.ts`, so Sentry misses it.
 * The logged fields are `status`, `statusText`, `headers`, and `data`.
 */
export function unhandledServerErrorFromConsoleArgs(
  args: readonly unknown[],
): { error: Error; status?: number } | undefined {
  for (const arg of args) {
    if (!(arg instanceof Error) || arg.name !== 'HTTPError') continue
    const record = arg as Error & {
      unhandled?: unknown
      status?: unknown
      cause?: unknown
    }
    if (record.unhandled !== true) continue

    return {
      error: record.cause instanceof Error ? record.cause : arg,
      status: typeof record.status === 'number' ? record.status : undefined,
    }
  }
  return undefined
}
