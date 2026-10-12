type SchedulerWithPostTask = {
  postTask: (
    callback: () => void,
    options?: { priority?: 'background' | 'user-blocking' | 'user-visible' },
  ) => void
}

/**
 * Schedules non-critical work after the browser can paint the next frame.
 * Keeps interaction handlers short to improve INP.
 */
export function deferAfterPaint(callback: () => void): void {
  if (typeof window === 'undefined') {
    callback()
    return
  }

  const scheduler = (globalThis as { scheduler?: SchedulerWithPostTask })
    .scheduler
  if (scheduler?.postTask) {
    scheduler.postTask(callback, { priority: 'background' })
    return
  }

  requestAnimationFrame(() => {
    requestAnimationFrame(callback)
  })
}
