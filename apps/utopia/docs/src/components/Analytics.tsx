import { useEffect } from 'react'
import { initAnalyticsTracking } from '@/lib/analytics'

/** Initializes Appwrite Analytics once in the browser (`tracking.start()` handles SPA pageviews). */
export function Analytics() {
  useEffect(() => {
    initAnalyticsTracking()
  }, [])
  return null
}
