'use client'

import * as React from 'react'
import { format, subDays, startOfDay, endOfDay, isSameDay } from 'date-fns'
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
import { Label } from '@/components/ui/label'

export type DateRangePreset = {
  label: string
  value: string
  getRange: () => { from: Date; to: Date }
}

const PRESETS: DateRangePreset[] = [
  {
    label: 'Last 7 days',
    value: '7d',
    getRange: () => ({
      from: startOfDay(subDays(new Date(), 6)),
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
  {
    label: 'Last 90 days',
    value: '90d',
    getRange: () => ({
      from: startOfDay(subDays(new Date(), 89)),
      to: endOfDay(new Date()),
    }),
  },
  {
    label: 'Last 12 months',
    value: '12m',
    getRange: () => ({
      from: startOfDay(subDays(new Date(), 364)),
      to: endOfDay(new Date()),
    }),
  },
]

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
  const [selectedPreset, setSelectedPreset] = React.useState<string | null>(null)

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
      return format(range.from, 'MMM d, yyyy')
    }
    return `${format(range.from, 'MMM d')} - ${format(range.to, 'MMM d, yyyy')}`
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
      <PopoverContent className="w-auto p-0" align="end" sideOffset={4}>
        <div className="flex flex-col">
          {/* Presets */}
          <div className="border-b border-border p-3">
            <div className="space-y-1.5">
              <Label className="text-[11px] font-medium text-muted-foreground">
                Quick select
              </Label>
              <div className="grid grid-cols-2 gap-1.5">
                {PRESETS.map((preset) => (
                  <Button
                    key={preset.value}
                    variant={
                      selectedPreset === preset.value ? 'default' : 'outline'
                    }
                    size="sm"
                    className="h-8 text-[11px] justify-start"
                    onClick={() => handlePresetSelect(preset)}
                  >
                    {preset.label}
                  </Button>
                ))}
              </div>
            </div>
          </div>

          {/* Calendar */}
          <div className="p-3">
            <Calendar
              mode="range"
              selected={dateRange}
              onSelect={onDateRangeChange}
              numberOfMonths={2}
              defaultMonth={dateRange?.from || new Date()}
            />
          </div>

          {/* Actions */}
          <div className="border-t border-border p-3 flex items-center justify-between gap-2">
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
      </PopoverContent>
    </Popover>
  )
}
