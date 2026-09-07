'use client'

import * as React from 'react'
import { format } from 'date-fns'
import { Calendar as CalendarIcon, ChevronDown } from 'lucide-react'

import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Calendar } from '@/components/ui/calendar'
import { Input } from '@/components/ui/input'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { useT } from '@/lib/i18n/translate'
import { useLocalizedDateFormat } from '@/lib/i18n/use-localized-date-format'

export interface DateTimePickerProps {
  /** ISO string or null/empty for unset */
  value: string | null | undefined
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

function digitsOnly(value: string, maxLength: number): string {
  return value.replace(/\D/g, '').slice(0, maxLength)
}

function pad2(value: number): string {
  return String(value).padStart(2, '0')
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
  const date = React.useMemo(() => parseISO(value), [value])
  const minDate = React.useMemo(() => parseISO(min), [min])
  const maxDate = React.useMemo(() => parseISO(max), [max])
  const hourRef = React.useRef<HTMLInputElement>(null)
  const minuteRef = React.useRef<HTMLInputElement>(null)
  const [hourStr, setHourStr] = React.useState(() =>
    date ? format(date, 'HH') : '',
  )
  const [minuteStr, setMinuteStr] = React.useState(() =>
    date ? format(date, 'mm') : '',
  )

  React.useEffect(() => {
    const active = document.activeElement
    if (active === hourRef.current || active === minuteRef.current) return
    setHourStr(date ? format(date, 'HH') : '')
    setMinuteStr(date ? format(date, 'mm') : '')
  }, [date])

  const formatted = date ? formatDate(date, "MMM d, yyyy '·' HH:mm") : ''
  const calendarDisabled = React.useMemo(() => {
    const matchers = []
    if (minDate) matchers.push({ before: minDate })
    if (maxDate) matchers.push({ after: maxDate })
    return matchers.length > 0 ? matchers : undefined
  }, [minDate, maxDate])

  function commit(next: Date) {
    onChange(clampToBounds(next, minDate, maxDate).toISOString())
  }

  function mergeTime(base: Date, hourValue: string, minuteValue: string): Date {
    const hour = Math.min(23, Math.max(0, parseInt(hourValue, 10) || 0))
    const minute = Math.min(59, Math.max(0, parseInt(minuteValue, 10) || 0))
    const merged = new Date(base)
    merged.setHours(hour, minute, 0, 0)
    return merged
  }

  function handleSelectDate(next: Date | undefined) {
    if (!next) return
    commit(mergeTime(next, hourStr || '0', minuteStr || '0'))
  }

  function commitTimeFields(nextHour: string, nextMinute: string) {
    const base = date ?? new Date()
    commit(mergeTime(base, nextHour || '0', nextMinute || '0'))
  }

  function handleHourChange(raw: string) {
    const next = digitsOnly(raw, 2)
    setHourStr(next)
    if (next.length === 2) {
      commitTimeFields(next, minuteStr || '0')
      minuteRef.current?.focus()
      minuteRef.current?.select()
    }
  }

  function handleMinuteChange(raw: string) {
    const next = digitsOnly(raw, 2)
    setMinuteStr(next)
    if (next.length === 2) {
      commitTimeFields(hourStr || '0', next)
    }
  }

  function handleHourBlur() {
    const normalized = hourStr === '' ? '00' : pad2(Math.min(23, parseInt(hourStr, 10) || 0))
    setHourStr(normalized)
    commitTimeFields(normalized, minuteStr || '00')
  }

  function handleMinuteBlur() {
    const normalized =
      minuteStr === '' ? '00' : pad2(Math.min(59, parseInt(minuteStr, 10) || 0))
    setMinuteStr(normalized)
    commitTimeFields(hourStr || '00', normalized)
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
        onOpenAutoFocus={(event) => event.preventDefault()}
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
            <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
              {t('Time')}
            </span>
            <div className="flex items-center gap-1">
              <Input
                ref={hourRef}
                type="text"
                inputMode="numeric"
                autoComplete="off"
                maxLength={2}
                value={hourStr}
                onChange={(event) => handleHourChange(event.target.value)}
                onBlur={handleHourBlur}
                onPointerDown={(event) => event.stopPropagation()}
                disabled={disabled}
                aria-label={t('Hours')}
                placeholder="HH"
                className="h-7 w-11 px-1 text-center text-[12px] tabular-nums"
              />
              <span className="text-[13px] text-muted-foreground">:</span>
              <Input
                ref={minuteRef}
                type="text"
                inputMode="numeric"
                autoComplete="off"
                maxLength={2}
                value={minuteStr}
                onChange={(event) => handleMinuteChange(event.target.value)}
                onBlur={handleMinuteBlur}
                onPointerDown={(event) => event.stopPropagation()}
                disabled={disabled}
                aria-label={t('Minutes')}
                placeholder="mm"
                className="h-7 w-11 px-1 text-center text-[12px] tabular-nums"
              />
            </div>
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
