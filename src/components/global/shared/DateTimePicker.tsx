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

function parseISO(value: string | null | undefined): Date | null {
  if (!value) return null
  const d = new Date(value)
  return Number.isNaN(d.getTime()) ? null : d
}

export function DateTimePicker({
  value,
  onChange,
  disabled,
  placeholder = 'Select date & time',
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
  const [timeStr, setTimeStr] = React.useState(() =>
    date ? format(date, 'HH:mm') : '',
  )

  React.useEffect(() => {
    setTimeStr(date ? format(date, 'HH:mm') : '')
  }, [date])

  const formatted = date ? formatDate(date, "MMM d, yyyy '·' HH:mm") : ''

  function commit(next: Date) {
    onChange(next.toISOString())
  }

  function handleSelectDate(next: Date | undefined) {
    if (!next) return
    const [hStr = '0', mStr = '0'] = (timeStr || '00:00').split(':')
    const h = parseInt(hStr, 10) || 0
    const m = parseInt(mStr, 10) || 0
    const merged = new Date(next)
    merged.setHours(h, m, 0, 0)
    commit(merged)
  }

  function handleTimeChange(t: string) {
    setTimeStr(t)
    const base = date ?? new Date()
    const [hStr = '0', mStr = '0'] = (t || '00:00').split(':')
    const h = parseInt(hStr, 10) || 0
    const m = parseInt(mStr, 10) || 0
    const merged = new Date(base)
    merged.setHours(h, m, 0, 0)
    commit(merged)
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
      >
        <div className="p-3">
          <Calendar
            mode="single"
            selected={date ?? undefined}
            onSelect={handleSelectDate}
            defaultMonth={date ?? new Date()}
          />
        </div>
        <div className="flex items-center justify-between gap-2 border-t border-border bg-muted/30 p-3">
          <label className="flex items-center gap-2">
            <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
              {t('Time')}
            </span>
            <Input
              type="time"
              value={timeStr}
              onChange={(e) => handleTimeChange(e.target.value)}
              className="h-7 w-[110px] cursor-pointer text-[12px]"
            />
          </label>
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
