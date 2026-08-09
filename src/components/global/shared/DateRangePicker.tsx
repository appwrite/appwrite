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
  getUsageDateRangePresetByValue,
  USAGE_DATE_RANGE_PRESET_GROUPS,
  type UsageDateRangePreset,
} from '@/lib/usage/usage-date-range-presets'
import { isFullCalendarDayRange, normalizeUsageDateRangeSelection } from '@/lib/usage/usage-date-range'
import { useT } from '@/lib/i18n/translate'
import { useLocalizedDateFormat } from '@/lib/i18n/use-localized-date-format'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

export type DateRangePreset = UsageDateRangePreset

const PRESET_GROUPS = USAGE_DATE_RANGE_PRESET_GROUPS
const WIDE_LAYOUT_MIN_WIDTH = 820

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
  /**
   * Authoritative quick-select id when known (e.g. saved usage prefs).
   * Preferred over inferring from snapshotted `dateRange` timestamps.
   */
  presetId?: string | null
}

export function DateRangePicker({
  dateRange,
  onDateRangeChange,
  className,
  popoverContentAlign = 'end',
  presetId = null,
}: DateRangePickerProps) {
  const t = useT()
  const { formatDate } = useLocalizedDateFormat()
  const isWideLayout = useMediaMinWidth(WIDE_LAYOUT_MIN_WIDTH)
  const [isOpen, setIsOpen] = React.useState(false)
  const [pendingDateRange, setPendingDateRange] = React.useState<
    DateRange | undefined
  >(dateRange)

  React.useEffect(() => {
    if (!isOpen) {
      setPendingDateRange(dateRange)
    }
  }, [dateRange, isOpen])

  const matchingPreset = React.useMemo(() => {
    if (presetId) {
      return getUsageDateRangePresetByValue(presetId) ?? findMatchingPreset(dateRange)
    }
    return findMatchingPreset(dateRange)
  }, [dateRange, presetId])

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
      // Refresh rolling presets to a live window so quick-select stays matched.
      if (presetId) {
        const preset = getUsageDateRangePresetByValue(presetId)
        setPendingDateRange(preset ? preset.getRange() : dateRange)
      } else {
        setPendingDateRange(dateRange)
      }
      setIsOpen(true)
      return
    }

    if (isOpen) {
      setPendingDateRange(dateRange)
      setIsOpen(false)
    }
  }

  const handlePresetSelect = (preset: DateRangePreset) => {
    const range = preset.getRange()
    setPendingDateRange(range)
    setSelectedPreset(preset.value)
    onDateRangeChange(range)
    setIsOpen(false)
  }

  const handlePresetValueChange = (value: string) => {
    const preset = getUsageDateRangePresetByValue(value)
    if (preset) {
      handlePresetSelect(preset)
    }
  }

  const handleClear = () => {
    setPendingDateRange(undefined)
    setSelectedPreset(null)
  }

  const handleApply = () => {
    onDateRangeChange(normalizeUsageDateRangeSelection(pendingDateRange))
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

  const pendingSummary = pendingMatchingPreset
    ? t(pendingMatchingPreset.label)
    : formatDateRange(pendingDateRange)

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
          <span className="flex-1 truncate text-start">{triggerLabel}</span>
          <ChevronDown className="h-3.5 w-3.5 opacity-50 shrink-0" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className={cn(
          'overflow-hidden rounded-xl p-0 shadow-lg',
          isWideLayout
            ? 'w-auto max-w-[calc(100vw-1rem)]'
            : 'w-[min(calc(100vw-1rem),21.5rem)]',
        )}
        align={popoverContentAlign}
        sideOffset={4}
        collisionPadding={8}
      >
        {isWideLayout ? (
          <div className="flex flex-row items-stretch">
            <div className="flex w-[220px] shrink-0 flex-col border-e border-border bg-muted/25">
              <div className="shrink-0 border-b border-border/80 px-3 py-2.5">
                <p className="text-[11px] font-semibold leading-none text-foreground">
                  {t('Quick select')}
                </p>
              </div>
              <div className="flex min-h-0 flex-1 flex-col justify-start px-2 py-2">
                {PRESET_GROUPS.map((group, groupIndex) => (
                  <div
                    key={group.title ?? `group-${groupIndex}`}
                    className={cn(
                      groupIndex > 0 && 'mt-2 border-t border-border/60 pt-2',
                    )}
                  >
                    {group.title ? (
                      <p className="mb-1.5 px-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {t(group.title)}
                      </p>
                    ) : null}
                    <div className="grid grid-cols-1 gap-0.5">
                      {group.presets.map((preset) => {
                        const isSelected = selectedPreset === preset.value
                        return (
                          <button
                            key={preset.value}
                            type="button"
                            onClick={() => handlePresetSelect(preset)}
                            className={cn(
                              'cursor-pointer rounded-md px-2.5 py-1.5 text-start text-[12px] font-medium transition-colors',
                              'outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
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

            <div className="flex min-h-0 min-w-0 flex-1 flex-col bg-background">
              <div className="p-3">
                <Calendar
                  mode="range"
                  selected={pendingDateRange}
                  onSelect={setPendingDateRange}
                  numberOfMonths={2}
                  defaultMonth={
                    pendingDateRange?.from || dateRange?.from || new Date()
                  }
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
        ) : (
          <div className="flex flex-col bg-background">
            <div className="border-b border-border px-3.5 py-3">
              <label
                htmlFor="date-range-quick-select"
                className="mb-1.5 block text-[10px] font-semibold uppercase tracking-wider text-muted-foreground"
              >
                {t('Quick select')}
              </label>
              <Select
                value={selectedPreset ?? undefined}
                onValueChange={handlePresetValueChange}
              >
                <SelectTrigger
                  id="date-range-quick-select"
                  size="sm"
                  className="h-9 w-full min-w-0 bg-muted/30 text-[13px]"
                >
                  <SelectValue placeholder={pendingSummary} />
                </SelectTrigger>
                <SelectContent
                  position="popper"
                  className="z-[10060]"
                  align="start"
                >
                  {PRESET_GROUPS.map((group, groupIndex) => (
                    <SelectGroup key={group.title ?? `group-${groupIndex}`}>
                      {group.title ? (
                        <SelectLabel className="text-[10px] font-semibold uppercase tracking-wider">
                          {t(group.title)}
                        </SelectLabel>
                      ) : null}
                      {group.presets.map((preset) => (
                        <SelectItem
                          key={preset.value}
                          value={preset.value}
                          className="text-[13px]"
                        >
                          {t(preset.label)}
                        </SelectItem>
                      ))}
                    </SelectGroup>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="px-2.5 pt-2 pb-1">
              <Calendar
                mode="range"
                selected={pendingDateRange}
                onSelect={setPendingDateRange}
                numberOfMonths={1}
                defaultMonth={
                  pendingDateRange?.from || dateRange?.from || new Date()
                }
                className="mx-auto w-full p-0 [--cell-size:2.25rem]"
                classNames={{
                  root: 'w-full',
                  months: 'flex w-full flex-col relative',
                  month: 'flex w-full flex-col gap-2',
                  week: 'flex w-full mt-1',
                  weekdays: 'flex w-full',
                  caption_label: 'select-none font-medium text-[13px]',
                }}
              />
            </div>

            <div className="flex shrink-0 items-center justify-between gap-2 border-t border-border bg-muted/20 px-3.5 py-2.5">
              <Button
                variant="ghost"
                size="sm"
                className="h-8 px-2.5 text-[12px]"
                onClick={handleClear}
              >
                {t('Clear')}
              </Button>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 px-3 text-[12px]"
                  onClick={handleCancel}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  size="sm"
                  className="h-8 px-3 text-[12px]"
                  onClick={handleApply}
                >
                  {t('Apply')}
                </Button>
              </div>
            </div>
          </div>
        )}
      </PopoverContent>
    </Popover>
  )
}
