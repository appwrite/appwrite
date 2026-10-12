'use client'

import { useT } from '@/lib/i18n/translate'
import {
  formatTimeZoneLabel,
  formatTimeZoneOffset,
  useDisplayTimeZone,
} from '@/lib/timezones'

/** Offset badge for datetime headers, shown only while a non-browser display zone is active. */
export function DisplayTimeZoneBadge() {
  const t = useT()
  const { timeZone, isOverride } = useDisplayTimeZone()
  if (!isOverride) return null
  const label = `${t('Timezone')}: ${formatTimeZoneLabel(timeZone)}`
  return (
    <span
      title={label}
      className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums text-muted-foreground"
    >
      <span className="sr-only">{label}</span>
      <bdi dir="ltr" aria-hidden>
        {formatTimeZoneOffset(timeZone)}
      </bdi>
    </span>
  )
}
