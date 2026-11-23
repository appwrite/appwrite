import { MouseEvent, useEffect, useRef, useState } from 'react'
import { Check, Copy } from 'lucide-react'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { cn } from '@/lib/utils'
import { useT, translate } from '@/lib/i18n/translate'
import { formatLocalizedDateShort } from '@/lib/i18n/date-format'
import { useLocalizedDateFormat } from '@/lib/i18n/use-localized-date-format'
import {
  formatRelativeDuration,
  formatRelativeDurationBreakdown,
  formatShortRelativeTime,
  pickPrimaryRelativeUnit,
  type RelativeTimeUnit,
} from '@/lib/i18n/relative-time'

function parseTooltipDate(date: string | Date): Date | null {
  const dateObj = typeof date === 'string' ? new Date(date) : date
  return Number.isNaN(dateObj.getTime()) ? null : dateObj
}

interface DateTooltipProps {
  /** ISO timestamp string or Date object */
  date: string | Date
  /** Optional className for the trigger element */
  className?: string
  /** If true, shows formatted date instead of relative time */
  showFormattedDate?: boolean
  /** If true, keeps relative time labels updated on an interval */
  live?: boolean
  /** Refresh interval for live mode (default: 30s) */
  liveUpdateMs?: number
  /** If true, shows the time label without the detail popover */
  disableTooltip?: boolean
}

type DateTooltipContentProps = Omit<DateTooltipProps, 'date'> & {
  dateObj: Date
}

/**
 * Formats a date to show relative time with detailed popover
 * showing precise breakdown, UTC time, and local time
 */
export function DateTooltip({
  date,
  className,
  showFormattedDate = false,
  live = false,
  liveUpdateMs = 30_000,
  disableTooltip = false,
}: DateTooltipProps) {
  const t = useT()
  const dateObj = parseTooltipDate(date)

  if (!dateObj) {
    return (
      <span className={cn('text-muted-foreground', className)}>
        {t('Unknown')}
      </span>
    )
  }

  return (
    <DateTooltipContent
      dateObj={dateObj}
      className={className}
      showFormattedDate={showFormattedDate}
      live={live}
      liveUpdateMs={liveUpdateMs}
      disableTooltip={disableTooltip}
    />
  )
}

function DateTooltipContent({
  dateObj,
  className,
  showFormattedDate = false,
  live = false,
  liveUpdateMs = 30_000,
  disableTooltip = false,
}: DateTooltipContentProps) {
  const t = useT()
  const { formatDateTime: formatLocalizedDateTimeForLanguage } =
    useLocalizedDateFormat()
  const [nowMs, setNowMs] = useState(() => Date.now())

  useEffect(() => {
    if (!live || showFormattedDate) return
    const intervalId = window.setInterval(
      () => {
        setNowMs(Date.now())
      },
      Math.max(5_000, liveUpdateMs),
    )
    return () => window.clearInterval(intervalId)
  }, [live, liveUpdateMs, showFormattedDate])

  // Calculate relative time
  const diffMs = dateObj.getTime() - nowMs
  const isFuture = diffMs > 0
  const absDiffMs = Math.abs(diffMs)
  const diffSeconds = Math.floor(absDiffMs / 1000)
  const diffMinutes = Math.floor(diffSeconds / 60)
  const diffHours = Math.floor(diffMinutes / 60)
  const diffDays = Math.floor(diffHours / 24)
  const diffWeeks = Math.floor(diffDays / 7)
  const diffMonths = Math.floor(diffDays / 30)
  const diffYears = Math.floor(diffDays / 365)

  // Simple relative time for display
  const getSimpleRelativeTime = (): string => {
    if (diffSeconds < 60) return t('Just now')

    const { count, unit } = pickPrimaryRelativeUnit({
      diffYears,
      diffMonths,
      diffWeeks,
      diffDays,
      diffHours,
      diffMinutes,
    })

    return formatRelativeDuration(count, unit, { isFuture, t })
  }

  // Detailed breakdown for tooltip
  const getDetailedRelativeTime = (): string => {
    const parts: Array<{ count: number; unit: RelativeTimeUnit }> = []
    let remaining = absDiffMs

    const years = Math.floor(remaining / (365 * 24 * 60 * 60 * 1000))
    remaining -= years * 365 * 24 * 60 * 60 * 1000

    const months = Math.floor(remaining / (30 * 24 * 60 * 60 * 1000))
    remaining -= months * 30 * 24 * 60 * 60 * 1000

    const weeks = Math.floor(remaining / (7 * 24 * 60 * 60 * 1000))
    remaining -= weeks * 7 * 24 * 60 * 60 * 1000

    const days = Math.floor(remaining / (24 * 60 * 60 * 1000))
    remaining -= days * 24 * 60 * 60 * 1000

    const hours = Math.floor(remaining / (60 * 60 * 1000))
    remaining -= hours * 60 * 60 * 1000

    const minutes = Math.floor(remaining / (60 * 1000))

    const timeUnits: Array<{ value: number; unit: RelativeTimeUnit }> = [
      { value: years, unit: 'year' },
      { value: months, unit: 'month' },
      { value: weeks, unit: 'week' },
      { value: days, unit: 'day' },
      { value: hours, unit: 'hour' },
      { value: minutes, unit: 'minute' },
    ]

    let startIndex = 0
    for (let i = 0; i < timeUnits.length; i++) {
      if (timeUnits[i].value > 0) {
        startIndex = i
        break
      }
    }

    for (let i = startIndex; i < timeUnits.length && parts.length < 3; i++) {
      const unit = timeUnits[i]
      if (
        unit.value > 0 ||
        (i === timeUnits.length - 1 && parts.length === 0)
      ) {
        parts.push({ count: unit.value, unit: unit.unit })
      }
    }

    return formatRelativeDurationBreakdown(parts, { isFuture, t })
  }

  const formatDateTime = (d: Date, timeZone?: string): string => {
    return formatLocalizedDateTimeForLanguage(d, {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
      timeZone,
    })
  }

  const utcTime = formatDateTime(dateObj, 'UTC')
  const localTime = formatDateTime(dateObj)

  const [isOpen, setIsOpen] = useState(false)
  const [copiedField, setCopiedField] = useState<'utc' | 'local' | null>(null)
  const closeTimeoutRef = useRef<number | null>(null)
  const isoTime = dateObj.toISOString()

  useEffect(() => {
    return () => {
      if (closeTimeoutRef.current !== null) {
        window.clearTimeout(closeTimeoutRef.current)
      }
    }
  }, [])

  const openPopover = () => {
    if (closeTimeoutRef.current !== null) {
      window.clearTimeout(closeTimeoutRef.current)
      closeTimeoutRef.current = null
    }
    setIsOpen(true)
  }

  const scheduleClosePopover = () => {
    if (closeTimeoutRef.current !== null) {
      window.clearTimeout(closeTimeoutRef.current)
    }
    closeTimeoutRef.current = window.setTimeout(() => {
      setIsOpen(false)
      closeTimeoutRef.current = null
    }, 120)
  }

  const handleCopyIso = (
    field: 'utc' | 'local',
    event?: MouseEvent<HTMLElement>,
  ) => {
    event?.preventDefault()
    event?.stopPropagation()
    navigator.clipboard.writeText(isoTime)
    setCopiedField(field)
    window.setTimeout(() => setCopiedField(null), 2000)
  }

  const timeLabel = showFormattedDate
    ? formatDateTime(dateObj)
    : getSimpleRelativeTime()

  if (disableTooltip) {
    return <span className={className}>{timeLabel}</span>
  }

  return (
    <Popover open={isOpen} onOpenChange={setIsOpen}>
      <PopoverTrigger asChild>
        <span
          className={cn('cursor-default', className)}
          onMouseEnter={openPopover}
          onMouseLeave={scheduleClosePopover}
        >
          {timeLabel}
        </span>
      </PopoverTrigger>
      <PopoverContent
        side="top"
        align="center"
        sideOffset={8}
        className="w-auto max-w-[280px] p-0"
        onOpenAutoFocus={(event) => event.preventDefault()}
        onClick={(event) => event.stopPropagation()}
        onPointerDown={(event) => event.stopPropagation()}
        onMouseEnter={openPopover}
        onMouseLeave={scheduleClosePopover}
      >
        <div className="flex flex-col">
          {/* Detailed relative time */}
          <div className="border-b border-border px-3 py-2">
            <p className="text-[12px] text-muted-foreground">
              {getDetailedRelativeTime()}
            </p>
          </div>

          {/* UTC and Local times */}
          <div className="flex flex-col gap-1.5 px-3 py-2">
            <button
              type="button"
              className="group flex w-full cursor-pointer items-center gap-2 rounded px-1 py-0.5 text-start transition-colors hover:bg-muted/40"
              onClick={(event) => handleCopyIso('utc', event)}
            >
              <span className="text-[13px] text-popover-foreground">
                {utcTime}
              </span>
              <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                UTC
              </span>
              <span
                className="ms-auto inline-flex h-6 w-6 items-center justify-center text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                aria-hidden="true"
              >
                {copiedField === 'utc' ? (
                  <Check className="h-3.5 w-3.5" />
                ) : (
                  <Copy className="h-3.5 w-3.5" />
                )}
              </span>
            </button>
            <button
              type="button"
              className="group flex w-full cursor-pointer items-center gap-2 rounded px-1 py-0.5 text-start transition-colors hover:bg-muted/40"
              onClick={(event) => handleCopyIso('local', event)}
            >
              <span className="text-[13px] text-popover-foreground">
                {localTime}
              </span>
              <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                {t('Local')}
              </span>
              <span
                className="ms-auto inline-flex h-6 w-6 items-center justify-center text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                aria-hidden="true"
              >
                {copiedField === 'local' ? (
                  <Check className="h-3.5 w-3.5" />
                ) : (
                  <Copy className="h-3.5 w-3.5" />
                )}
              </span>
            </button>
          </div>
        </div>
      </PopoverContent>
    </Popover>
  )
}

/**
 * Utility function to format date for display without tooltip
 * Uses international format: "11 Dec 2025"
 */
export function formatDate(date: string | Date): string {
  const dateObj = parseTooltipDate(date)
  if (!dateObj) return translate('Unknown')
  return formatLocalizedDateShort(dateObj)
}

/**
 * Utility function to get simple relative time string
 */
export function getRelativeTimeString(date: string | Date): string {
  return formatShortRelativeTime(date)
}
