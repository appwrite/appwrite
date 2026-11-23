'use client'

import * as React from 'react'
import { Calendar as CalendarIcon, ChevronDown, Globe } from 'lucide-react'

import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Calendar } from '@/components/ui/calendar'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { useT } from '@/lib/i18n/translate'
import { useLocalizedDateFormat } from '@/lib/i18n/use-localized-date-format'
import { pad2, type HourMinute } from '@/lib/time-segments'
import {
  formatTimeZoneLabel,
  formatTimeZoneOffset,
  getZonedParts,
  useDisplayTimeZone,
  zonedCalendarDate,
  zonedPartsToDateCompatible,
} from '@/lib/timezones'
import { DateTimePickerTimeFields } from './DateTimePickerTimeFields'
import { DateTimePickerZonePanel } from './DateTimePickerZonePanel'

export interface DateTimePickerProps {
  /** ISO string or null/empty for unset */
  value: string | null | undefined
  /**
   * Receives an ISO string or null. Fires once per accepted keystroke, day
   * pick or Clear (including intermediate values like 01:00 before 15:00);
   * never on focus, blur, open, close or zone change. Parents must not run
   * side effects on change.
   */
  onChange: (value: string | null) => void
  disabled?: boolean
  placeholder?: string
  /** Inclusive lower bound (ISO string or Date). Dates before this are disabled. */
  min?: string | Date | null
  /** Inclusive upper bound (ISO string or Date). Dates after this are disabled. */
  max?: string | Date | null
  /** Tailwind classes applied to the trigger button */
  className?: string
  align?: 'start' | 'center' | 'end'
  /** Show a Clear button in the popover footer to set value to null */
  clearable?: boolean
  /** Visual size for the trigger */
  size?: 'sm' | 'default'
  /** Hide the leading calendar icon (useful for tight cells) */
  hideIcon?: boolean
  /** Auto-focus the trigger on mount */
  autoFocus?: boolean
  /** Forwarded ref for the trigger button (e.g. focus management) */
  triggerRef?: React.Ref<HTMLButtonElement>
  id?: string
  ariaLabel?: string
  /** Forwarded onFocus on the trigger button */
  onFocus?: React.FocusEventHandler<HTMLButtonElement>
  /**
   * 'preferred': value is an absolute instant (Appwrite datetime). Reads and
   * writes wall clock in the device display zone (useDisplayTimeZone) and
   * shows the zone switcher. NEVER use for naive SQL timestamp/time columns,
   * SQL-capable filters or role drawers.
   * 'browser' (default): browser-local wall clock, no zone UI.
   */
  timeZoneMode?: 'browser' | 'preferred'
  /** Append the UTC offset to the trigger (wide triggers only; ignored unless timeZoneMode='preferred'). */
  showTimeZoneInTrigger?: boolean
}

function parseISO(value: string | Date | null | undefined): Date | null {
  if (!value) return null
  const d = value instanceof Date ? value : new Date(value)
  return Number.isNaN(d.getTime()) ? null : d
}

function clampToBounds(next: Date, min: Date | null, max: Date | null): Date {
  let time = next.getTime()
  if (min && time < min.getTime()) time = min.getTime()
  if (max && time > max.getTime()) time = max.getTime()
  return new Date(time)
}

/** Wall clock in `timeZone` on `base`'s day there; gap forward, overlap earlier. */
function withZonedTime(
  base: Date,
  { hour, minute }: HourMinute,
  timeZone: string,
): Date {
  return zonedPartsToDateCompatible(
    { ...getZonedParts(base, timeZone), hour, minute, second: 0 },
    timeZone,
  )
}

function toHourMinute(date: Date, timeZone: string): HourMinute {
  const { hour, minute } = getZonedParts(date, timeZone)
  return { hour, minute }
}

export function DateTimePicker({
  value,
  onChange,
  disabled,
  placeholder = 'Select date & time',
  min,
  max,
  className,
  align = 'start',
  clearable = true,
  size = 'default',
  hideIcon = false,
  autoFocus,
  triggerRef,
  id,
  ariaLabel,
  onFocus,
  timeZoneMode = 'browser',
  showTimeZoneInTrigger = false,
}: DateTimePickerProps) {
  const t = useT()
  const { formatDate, formatDateTime } = useLocalizedDateFormat()
  const display = useDisplayTimeZone()
  const zoneEnabled = timeZoneMode === 'preferred'
  const timeZone = zoneEnabled ? display.timeZone : display.browserTimeZone
  const [open, setOpen] = React.useState(false)
  const [view, setView] = React.useState<'calendar' | 'zone'>('calendar')
  const [openedByKeyboard, setOpenedByKeyboard] = React.useState(false)
  const keyboardActivationRef = React.useRef(false)
  const zoneButtonRef = React.useRef<HTMLButtonElement>(null)
  const returnFocusToZoneButtonRef = React.useRef(false)
  const timeLabelId = React.useId()
  const zoneLabelId = React.useId()
  const utcEchoId = React.useId()
  const date = React.useMemo(() => parseISO(value), [value])
  const minDate = React.useMemo(() => parseISO(min), [min])
  const maxDate = React.useMemo(() => parseISO(max), [max])
  // Latest emitted instant, so consecutive keystrokes build on each other
  // before the parent re-renders with the new value.
  const latestDateRef = React.useRef<Date | null>(date)

  React.useLayoutEffect(() => {
    latestDateRef.current = date
  }, [date])

  React.useEffect(() => {
    if (view !== 'calendar' || !returnFocusToZoneButtonRef.current) return
    returnFocusToZoneButtonRef.current = false
    zoneButtonRef.current?.focus({ preventScroll: true })
  }, [view])

  const zoned = React.useMemo(
    () => (date ? getZonedParts(date, timeZone) : null),
    [date, timeZone],
  )
  const selectedDay = React.useMemo(
    () => (date ? zonedCalendarDate(date, timeZone) : undefined),
    [date, timeZone],
  )
  const todayInZone = open ? zonedCalendarDate(new Date(), timeZone) : undefined
  const formatted =
    zoned && selectedDay
      ? `${formatDate(selectedDay, 'MMM d, yyyy')} · ${pad2(zoned.hour)}:${pad2(zoned.minute)}`
      : ''
  const calendarDisabled = React.useMemo(() => {
    const matchers = []
    if (minDate) matchers.push({ before: zonedCalendarDate(minDate, timeZone) })
    if (maxDate) matchers.push({ after: zonedCalendarDate(maxDate, timeZone) })
    return matchers.length > 0 ? matchers : undefined
  }, [minDate, maxDate, timeZone])
  const triggerOffset =
    zoneEnabled && showTimeZoneInTrigger && date
      ? formatTimeZoneOffset(timeZone, date)
      : null
  const zoneLabel = zoneEnabled
    ? formatTimeZoneLabel(timeZone, date ?? new Date())
    : ''
  let utcEcho: string | null = null
  if (zoneEnabled && date && zoned) {
    const utcDayDiffers =
      date.getUTCFullYear() !== zoned.year ||
      date.getUTCMonth() + 1 !== zoned.month ||
      date.getUTCDate() !== zoned.day
    // Compare parts, not the offset label: engines format zero as 'GMT' or 'GMT+0'.
    const offsetIsZero =
      !utcDayDiffers &&
      date.getUTCHours() === zoned.hour &&
      date.getUTCMinutes() === zoned.minute
    if (!offsetIsZero) {
      utcEcho = formatDateTime(date, {
        month: utcDayDiffers ? 'short' : undefined,
        day: utcDayDiffers ? 'numeric' : undefined,
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone: 'UTC',
      })
    }
  }

  function emit(next: Date): Date {
    const clamped = clampToBounds(next, minDate, maxDate)
    latestDateRef.current = clamped
    onChange(clamped.toISOString())
    return clamped
  }

  function handleTimeChange(time: HourMinute): HourMinute {
    const applied = emit(
      withZonedTime(latestDateRef.current ?? new Date(), time, timeZone),
    )
    return toHourMinute(applied, timeZone)
  }

  function resolveWallClock(time: HourMinute): HourMinute {
    return toHourMinute(
      withZonedTime(latestDateRef.current ?? new Date(), time, timeZone),
      timeZone,
    )
  }

  function handleSelectDate(day: Date | undefined) {
    if (!day) return
    const current = latestDateRef.current
      ? getZonedParts(latestDateRef.current, timeZone)
      : null
    emit(
      zonedPartsToDateCompatible(
        {
          year: day.getFullYear(),
          month: day.getMonth() + 1,
          day: day.getDate(),
          hour: current?.hour ?? 0,
          minute: current?.minute ?? 0,
          second: 0,
        },
        timeZone,
      ),
    )
  }

  function handleClear() {
    onChange(null)
    setOpen(false)
  }

  function closeZoneView() {
    returnFocusToZoneButtonRef.current = true
    setView('calendar')
  }

  return (
    <Popover
      open={open}
      onOpenChange={(nextOpen) => {
        setOpen(nextOpen)
        if (!nextOpen) setView('calendar')
      }}
    >
      <PopoverTrigger asChild>
        <Button
          id={id}
          ref={triggerRef}
          type="button"
          variant="outline"
          disabled={disabled}
          autoFocus={autoFocus}
          onFocus={onFocus}
          // Not event.detail: clicks forwarded from a <label> also report 0.
          onKeyDown={(event) => {
            keyboardActivationRef.current =
              event.key === 'Enter' || event.key === ' '
          }}
          // Runs before Radix toggles.
          onClick={() => {
            setOpenedByKeyboard(keyboardActivationRef.current)
            keyboardActivationRef.current = false
          }}
          aria-label={ariaLabel ? t(ariaLabel) : undefined}
          className={cn(
            'w-full cursor-pointer justify-start gap-2 text-start font-normal',
            size === 'sm' ? 'h-8 text-[12px]' : 'h-9 text-[13px]',
            !date && 'text-muted-foreground',
            className,
          )}
        >
          {!hideIcon && (
            <CalendarIcon className="h-3.5 w-3.5 shrink-0 opacity-70" />
          )}
          <span className="flex-1 truncate">
            {date ? formatted : t(placeholder)}
          </span>
          {triggerOffset ? (
            <bdi
              dir="ltr"
              className="shrink-0 text-[11px] tabular-nums text-muted-foreground"
            >
              {triggerOffset}
            </bdi>
          ) : null}
          <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align={align}
        sideOffset={4}
        className="relative flex max-h-(--radix-popover-content-available-height) w-auto flex-col overflow-hidden rounded-xl p-0 shadow-lg"
        // Capture keeps a surrounding Sheet/Dialog scroll lock from eating the wheel.
        onWheelCapture={(event) => event.stopPropagation()}
        onTouchMoveCapture={(event) => event.stopPropagation()}
        onOpenAutoFocus={(event) => {
          event.preventDefault()
          // Not on mount: a surrounding Sheet's focus trap pauses only once this scope mounts.
          if (openedByKeyboard) {
            ;(event.currentTarget as HTMLElement)
              .querySelector<HTMLInputElement>('[role="spinbutton"]')
              ?.focus({ preventScroll: true })
          }
        }}
        onEscapeKeyDown={(event) => {
          // Radix listens in the document capture phase, before cmdk sees the key.
          if (view === 'zone') {
            event.preventDefault()
            closeZoneView()
          }
        }}
      >
        <div
          inert={view === 'zone'}
          // Only the calendar scrolls on short viewports, so time and zone stay visible.
          className="min-h-0 overflow-y-auto overscroll-contain p-3"
        >
          <Calendar
            mode="single"
            selected={selectedDay}
            today={todayInZone}
            onSelect={handleSelectDate}
            defaultMonth={selectedDay ?? todayInZone}
            disabled={calendarDisabled}
          />
        </div>
        <div
          inert={view === 'zone'}
          className="shrink-0 border-t border-border bg-muted/30 p-3"
        >
          <div className="flex items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <span
                id={timeLabelId}
                className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground"
              >
                {t('Time')}
              </span>
              <DateTimePickerTimeFields
                hour={zoned?.hour ?? null}
                minute={zoned?.minute ?? null}
                timeZone={timeZone}
                disabled={disabled}
                onChange={handleTimeChange}
                resolve={resolveWallClock}
                onConfirm={() => setOpen(false)}
                aria-labelledby={
                  zoneEnabled ? `${timeLabelId} ${zoneLabelId}` : timeLabelId
                }
                aria-describedby={utcEcho ? utcEchoId : undefined}
              />
            </div>
            <div className="flex items-center gap-2">
              {clearable && date && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 cursor-pointer text-[11px]"
                  onClick={handleClear}
                >
                  {t('Clear')}
                </Button>
              )}
              <Button
                size="sm"
                className="h-7 cursor-pointer text-[11px]"
                onClick={() => setOpen(false)}
              >
                {t('Done')}
              </Button>
            </div>
          </div>
          {zoneEnabled ? (
            // w-0 min-w-full: wraps inside the calendar width instead of widening the popover.
            <div className="mt-2 flex w-0 min-w-full flex-wrap items-center gap-x-2 gap-y-1">
              <Button
                ref={zoneButtonRef}
                type="button"
                variant="ghost"
                size="sm"
                title={zoneLabel}
                aria-label={`${t('Timezone')}: ${zoneLabel}`}
                onClick={() => setView('zone')}
                className={cn(
                  '-ms-1.5 h-6 min-w-0 max-w-full cursor-pointer gap-1 px-1.5 text-[11px] font-normal has-[>svg]:px-1.5',
                  display.isOverride
                    ? 'text-foreground'
                    : 'text-muted-foreground',
                )}
              >
                <Globe className="size-3 shrink-0" />
                <bdi id={zoneLabelId} dir="ltr" className="truncate">
                  {zoneLabel}
                </bdi>
                <ChevronDown className="size-3 shrink-0 opacity-60" />
              </Button>
              {utcEcho ? (
                <span
                  id={utcEchoId}
                  className="ms-auto shrink-0 whitespace-nowrap text-[11px] text-muted-foreground"
                >
                  {t('Stored in UTC')}{' '}
                  <bdi dir="ltr" className="tabular-nums">
                    {utcEcho}
                  </bdi>
                </span>
              ) : null}
            </div>
          ) : null}
        </div>
        {view === 'zone' ? (
          <DateTimePickerZonePanel
            storedTimeZone={display.storedTimeZone}
            browserTimeZone={display.browserTimeZone}
            at={date ?? new Date()}
            onSelect={(nextTimeZone) => {
              display.setTimeZone(nextTimeZone)
              closeZoneView()
            }}
            onBack={closeZoneView}
          />
        ) : null}
      </PopoverContent>
    </Popover>
  )
}
