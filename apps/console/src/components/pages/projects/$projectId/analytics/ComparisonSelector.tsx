'use client'

import * as React from 'react'
import { subDays, subYears, startOfDay, endOfDay } from 'date-fns'
import { DateRange } from 'react-day-picker'
import { ChevronDown, TrendingUp } from 'lucide-react'

import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu'
import { useT } from '@/lib/i18n/translate'

export type ComparisonType =
  | 'none'
  | 'previous-period'
  | 'previous-year'
  | 'same-period-last-year'

interface ComparisonSelectorProps {
  dateRange: DateRange | undefined
  comparisonType: ComparisonType
  onComparisonTypeChange: (type: ComparisonType) => void
  onComparisonRangeChange: (range: DateRange | undefined) => void
  className?: string
}

const COMPARISON_OPTIONS: Array<{
  value: ComparisonType
  label: string
  description: string
  getRange: (mainRange: DateRange | undefined) => DateRange | undefined
}> = [
  {
    value: 'none',
    label: 'No comparison',
    description: 'Show only current period',
    getRange: () => undefined,
  },
  {
    value: 'previous-period',
    label: 'Previous period',
    description: 'Same length period before',
    getRange: (mainRange) => {
      if (!mainRange?.from || !mainRange?.to) return undefined
      const daysDiff = Math.ceil(
        (mainRange.to.getTime() - mainRange.from.getTime()) /
          (1000 * 60 * 60 * 24),
      )
      return {
        from: startOfDay(subDays(mainRange.from, daysDiff + 1)),
        to: endOfDay(subDays(mainRange.from, 1)),
      }
    },
  },
  {
    value: 'previous-year',
    label: 'Previous year',
    description: 'Same dates, previous year',
    getRange: (mainRange) => {
      if (!mainRange?.from || !mainRange?.to) return undefined
      return {
        from: startOfDay(subYears(mainRange.from, 1)),
        to: endOfDay(subYears(mainRange.to, 1)),
      }
    },
  },
  {
    value: 'same-period-last-year',
    label: 'Same period last year',
    description: 'Same dates, one year ago',
    getRange: (mainRange) => {
      if (!mainRange?.from || !mainRange?.to) return undefined
      return {
        from: startOfDay(subYears(mainRange.from, 1)),
        to: endOfDay(subYears(mainRange.to, 1)),
      }
    },
  },
]

export function ComparisonSelector({
  dateRange,
  comparisonType,
  onComparisonTypeChange,
  onComparisonRangeChange,
  className,
}: ComparisonSelectorProps) {
  const t = useT()
  const selectedOption = COMPARISON_OPTIONS.find(
    (opt) => opt.value === comparisonType,
  )

  // Calculate comparison range when date range or comparison type changes
  React.useEffect(() => {
    if (comparisonType === 'none') {
      onComparisonRangeChange(undefined)
      return
    }

    const option = COMPARISON_OPTIONS.find(
      (opt) => opt.value === comparisonType,
    )
    if (option) {
      const comparisonRange = option.getRange(dateRange)
      onComparisonRangeChange(comparisonRange)
    }
  }, [dateRange, comparisonType, onComparisonRangeChange])

  const handleSelect = (type: ComparisonType) => {
    onComparisonTypeChange(type)
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className={cn(
            'h-8 gap-1.5 text-[12px] font-medium',
            comparisonType === 'none' && 'text-muted-foreground',
            className,
          )}
        >
          <TrendingUp className="h-3.5 w-3.5" />
          <span className="min-w-[120px] text-start">
            {selectedOption ? t(selectedOption.label) : t('Compare')}
          </span>
          <ChevronDown className="h-3.5 w-3.5 opacity-50" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-[200px]">
        {COMPARISON_OPTIONS.map((option, index) => (
          <React.Fragment key={option.value}>
            {index > 0 && <DropdownMenuSeparator />}
            <DropdownMenuItem
              onClick={() => handleSelect(option.value)}
              className={cn(
                'flex flex-col items-start gap-0.5 py-2.5 cursor-pointer',
                comparisonType === option.value && 'bg-accent',
              )}
            >
              <span className="text-[12px] font-medium">{t(option.label)}</span>
              <span className="text-[11px] text-muted-foreground">
                {t(option.description)}
              </span>
            </DropdownMenuItem>
          </React.Fragment>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
