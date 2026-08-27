'use client'

import { useCallback, useEffect, useState } from 'react'
import { useI18n } from '@/lib/i18n'
import { INIT_EVENT_TIME_ZONE, formatInitScheduleTime } from './schedule-time'

/**
 * Formatter for Init session times in the viewer's own timezone.
 *
 * Renders in the event zone until hydration so server and first client markup
 * agree, then switches to the viewer's zone.
 */
export function useInitScheduleTime(): (startsAt: string) => string {
  const { language } = useI18n()
  const [isHydrated, setIsHydrated] = useState(false)

  useEffect(() => {
    setIsHydrated(true)
  }, [])

  return useCallback(
    (startsAt: string) =>
      formatInitScheduleTime(startsAt, {
        language,
        timeZone: isHydrated ? undefined : INIT_EVENT_TIME_ZONE,
      }),
    [language, isHydrated],
  )
}
