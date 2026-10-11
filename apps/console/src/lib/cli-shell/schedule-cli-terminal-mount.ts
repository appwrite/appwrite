import { CLI_SHELL_COLLAPSE_MS } from './constants'

/** Wait for the panel expand animation, then run when the main thread is idle. */
export function scheduleCliTerminalMount(callback: () => void): () => void {
  if (typeof window === 'undefined') {
    callback()
    return () => {}
  }

  let cancelled = false
  let collapseTimer: ReturnType<typeof globalThis.setTimeout> | undefined
  let idleId: number | undefined

  collapseTimer = globalThis.setTimeout(() => {
    collapseTimer = undefined
    if (cancelled) return

    if (typeof window.requestIdleCallback === 'function') {
      idleId = window.requestIdleCallback(
        () => {
          idleId = undefined
          if (!cancelled) callback()
        },
        { timeout: 250 },
      )
      return
    }

    callback()
  }, CLI_SHELL_COLLAPSE_MS)

  return () => {
    cancelled = true
    if (collapseTimer !== undefined) {
      globalThis.clearTimeout(collapseTimer)
    }
    if (idleId !== undefined) {
      window.cancelIdleCallback(idleId)
    }
  }
}
