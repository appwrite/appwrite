'use client'

import * as React from 'react'
import { format } from 'date-fns'
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

export interface DatePickerProps {
  value: Date | null | undefined
  onChange: (value: Date | null) => void
  disabled?: boolean
  placeholder?: string
  className?: string
  align?: 'start' | 'center' | 'end'
  clearable?: boolean
  size?: 'sm' | 'default'
  hideIcon?: boolean
  id?: string
  ariaLabel?: string
}

function startOfDay(date: Date): Date {
  const next = new Date(date)
  next.setHours(0, 0, 0, 0)
  return next
}

export function DatePicker({
  value,
  onChange,
  disabled,
  placeholder = 'Select date',
  className,
  align = 'start',
  clearable = true,
  size = 'default',
  hideIcon = false,
  id,
  ariaLabel,
}: DatePickerProps) {
  const t = useT()
  const [open, setOpen] = React.useState(false)
  const date = value ?? null
  const formatted = date ? format(date, 'MMM d, yyyy') : ''

  function commit(next: Date, close = true) {
    onChange(startOfDay(next))
    if (close) setOpen(false)
  }

  function handleSelectDate(next: Date | undefined) {
    if (!next) return
    commit(next)
  }

  function handleToday() {
    commit(new Date())
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
          type="button"
          variant="outline"
          disabled={disabled}
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
          <Button
            variant="ghost"
            size="sm"
            className="h-7 cursor-pointer text-[11px]"
            onClick={handleToday}
          >
            {t('Today')}
          </Button>
          <div className="flex items-center gap-2">
            {clearable && date ? (
              <Button
                variant="ghost"
                size="sm"
                className="h-7 cursor-pointer text-[11px]"
                onClick={handleClear}
              >
                {t('Clear')}
              </Button>
            ) : null}
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
