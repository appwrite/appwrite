import { useEffect, useState } from 'react'

/** How long something may load before it shows a loader. */
export const LONG_WAIT_MS = 5000

/**
 * True once `active` has stayed true for `afterMs`, so a loader appears only
 * for a wait long enough to need one, never as a flash on a quick load.
 */
export function useLongWait(active: boolean, afterMs = LONG_WAIT_MS): boolean {
  const [long, setLong] = useState(false)

  useEffect(() => {
    if (!active) {
      setLong(false)
      return
    }
    const timer = window.setTimeout(() => setLong(true), afterMs)
    return () => window.clearTimeout(timer)
  }, [active, afterMs])

  return active && long
}
