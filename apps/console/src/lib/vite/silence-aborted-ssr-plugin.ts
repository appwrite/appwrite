import type { Plugin } from 'vite'

function isClientDisconnectError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false

  const candidate = error as {
    message?: string
    code?: string
    cause?: { code?: string; message?: string }
  }
  const code = candidate.code ?? candidate.cause?.code
  if (
    code === 'ECONNRESET' ||
    code === 'EPIPE' ||
    code === 'ECONNABORTED' ||
    code === 'ERR_STREAM_PREMATURE_CLOSE'
  ) {
    return true
  }

  const message = candidate.message ?? candidate.cause?.message ?? ''
  return message === 'aborted' || message === 'Error: aborted'
}

/**
 * TanStack Start's Vite SSR middleware `console.error`s when the browser
 * cancels an in-flight document request (refresh, HMR, navigation). That is
 * not a failed page; swallow only that disconnect shape.
 */
export function silenceAbortedSsrPlugin(): Plugin {
  return {
    name: 'silence-aborted-ssr',
    configureServer() {
      const originalError = console.error.bind(console)
      console.error = (...args: Parameters<typeof console.error>) => {
        if (args.some((arg) => isClientDisconnectError(arg))) return
        originalError(...args)
      }
    },
  }
}
