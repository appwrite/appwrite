'use client'

import * as React from 'react'
import { Calendar as CalendarIcon, ChevronDown } from 'lucide-react'

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
import type { HourMinute } from '@/lib/time-segments'
import { DateTimePickerTimeFields } from './DateTimePickerTimeFields'

export interface DateTimePickerProps {
  /** ISO string or null/empty for unset */
  value: string | null | undefined
  /**
   * Receives an ISO string or null. Fires once per accepted keystroke, day
   * pick or Clear (including intermediate values like 01:00 before 15:00);
   * never on focus, blur, open or close. Parents must not run side effects
   * on change.
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

/**
 * Local wall clock on `base`'s local day. setHours resolves DST like the Date
 * constructor (gap forward, overlap earlier) without remapping years 0-99.
 */
function withLocalTime(base: Date, { hour, minute }: HourMinute): Date {
  const next = new Date(base)
  next.setHours(hour, minute, 0, 0)
  return next
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
}: DateTimePickerProps) {
  const t = useT()
  const { formatDate } = useLocalizedDateFormat()
  const [open, setOpen] = React.useState(false)
  const [openedByKeyboard, setOpenedByKeyboard] = React.useState(false)
  const keyboardActivationRef = React.useRef(false)
  const timeLabelId = React.useId()
  const date = React.useMemo(() => parseISO(value), [value])
  const minDate = React.useMemo(() => parseISO(min), [min])
  const maxDate = React.useMemo(() => parseISO(max), [max])
  // Latest emitted instant, so consecutive keystrokes build on each other
  // before the parent re-renders with the new value.
  const latestDateRef = React.useRef<Date | null>(date)

  React.useLayoutEffect(() => {
    latestDateRef.current = date
  }, [date])

  const formatted = date ? formatDate(date, "MMM d, yyyy '·' HH:mm") : ''
  const calendarDisabled = React.useMemo(() => {
    const matchers = []
    if (minDate) matchers.push({ before: minDate })
    if (maxDate) matchers.push({ after: maxDate })
    return matchers.length > 0 ? matchers : undefined
  }, [minDate, maxDate])

  function emit(next: Date): Date {
    const clamped = clampToBounds(next, minDate, maxDate)
    latestDateRef.current = clamped
    onChange(clamped.toISOString())
    return clamped
  }

  function handleTimeChange(time: HourMinute): HourMinute {
    const applied = emit(
      withLocalTime(latestDateRef.current ?? new Date(), time),
    )
    return { hour: applied.getHours(), minute: applied.getMinutes() }
  }

  function resolveWallClock(time: HourMinute): HourMinute {
    const resolved = withLocalTime(latestDateRef.current ?? new Date(), time)
    return { hour: resolved.getHours(), minute: resolved.getMinutes() }
  }

  function handleSelectDate(day: Date | undefined) {
    if (!day) return
    const current = latestDateRef.current
    emit(
      withLocalTime(day, {
        hour: current?.getHours() ?? 0,
        minute: current?.getMinutes() ?? 0,
      }),
    )
  }

  function handleClear() {
    onChange(null)
    setOpen(false)
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
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
          <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align={align}
        sideOffset={4}
        className="w-auto overflow-hidden rounded-xl p-0 shadow-lg"
        onOpenAutoFocus={(event) => {
          event.preventDefault()
          // Not on mount: a surrounding Sheet's focus trap pauses only once this scope mounts.
          if (openedByKeyboard) {
            ;(event.currentTarget as HTMLElement)
              .querySelector<HTMLInputElement>('[role="spinbutton"]')
              ?.focus({ preventScroll: true })
          }
        }}
      >
        <div className="p-3">
          <Calendar
            mode="single"
            selected={date ?? undefined}
            onSelect={handleSelectDate}
            defaultMonth={date ?? new Date()}
            disabled={calendarDisabled}
          />
        </div>
        <div className="flex items-center justify-between gap-2 border-t border-border bg-muted/30 p-3">
          <div className="flex items-center gap-2">
            <span
              id={timeLabelId}
              className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground"
            >
              {t('Time')}
            </span>
            <DateTimePickerTimeFields
              hour={date ? date.getHours() : null}
              minute={date ? date.getMinutes() : null}
              disabled={disabled}
              onChange={handleTimeChange}
              resolve={resolveWallClock}
              onConfirm={() => setOpen(false)}
              aria-labelledby={timeLabelId}
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
      </PopoverContent>
    </Popover>
  )
}
