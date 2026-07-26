const PROCESS_EXIT_PREFIX = 'Process exited with code'

function getRejectionMessage(reason: unknown): string {
  if (reason && typeof reason === 'object' && 'message' in reason) {
    return String((reason as { message: unknown }).message)
  }
  return String(reason ?? '')
}

function isProcessExitRejection(reason: unknown): boolean {
  return getRejectionMessage(reason).startsWith(PROCESS_EXIT_PREFIX)
}

/**
 * Vite's client logs unhandled rejections even after preventDefault(). Register
 * in the capture phase and stopImmediatePropagation so Vite never sees
 * almostnode's intentional process.exit throws.
 */
function handleUnhandledRejection(event: PromiseRejectionEvent): void {
  if (!isProcessExitRejection(event.reason)) return
  event.preventDefault()
  event.stopImmediatePropagation()
}

let installed = false

/** Install once for the page lifetime. Safe to call repeatedly. */
export function installProcessExitRejectionSuppressor(): void {
  if (installed || typeof window === 'undefined') return
  installed = true
  window.addEventListener('unhandledrejection', handleUnhandledRejection, true)
}

if (typeof window !== 'undefined') {
  installProcessExitRejectionSuppressor()
}
