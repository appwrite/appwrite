import { useEffect, useState } from 'react'
import {
  HANUKKAH_BANNER_ID,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners'
import { getHanukkahNight } from '@/lib/hanukkah'
import { useHolidayBanner } from '@/hooks/use-holiday-banner'

/** True while the Hanukkah banner is scheduled (UTC, cloud) or force-previewed. */
export function useHanukkah(): boolean {
  return useHolidayBanner(HANUKKAH_BANNER_ID)
}

/**
 * Night 0–8 while Hanukkah decorations are visible.
 * Follows local sundown; preview outside the festival shows all eight.
 */
export function useHanukkahNight(): number | null {
  const visible = useHanukkah()
  const { isPreviewEnabled } = useDebugConsoleBannerPreviews()
  const preview = isPreviewEnabled(HANUKKAH_BANNER_ID)
  const [night, setNight] = useState<number | null>(null)

  useEffect(() => {
    if (!visible) {
      setNight(null)
      return
    }

    const tick = () => setNight(getHanukkahNight(new Date(), { preview }))
    tick()
    const timer = window.setInterval(tick, 60_000)
    return () => window.clearInterval(timer)
  }, [visible, preview])

  return visible ? night : null
}
