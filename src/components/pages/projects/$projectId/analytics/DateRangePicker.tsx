'use client'

import * as React from 'react'
import {
  format,
  subDays,
  subHours,
  startOfDay,
  endOfDay,
  isSameDay,
} from 'date-fns'
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

export type DateRangePreset = {
  label: string
  value: string
  getRange: () => { from: Date; to: Date }
}

type PresetGroup = {
  title: string
  presets: DateRangePreset[]
}

const PRESET_GROUPS: PresetGroup[] = [
  {
    title: 'Recent',
    presets: [
      {
        label: 'Last hour',
        value: '1h',
        getRange: () => {
          const now = new Date()
          return { from: subHours(now, 1), to: now }
        },
      },
      {
        label: 'Last 6 hours',
        value: '6h',
        getRange: () => {
          const now = new Date()
          return { from: subHours(now, 6), to: now }
        },
      },
      {
        label: 'Last 24 hours',
        value: '24h',
        getRange: () => {
          const now = new Date()
          return { from: subHours(now, 24), to: now }
        },
      },
    ],
  },
  {
    title: 'Days',
    presets: [
      {
        label: 'Today',
        value: 'today',
        getRange: () => {
          const n = new Date()
          return { from: startOfDay(n), to: endOfDay(n) }
        },
      },
      {
        label: 'Last 7 days',
        value: '7d',
        getRange: () => ({
          from: startOfDay(subDays(new Date(), 6)),
          to: endOfDay(new Date()),
        }),
      },
      {
        label: 'Last 14 days',
        value: '14d',
        getRange: () => ({
          from: startOfDay(subDays(new Date(), 13)),
          to: endOfDay(new Date()),
        }),
      },
      {
        label: 'Last 30 days',
        value: '30d',
        getRange: () => ({
          from: startOfDay(subDays(new Date(), 29)),
          to: endOfDay(new Date()),
        }),
      },
    ],
  },
]

function isFullCalendarDay(from: Date, to: Date) {
  return (
    from.getTime() === startOfDay(from).getTime() &&
    to.getTime() === endOfDay(to).getTime()
  )
}

interface DateRangePickerProps {
  dateRange: DateRange | undefined
  onDateRangeChange: (range: DateRange | undefined) => void
  className?: string
}

export function DateRangePicker({
  dateRange,
  onDateRangeChange,
  className,
}: DateRangePickerProps) {
  const [isOpen, setIsOpen] = React.useState(false)
  const [selectedPreset, setSelectedPreset] = React.useState<string | null>(
    null,
  )

  const handlePresetSelect = (preset: DateRangePreset) => {
    const range = preset.getRange()
    onDateRangeChange(range)
    setSelectedPreset(preset.value)
  }

  const handleClear = () => {
    onDateRangeChange(undefined)
    setSelectedPreset(null)
  }

  const formatDateRange = (range: DateRange | undefined): string => {
    if (!range?.from) return 'Select date range'
    if (!range.to) {
      return format(range.from, 'MMM d, yyyy')
    }
    if (isSameDay(range.from, range.to)) {
      if (isFullCalendarDay(range.from, range.to)) {
        return format(range.from, 'MMM d, yyyy')
      }
      return `${format(range.from, 'MMM d · h:mm a')} – ${format(range.to, 'h:mm a')}`
    }
    if (isFullCalendarDay(range.from, range.to)) {
      return `${format(range.from, 'MMM d')} - ${format(range.to, 'MMM d, yyyy')}`
    }
    return `${format(range.from, 'MMM d, h:mm a')} - ${format(range.to, 'MMM d, h:mm a')}`
  }

  return (
    <Popover open={isOpen} onOpenChange={setIsOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className={cn(
            'h-8 gap-1.5 text-[12px] font-medium justify-start text-left min-w-[180px]',
            !dateRange && 'text-muted-foreground',
            className,
          )}
        >
          <CalendarIcon className="h-3.5 w-3.5 shrink-0" />
          <span className="flex-1 truncate text-left">
            {formatDateRange(dateRange)}
          </span>
          <ChevronDown className="h-3.5 w-3.5 opacity-50 shrink-0" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="w-auto max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-xl p-0 shadow-lg"
        align="end"
        sideOffset={4}
      >
        <div className="flex flex-col min-[820px]:flex-row min-[820px]:items-stretch">
          {/* Quick select — same height as calendar column on wide layouts */}
          <div
            className={cn(
              'flex flex-col border-b border-border bg-muted/25',
              'min-[820px]:w-[220px] min-[820px]:shrink-0 min-[820px]:border-b-0 min-[820px]:border-r',
            )}
          >
            <div className="shrink-0 border-b border-border/80 px-3 py-2.5">
              <p className="text-[11px] font-semibold leading-none text-foreground">
                Quick select
              </p>
              <p className="mt-1 text-[10px] leading-snug text-muted-foreground">
                Common ranges or pick dates on the calendar
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
                    {group.title}
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
                            'cursor-pointer rounded-md px-2 py-2 text-left text-[12px] font-medium transition-colors',
                            'outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                            'min-[820px]:px-2.5 min-[820px]:py-1.5',
                            isSelected
                              ? 'bg-background text-foreground shadow-sm ring-1 ring-border'
                              : 'text-muted-foreground hover:bg-background/60 hover:text-foreground',
                          )}
                        >
                          {preset.label}
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
                selected={dateRange}
                onSelect={onDateRangeChange}
                numberOfMonths={2}
                defaultMonth={dateRange?.from || new Date()}
              />
            </div>

            <div className="flex shrink-0 items-center justify-between gap-2 border-t border-border p-3">
              <Button
                variant="ghost"
                size="sm"
                className="h-7 text-[11px]"
                onClick={handleClear}
              >
                Clear
              </Button>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  className="h-7 text-[11px]"
                  onClick={() => setIsOpen(false)}
                >
                  Cancel
                </Button>
                <Button
                  size="sm"
                  className="h-7 text-[11px]"
                  onClick={() => setIsOpen(false)}
                >
                  Apply
                </Button>
              </div>
            </div>
          </div>
        </div>
      </PopoverContent>
    </Popover>
  )
}
