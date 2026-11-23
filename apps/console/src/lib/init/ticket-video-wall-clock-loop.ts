export function sleep(ms: number) {
  return new Promise<void>((resolve) => {
    window.setTimeout(resolve, ms)
  })
}

function isDocumentHidden() {
  return typeof document !== 'undefined' && document.visibilityState === 'hidden'
}

function isReleasable(
  value: unknown,
): value is { release: () => Promise<unknown> } {
  return (
    typeof value === 'object' &&
    value !== null &&
    'release' in value &&
    typeof value.release === 'function'
  )
}

/** Best-effort screen wake lock for the duration of ticket video export. */
export async function withInitTicketCaptureWakeLock<T>(
  task: () => Promise<T>,
): Promise<T> {
  let sentinel: unknown = null

  const requestWakeLock = async () => {
    if (typeof navigator === 'undefined' || isDocumentHidden()) return
    const wakeLock = (
      navigator as Navigator & {
        wakeLock?: { request: (type: 'screen') => Promise<unknown> }
      }
    ).wakeLock
    if (!wakeLock) return

    try {
      sentinel = await wakeLock.request('screen')
    } catch {
      sentinel = null
    }
  }

  const handleVisibility = () => {
    if (document.visibilityState === 'visible') {
      void requestWakeLock()
    }
  }

  try {
    await requestWakeLock()
    document.addEventListener('visibilitychange', handleVisibility)
    return await task()
  } finally {
    document.removeEventListener('visibilitychange', handleVisibility)
    if (isReleasable(sentinel)) {
      try {
        await sentinel.release()
      } catch {
        // Ignore wake lock release failures.
      }
    }
  }
}
