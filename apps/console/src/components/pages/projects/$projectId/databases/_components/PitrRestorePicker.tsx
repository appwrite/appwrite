import { useMemo } from 'react'
import { Calendar } from '@/components/ui/calendar'
import { Button } from '@/components/ui/button'
import { TimeInput } from '@/components/global/shared/TimeInput'
import { TimezoneSelect } from '@/components/global/shared/TimezoneSelect'
import { useT } from '@/lib/i18n/translate'
import { useLocalizedDateFormat } from '@/lib/i18n/use-localized-date-format'
import {
  getPitrDayBounds,
  pitrOutOfRangeReason,
  type PitrOutOfRangeReason,
} from '@/lib/databases/dedicated-pitr'
import {
  getUserTimeZone,
  getZonedParts,
  zonedCalendarDate,
  zonedPartsToDate,
} from '@/lib/timezones'
import { cn } from '@/lib/utils'

type RecoveryWindow = { earliest: Date; latest: Date }

type PitrRestorePickerProps = {
  value: Date | null
  onChange: (next: Date) => void
  recoveryWindow: RecoveryWindow
  timeZone: string
  onTimeZoneChange: (timeZone: string) => void
  disabled?: boolean
}

const STEP_MS = {
  hour: 3_600_000,
  minute: 60_000,
  second: 1_000,
} as const

function outOfRangeMessage(
  reason: PitrOutOfRangeReason,
  t: (key: string) => string,
): string {
  if (reason === 'before') {
    return t('This time is before the earliest recovery point.')
  }
  return t('This time is after the latest recovery point.')
}

function dayOverlapsWindow(
  day: Date,
  bounds: RecoveryWindow,
  timeZone: string,
): boolean {
  const start = zonedPartsToDate(
    {
      year: day.getFullYear(),
      month: day.getMonth() + 1,
      day: day.getDate(),
      hour: 0,
      minute: 0,
      second: 0,
    },
    timeZone,
  )
  const end = zonedPartsToDate(
    {
      year: day.getFullYear(),
      month: day.getMonth() + 1,
      day: day.getDate(),
      hour: 23,
      minute: 59,
      second: 59,
    },
    timeZone,
  )
  return (
    start.getTime() <= bounds.latest.getTime() &&
    end.getTime() >= bounds.earliest.getTime()
  )
}

function wallClockToInstant(
  day: Date,
  hour: number,
  minute: number,
  second: number,
  timeZone: string,
): Date {
  return zonedPartsToDate(
    {
      year: day.getFullYear(),
      month: day.getMonth() + 1,
      day: day.getDate(),
      hour,
      minute,
      second,
    },
    timeZone,
  )
}

export function PitrRestorePicker({
  value,
  onChange,
  recoveryWindow,
  timeZone,
  onTimeZoneChange,
  disabled,
}: PitrRestorePickerProps) {
  const t = useT()
  const { formatDateTime } = useLocalizedDateFormat()
  const anchorDate = value ?? recoveryWindow.latest
  const selectedDay = zonedCalendarDate(anchorDate, timeZone)
  const wallClock = useMemo(
    () => getZonedParts(anchorDate, timeZone),
    [anchorDate, timeZone],
  )

  const composedInstant = useMemo(
    () =>
      wallClockToInstant(
        selectedDay,
        wallClock.hour,
        wallClock.minute,
        wallClock.second,
        timeZone,
      ),
    [selectedDay, wallClock, timeZone],
  )

  const validationReason = pitrOutOfRangeReason(
    composedInstant,
    recoveryWindow,
  )

  const selectedLabel = formatDateTime(composedInstant, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
    timeZone,
  })
  const utcLabel = formatDateTime(composedInstant, {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
    timeZone: 'UTC',
    timeZoneName: 'short',
  })

  const calendarDisabled = useMemo(
    () => [
      { before: zonedCalendarDate(recoveryWindow.earliest, timeZone) },
      { after: zonedCalendarDate(recoveryWindow.latest, timeZone) },
    ],
    [recoveryWindow, timeZone],
  )

  function handleTimeChange(next: {
    hour: number
    minute: number
    second: number
  }) {
    onChange(
      wallClockToInstant(
        selectedDay,
        next.hour,
        next.minute,
        next.second,
        timeZone,
      ),
    )
  }

  function stepTime(
    unit: keyof typeof STEP_MS,
    delta: number,
    at: { hour: number; minute: number; second: number },
  ) {
    const bounds = getPitrDayBounds(selectedDay, recoveryWindow, timeZone)
    if (!bounds) return

    const current = wallClockToInstant(
      selectedDay,
      at.hour,
      at.minute,
      at.second,
      timeZone,
    )
    let nextMs = current.getTime() + delta * STEP_MS[unit]
    nextMs = Math.max(
      bounds.min.getTime(),
      Math.min(bounds.max.getTime(), nextMs),
    )
    onChange(new Date(nextMs))
  }

  function handleSelectDate(next: Date | undefined) {
    if (!next || disabled) return
    const currentDay = zonedCalendarDate(anchorDate, timeZone)
    if (
      currentDay.getFullYear() === next.getFullYear() &&
      currentDay.getMonth() === next.getMonth() &&
      currentDay.getDate() === next.getDate()
    ) {
      return
    }
    const bounds = getPitrDayBounds(next, recoveryWindow, timeZone)
    if (!bounds) return
    onChange(bounds.min)
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="grid md:grid-cols-[auto_minmax(0,1fr)]">
        <div className="p-3 md:border-e border-border">
          <Calendar
            mode="single"
            selected={selectedDay}
            onSelect={handleSelectDate}
            defaultMonth={
              selectedDay ?? zonedCalendarDate(recoveryWindow.latest, timeZone)
            }
            disabled={disabled ? () => true : calendarDisabled}
            modifiers={{
              available: (day) =>
                dayOverlapsWindow(day, recoveryWindow, timeZone),
            }}
            modifiersClassNames={{
              available:
                '[&>button]:rounded-md [&>button:not([data-selected-single=true])]:bg-[color-mix(in_oklch,var(--brand-cta)_14%,var(--background))] [&>button:not([data-selected-single=true])]:hover:bg-[color-mix(in_oklch,var(--brand-cta)_22%,var(--background))]',
            }}
            showOutsideDays={false}
            className="p-3 pb-0"
          />
          <div className="flex items-center gap-3 px-3 pt-4 pb-3">
            <span className="size-2.5 rounded-sm bg-[color-mix(in_oklch,var(--brand-cta)_40%,transparent)] border border-[color-mix(in_oklch,var(--brand-cta)_35%,var(--border))]" />
            <span className="text-[12px] text-muted-foreground">
              {t('Recovery available')}
            </span>
          </div>
        </div>

        <div className="flex flex-col gap-4 px-6 py-5">
          <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
            <p
              className={cn(
                'text-[18px] font-semibold tabular-nums',
                validationReason ? 'text-destructive' : 'text-foreground',
              )}
            >
              {selectedLabel}
            </p>
            <span className="text-[13px] text-muted-foreground tabular-nums">
              {utcLabel}
            </span>
          </div>

          <div className="space-y-1.5">
            <p className="text-[12px] text-muted-foreground">{t('Time zone')}</p>
            <TimezoneSelect
              id="pitr-timezone"
              value={timeZone}
              onValueChange={onTimeZoneChange}
              at={composedInstant}
              disabled={disabled}
            />
          </div>

          <div className="space-y-2">
            <div className="flex items-center justify-between gap-3">
              <p className="text-[12px] text-muted-foreground">
                {t('Time of recovery')}
              </p>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 text-[12px]"
                onClick={() => onChange(new Date(recoveryWindow.latest))}
                disabled={disabled}
              >
                {t('Use latest')}
              </Button>
            </div>
            <TimeInput
              id="pitr-restore-time"
              value={{
                hour: wallClock.hour,
                minute: wallClock.minute,
                second: wallClock.second,
              }}
              onChange={handleTimeChange}
              onStep={stepTime}
              disabled={disabled}
              invalid={!!validationReason}
            />
            {validationReason ? (
              <p className="text-[12px] text-destructive">
                {outOfRangeMessage(validationReason, t)}
              </p>
            ) : null}
          </div>

          <div className="grid grid-cols-1 gap-3 text-[12px] pt-1">
            <div className="flex flex-col gap-0.5">
              <span className="text-muted-foreground">
                {t('Restore available from')}
              </span>
              <PitrAbsoluteTimestamp
                date={recoveryWindow.earliest}
                timeZone={timeZone}
              />
            </div>
            <div className="flex flex-col gap-0.5">
              <span className="text-muted-foreground">
                {t('Latest restore available at')}
              </span>
              <PitrAbsoluteTimestamp
                date={recoveryWindow.latest}
                timeZone={timeZone}
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export function PitrAbsoluteTimestamp({
  date,
  timeZone,
  className,
}: {
  date: Date
  timeZone?: string
  className?: string
}) {
  const { formatDateTime } = useLocalizedDateFormat()
  return (
    <span className={cn('tabular-nums text-foreground', className)}>
      {formatDateTime(date, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
        ...(timeZone ? { timeZone } : {}),
      })}
    </span>
  )
}

export { getUserTimeZone }
