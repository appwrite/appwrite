'use client'

import * as React from 'react'
import { isSameDay } from 'date-fns'
import { Calendar as CalendarIcon, ChevronDown } from 'lucide-react'
import { DateRange } from 'react-day-picker'

import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Calendar } from '@/components/ui/calendar'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  findMatchingUsageDateRangePreset,
  USAGE_DATE_RANGE_PRESET_GROUPS,
  type UsageDateRangePreset,
} from '@/lib/usage/usage-date-range-presets'
import { isFullCalendarDayRange } from '@/lib/usage/usage-date-range'
import { useT } from '@/lib/i18n/translate'
import { useLocalizedDateFormat } from '@/lib/i18n/use-localized-date-format'

export type DateRangePreset = UsageDateRangePreset

const PRESET_GROUPS = USAGE_DATE_RANGE_PRESET_GROUPS

function findMatchingPreset(
  range: DateRange | undefined,
): UsageDateRangePreset | null {
  return findMatchingUsageDateRangePreset(range)
}

interface DateRangePickerProps {
  dateRange: DateRange | undefined
  onDateRangeChange: (range: DateRange | undefined) => void
  className?: string
  /** Radix PopoverContent `align` - default `end` for wide triggers in headers. */
  popoverContentAlign?: 'start' | 'center' | 'end'
}

export function DateRangePicker({
  dateRange,
  onDateRangeChange,
  className,
  popoverContentAlign = 'end',
}: DateRangePickerProps) {
  const t = useT()
  const { formatDate } = useLocalizedDateFormat()
  const [isOpen, setIsOpen] = React.useState(false)
  const [pendingDateRange, setPendingDateRange] = React.useState<
    DateRange | undefined
  >(dateRange)

  React.useEffect(() => {
    if (!isOpen) {
      setPendingDateRange(dateRange)
    }
  }, [dateRange, isOpen])

  const matchingPreset = React.useMemo(
    () => findMatchingPreset(dateRange),
    [dateRange],
  )

  const pendingMatchingPreset = React.useMemo(
    () => findMatchingPreset(pendingDateRange),
    [pendingDateRange],
  )

  const [selectedPreset, setSelectedPreset] = React.useState<string | null>(
    null,
  )

  React.useEffect(() => {
    const matching = isOpen ? pendingMatchingPreset : matchingPreset
    setSelectedPreset(matching?.value ?? null)
  }, [isOpen, matchingPreset?.value, pendingMatchingPreset?.value])

  const handleOpenChange = (open: boolean) => {
    if (open) {
      setPendingDateRange(dateRange)
      setIsOpen(true)
      return
    }

    if (isOpen) {
      setPendingDateRange(dateRange)
      setIsOpen(false)
    }
  }

  const handlePresetSelect = (preset: DateRangePreset) => {
    setPendingDateRange(preset.getRange())
    setSelectedPreset(preset.value)
  }

  const handleClear = () => {
    setPendingDateRange(undefined)
    setSelectedPreset(null)
  }

  const handleApply = () => {
    onDateRangeChange(pendingDateRange)
    setIsOpen(false)
  }

  const handleCancel = () => {
    setPendingDateRange(dateRange)
    setIsOpen(false)
  }

  const formatDateRange = (range: DateRange | undefined): string => {
    if (!range?.from) return t('Select date range')
    if (!range.to) {
      return formatDate(range.from, 'MMM d, yyyy')
    }
    if (isSameDay(range.from, range.to)) {
      if (isFullCalendarDayRange(range.from, range.to)) {
        return formatDate(range.from, 'MMM d, yyyy')
      }
      return `${formatDate(range.from, 'MMM d · h:mm a')} – ${formatDate(range.to, 'h:mm a')}`
    }
    if (isFullCalendarDayRange(range.from, range.to)) {
      return `${formatDate(range.from, 'MMM d')} - ${formatDate(range.to, 'MMM d, yyyy')}`
    }
    return `${formatDate(range.from, 'MMM d, h:mm a')} - ${formatDate(range.to, 'MMM d, h:mm a')}`
  }

  const triggerLabel = matchingPreset
    ? t(matchingPreset.label)
    : formatDateRange(dateRange)

  return (
    <Popover open={isOpen} onOpenChange={handleOpenChange}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className={cn(
            'h-8 gap-1.5 text-[12px] font-medium justify-start text-start min-w-[180px]',
            !dateRange && 'text-muted-foreground',
            className,
          )}
        >
          <CalendarIcon className="h-3.5 w-3.5 shrink-0" />
          <span className="flex-1 truncate text-start">
            {triggerLabel}
          </span>
          <ChevronDown className="h-3.5 w-3.5 opacity-50 shrink-0" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="w-auto max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-xl p-0 shadow-lg"
        align={popoverContentAlign}
        sideOffset={4}
      >
        <div className="flex flex-col min-[820px]:flex-row min-[820px]:items-stretch">
          {/* Quick select - same height as calendar column on wide layouts */}
          <div
            className={cn(
              'flex flex-col border-b border-border bg-muted/25',
              'min-[820px]:w-[220px] min-[820px]:shrink-0 min-[820px]:border-b-0 min-[820px]:border-e',
            )}
          >
            <div className="shrink-0 border-b border-border/80 px-3 py-2.5">
              <p className="text-[11px] font-semibold leading-none text-foreground">
                {t('Quick select')}
              </p>
              <p className="mt-1 text-[10px] leading-snug text-muted-foreground">
                {t('Common ranges or pick dates on the calendar')}
              </p>
            </div>
            <div className="flex min-h-0 flex-1 flex-col justify-start overflow-y-auto px-2 py-2 min-[820px]:px-2">
              {PRESET_GROUPS.map((group, groupIndex) => (
                <div
                  key={group.title}
                  className={cn(
                    groupIndex > 0 &&
                      'mt-3 border-t border-border/60 pt-3 min-[820px]:mt-2 min-[820px]:pt-2',
                  )}
                >
                  <p className="mb-1.5 px-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground min-[820px]:px-2">
                    {t(group.title)}
                  </p>
                  <div className="grid grid-cols-2 gap-1 min-[820px]:grid-cols-1 min-[820px]:gap-0.5">
                    {group.presets.map((preset) => {
                      const isSelected = selectedPreset === preset.value
                      return (
                        <button
                          key={preset.value}
                          type="button"
                          onClick={() => handlePresetSelect(preset)}
                          className={cn(
                            'cursor-pointer rounded-md px-2 py-2 text-start text-[12px] font-medium transition-colors',
                            'outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                            'min-[820px]:px-2.5 min-[820px]:py-1.5',
                            isSelected
                              ? 'bg-background text-foreground shadow-sm ring-1 ring-border'
                              : 'text-muted-foreground hover:bg-background/60 hover:text-foreground',
                          )}
                        >
                          {t(preset.label)}
                        </button>
                      )
                    })}
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="flex min-w-0 min-h-0 flex-1 flex-col bg-background min-[820px]:min-h-0">
            <div className="p-3">
              <Calendar
                mode="range"
                selected={pendingDateRange}
                onSelect={setPendingDateRange}
                numberOfMonths={2}
                defaultMonth={pendingDateRange?.from || dateRange?.from || new Date()}
              />
            </div>

            <div className="flex shrink-0 items-center justify-between gap-2 border-t border-border p-3">
              <Button
                variant="ghost"
                size="sm"
                className="h-7 text-[11px]"
                onClick={handleClear}
              >
                {t('Clear')}
              </Button>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  className="h-7 text-[11px]"
                  onClick={handleCancel}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  size="sm"
                  className="h-7 text-[11px]"
                  onClick={handleApply}
                >
                  {t('Apply')}
                </Button>
              </div>
            </div>
          </div>
        </div>
      </PopoverContent>
    </Popover>
  )
}
