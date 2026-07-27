/** ~60Hz tick - uses timers so capture continues when rAF is throttled (hidden tab). */
const DEFAULT_TICK_INTERVAL_MS = 16

export function sleep(ms: number) {
  return new Promise<void>((resolve) => {
    window.setTimeout(resolve, ms)
  })
}

function isDocumentHidden() {
  return typeof document !== 'undefined' && document.visibilityState === 'hidden'
}

/**
 * Drive capture progress from wall-clock time via setTimeout (not rAF).
 * Keeps tilt/progress advancing when the tab is in the background.
 */
export async function runWallClockLoop({
  durationMs,
  onTick,
  tickIntervalMs = DEFAULT_TICK_INTERVAL_MS,
}: {
  durationMs: number
  onTick: (progress: number) => void
  tickIntervalMs?: number
}): Promise<void> {
  const startTime = performance.now()

  return new Promise<void>((resolve) => {
    const tick = () => {
      const elapsed = performance.now() - startTime
      if (elapsed >= durationMs) {
        onTick(1)
        resolve()
        return
      }

      onTick(Math.min(1, elapsed / durationMs))
      window.setTimeout(tick, tickIntervalMs)
    }

    tick()
  })
}

/**
 * Wall-clock loop that pauses while the document is hidden.
 * Use for tab element capture - the compositor stops updating off-screen tabs.
 */
export async function runVisibilityPausedWallClockLoop({
  durationMs,
  onTick,
  onPause,
  onResume,
  tickIntervalMs = DEFAULT_TICK_INTERVAL_MS,
}: {
  durationMs: number
  onTick: (progress: number) => void
  onPause?: () => void
  onResume?: () => void
  tickIntervalMs?: number
}): Promise<void> {
  let elapsedMs = 0
  let lastSample = performance.now()
  let paused = isDocumentHidden()

  if (paused) {
    onPause?.()
  }

  return new Promise<void>((resolve) => {
    const handleVisibility = () => {
      const now = performance.now()
      const hidden = isDocumentHidden()

      if (hidden && !paused) {
        elapsedMs += now - lastSample
        paused = true
        onPause?.()
      } else if (!hidden && paused) {
        paused = false
        onResume?.()
      }

      lastSample = now
    }

    const tick = () => {
      const now = performance.now()

      if (!paused) {
        elapsedMs += now - lastSample
      }
      lastSample = now

      if (elapsedMs >= durationMs) {
        onTick(1)
        document.removeEventListener('visibilitychange', handleVisibility)
        resolve()
        return
      }

      onTick(Math.min(1, elapsedMs / durationMs))
      window.setTimeout(tick, tickIntervalMs)
    }

    document.addEventListener('visibilitychange', handleVisibility)
    tick()
  })
}

/** Best-effort screen wake lock for the duration of ticket video export. */
export async function withInitTicketCaptureWakeLock<T>(
  task: () => Promise<T>,
): Promise<T> {
  let wakeLock: WakeLockSentinel | null = null

  const requestWakeLock = async () => {
    if (typeof navigator === 'undefined' || !('wakeLock' in navigator)) return
    if (isDocumentHidden()) return

    try {
      wakeLock = await navigator.wakeLock.request('screen')
    } catch {
      wakeLock = null
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
    await wakeLock?.release().catch(() => undefined)
  }
}
