import { useState, useEffect, useMemo } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { Button } from '@/components/ui/button'
import {
  Clock,
  Code,
  AlertCircle,
  CheckCircle2,
  ChevronDown,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useT } from '@/lib/i18n/translate'

type SchedulePreset =
  | 'disabled'
  | 'every-minute'
  | 'every-5-minutes'
  | 'every-15-minutes'
  | 'every-30-minutes'
  | 'every-hour'
  | 'every-6-hours'
  | 'every-12-hours'
  | 'daily-midnight'
  | 'daily-noon'
  | 'twice-daily'
  | 'weekly-sunday'
  | 'weekly-monday'
  | 'weekly-tuesday'
  | 'weekly-wednesday'
  | 'weekly-thursday'
  | 'weekly-friday'
  | 'weekly-saturday'
  | 'monthly-1st'
  | 'monthly-15th'

interface CronScheduleEditorProps {
  value: string
  onChange: (value: string) => void
  disabled?: boolean
  /** When false, hides the Disabled (no schedule) preset. Defaults to true. */
  allowDisabled?: boolean
  /** Copy for the Disabled preset. Defaults to function schedule wording. */
  disabledDescription?: string
}

const FALLBACK_SCHEDULE_PRESET: SchedulePreset = 'weekly-monday'

// Preset categories
type PresetCategory = 'frequent' | 'daily' | 'weekly' | 'monthly'

interface PresetOption {
  value: SchedulePreset
  label: string
  description: string
  cron: string
  category: PresetCategory
}

// All preset options organized by category
export const PRESET_OPTIONS: PresetOption[] = [
  // Frequent
  {
    value: 'every-minute',
    label: 'Every minute',
    description: 'Runs every minute',
    cron: '* * * * *',
    category: 'frequent',
  },
  {
    value: 'every-5-minutes',
    label: 'Every 5 minutes',
    description: 'Runs every 5 minutes',
    cron: '*/5 * * * *',
    category: 'frequent',
  },
  {
    value: 'every-15-minutes',
    label: 'Every 15 minutes',
    description: 'Runs every 15 minutes',
    cron: '*/15 * * * *',
    category: 'frequent',
  },
  {
    value: 'every-30-minutes',
    label: 'Every 30 minutes',
    description: 'Runs every 30 minutes',
    cron: '*/30 * * * *',
    category: 'frequent',
  },
  {
    value: 'every-hour',
    label: 'Every hour',
    description: 'Runs at the start of every hour',
    cron: '0 * * * *',
    category: 'frequent',
  },

  // Daily
  {
    value: 'every-6-hours',
    label: 'Every 6 hours',
    description: 'Runs every 6 hours (00:00, 06:00, 12:00, 18:00)',
    cron: '0 */6 * * *',
    category: 'daily',
  },
  {
    value: 'every-12-hours',
    label: 'Every 12 hours',
    description: 'Runs every 12 hours (00:00, 12:00)',
    cron: '0 */12 * * *',
    category: 'daily',
  },
  {
    value: 'daily-midnight',
    label: 'Daily at midnight',
    description: 'Runs once per day at midnight (00:00)',
    cron: '0 0 * * *',
    category: 'daily',
  },
  {
    value: 'daily-noon',
    label: 'Daily at noon',
    description: 'Runs once per day at noon (12:00)',
    cron: '0 12 * * *',
    category: 'daily',
  },
  {
    value: 'twice-daily',
    label: 'Twice daily',
    description: 'Runs twice per day (09:00, 21:00)',
    cron: '0 9,21 * * *',
    category: 'daily',
  },

  // Weekly
  {
    value: 'weekly-sunday',
    label: 'Weekly on Sunday',
    description: 'Runs once per week on Sunday at midnight',
    cron: '0 0 * * 0',
    category: 'weekly',
  },
  {
    value: 'weekly-monday',
    label: 'Weekly on Monday',
    description: 'Runs once per week on Monday at midnight',
    cron: '0 0 * * 1',
    category: 'weekly',
  },
  {
    value: 'weekly-tuesday',
    label: 'Weekly on Tuesday',
    description: 'Runs once per week on Tuesday at midnight',
    cron: '0 0 * * 2',
    category: 'weekly',
  },
  {
    value: 'weekly-wednesday',
    label: 'Weekly on Wednesday',
    description: 'Runs once per week on Wednesday at midnight',
    cron: '0 0 * * 3',
    category: 'weekly',
  },
  {
    value: 'weekly-thursday',
    label: 'Weekly on Thursday',
    description: 'Runs once per week on Thursday at midnight',
    cron: '0 0 * * 4',
    category: 'weekly',
  },
  {
    value: 'weekly-friday',
    label: 'Weekly on Friday',
    description: 'Runs once per week on Friday at midnight',
    cron: '0 0 * * 5',
    category: 'weekly',
  },
  {
    value: 'weekly-saturday',
    label: 'Weekly on Saturday',
    description: 'Runs once per week on Saturday at midnight',
    cron: '0 0 * * 6',
    category: 'weekly',
  },

  // Monthly
  {
    value: 'monthly-1st',
    label: 'Monthly on the 1st',
    description: 'Runs once per month on the 1st at midnight',
    cron: '0 0 1 * *',
    category: 'monthly',
  },
  {
    value: 'monthly-15th',
    label: 'Monthly on the 15th',
    description: 'Runs once per month on the 15th at midnight',
    cron: '0 0 15 * *',
    category: 'monthly',
  },
]

// Helper to get presets by category
const getPresetsByCategory = (category: PresetCategory) => {
  return PRESET_OPTIONS.filter((preset) => preset.category === category)
}

// Validate cron expression (basic validation)
function validateCronExpression(cron: string): {
  valid: boolean
  error?: string
} {
  if (!cron.trim()) {
    return { valid: true } // Empty is valid (disabled)
  }

  const parts = cron.trim().split(/\s+/)
  if (parts.length !== 5) {
    return {
      valid: false,
      error:
        'Cron expression must have 5 parts (minute hour day month weekday)',
    }
  }

  // Basic validation for each part
  const patterns = [
    /^(\*|[0-5]?\d)(-(\*|[0-5]?\d))?(\/(\d+))?$/, // minute: 0-59
    /^(\*|[01]?\d|2[0-3])(-(\*|[01]?\d|2[0-3]))?(\/(\d+))?$/, // hour: 0-23
    /^(\*|[12]?\d|3[01])(-(\*|[12]?\d|3[01]))?(\/(\d+))?$/, // day: 1-31
    /^(\*|[1-9]|1[0-2])(-(\*|[1-9]|1[0-2]))?(\/(\d+))?$/, // month: 1-12
    /^(\*|[0-6])(-(\*|[0-6]))?(\/(\d+))?$/, // weekday: 0-6
  ]

  for (let i = 0; i < parts.length; i++) {
    if (parts[i] !== '*' && !patterns[i].test(parts[i])) {
      return {
        valid: false,
        error: `Invalid value in ${['minute', 'hour', 'day', 'month', 'weekday'][i]} field`,
      }
    }
  }

  return { valid: true }
}

// Parse cron expression to human-readable format
export function formatCronExpression(cron: string): string {
  if (!cron.trim()) {
    return 'Disabled'
  }

  const parts = cron.trim().split(/\s+/)
  if (parts.length !== 5) {
    return cron
  }

  const [minute, hour, day, month, weekday] = parts

  // Check if it matches a preset
  const matchingPreset = PRESET_OPTIONS.find((p) => p.cron === cron)
  if (matchingPreset) {
    return matchingPreset.label
  }

  // Custom format
  let description = ''

  // Check for interval patterns (e.g., */5, */15)
  const minuteInterval = minute.match(/^\*\/(\d+)$/)
  const hourInterval = hour.match(/^\*\/(\d+)$/)

  // Time
  if (hour === '*' && minute === '*') {
    description = 'Every minute'
  } else if (minuteInterval) {
    // Handle minute intervals (e.g., */5, */15, */30)
    const interval = minuteInterval[1]
    description = `Every ${interval} minute${interval !== '1' ? 's' : ''}`
  } else if (hourInterval) {
    // Handle hour intervals (e.g., */6, */12)
    const interval = hourInterval[1]
    description = `Every ${interval} hour${interval !== '1' ? 's' : ''}`
  } else if (hour === '*' && minute !== '*') {
    const minuteNum = parseInt(minute, 10)
    if (!isNaN(minuteNum)) {
      description = `Every hour at minute ${minuteNum}`
    } else {
      description = `Every hour at minute ${minute}`
    }
  } else if (hour !== '*' && minute === '*') {
    const hourNum = parseInt(hour, 10)
    if (!isNaN(hourNum)) {
      description = `Every minute of hour ${hourNum}`
    } else {
      description = `Every minute of hour ${hour}`
    }
  } else if (hour !== '*' && minute !== '*') {
    const hourNum = parseInt(hour, 10)
    const minuteNum = parseInt(minute, 10)
    if (!isNaN(hourNum) && !isNaN(minuteNum)) {
      const period = hourNum >= 12 ? 'PM' : 'AM'
      const displayHour =
        hourNum === 0 ? 12 : hourNum > 12 ? hourNum - 12 : hourNum
      description = `At ${displayHour}:${minuteNum.toString().padStart(2, '0')} ${period}`
    } else {
      // Fallback for complex expressions
      description = `At ${hour}:${minute}`
    }
  }

  // Day
  if (day !== '*') {
    description += ` on day ${day}`
  }

  // Month
  if (month !== '*') {
    description += ` of month ${month}`
  }

  // Weekday
  if (weekday !== '*') {
    const dayNames = [
      'Sunday',
      'Monday',
      'Tuesday',
      'Wednesday',
      'Thursday',
      'Friday',
      'Saturday',
    ]
    const dayIndex = parseInt(weekday, 10)
    if (dayIndex >= 0 && dayIndex < 7) {
      description += ` on ${dayNames[dayIndex]}`
    }
  }

  return description || cron
}

function getCronFromPreset(preset: SchedulePreset): string {
  if (preset === 'disabled') return ''
  return PRESET_OPTIONS.find((p) => p.value === preset)?.cron ?? ''
}

export function CronScheduleEditor({
  value,
  onChange,
  disabled,
  allowDisabled = true,
  disabledDescription = 'Function will not run on a schedule',
}: CronScheduleEditorProps) {
  const t = useT()
  const [mode, setMode] = useState<'preset' | 'advanced'>('preset')
  const [preset, setPreset] = useState<SchedulePreset>(
    allowDisabled ? 'disabled' : FALLBACK_SCHEDULE_PRESET,
  )
  const [customCron, setCustomCron] = useState('')
  const [popoverOpen, setPopoverOpen] = useState(false)

  const selectPreset = (nextPreset: SchedulePreset) => {
    if (!allowDisabled && nextPreset === 'disabled') {
      nextPreset = FALLBACK_SCHEDULE_PRESET
    }
    setPreset(nextPreset)
    onChange(getCronFromPreset(nextPreset))
  }

  // Sheet/Dialog RemoveScroll calls preventDefault on wheel for body-portaled
  // popovers. A non-passive listener re-applies the delta to the list.
  const [presetListEl, setPresetListEl] = useState<HTMLDivElement | null>(null)
  useEffect(() => {
    if (!presetListEl || !popoverOpen) return
    const onWheel = (event: globalThis.WheelEvent) => {
      const { scrollTop, scrollHeight, clientHeight } = presetListEl
      if (scrollHeight <= clientHeight) return
      const next = Math.min(
        Math.max(scrollTop + event.deltaY, 0),
        scrollHeight - clientHeight,
      )
      if (next === scrollTop) return
      presetListEl.scrollTop = next
      event.preventDefault()
      event.stopPropagation()
    }
    presetListEl.addEventListener('wheel', onWheel, { passive: false })
    return () => {
      presetListEl.removeEventListener('wheel', onWheel)
    }
  }, [presetListEl, popoverOpen])

  // Sync internal editor state when the value prop changes externally.
  useEffect(() => {
    if (!value || !value.trim()) {
      if (!allowDisabled) {
        setPreset(FALLBACK_SCHEDULE_PRESET)
        setMode('preset')
        setCustomCron('')
        onChange(getCronFromPreset(FALLBACK_SCHEDULE_PRESET))
        return
      }
      setPreset('disabled')
      setMode('preset')
      setCustomCron('')
      return
    }

    const matchingPreset = PRESET_OPTIONS.find((p) => p.cron === value)
    if (matchingPreset) {
      setPreset(matchingPreset.value)
      setMode('preset')
      return
    }

    setCustomCron(value)
    setMode('advanced')
    // Intentionally omit onChange: parent handlers are often inline.
  }, [value, allowDisabled])

  const validation = useMemo(() => {
    let currentValue = ''
    if (mode === 'advanced') {
      currentValue = customCron
    } else if (preset === 'disabled') {
      currentValue = ''
    } else {
      const presetOption = PRESET_OPTIONS.find((p) => p.value === preset)
      currentValue = presetOption?.cron || ''
    }
    return validateCronExpression(currentValue)
  }, [mode, preset, customCron])

  const formattedSchedule = useMemo(() => {
    let currentValue = ''
    if (mode === 'advanced') {
      currentValue = customCron
    } else if (preset === 'disabled') {
      currentValue = ''
    } else {
      const presetOption = PRESET_OPTIONS.find((p) => p.value === preset)
      currentValue = presetOption?.cron || ''
    }
    return formatCronExpression(currentValue)
  }, [mode, preset, customCron])

  const handleModeChange = (newMode: 'preset' | 'advanced') => {
    if (newMode === 'preset') {
      // When switching to preset, try to match current value to a preset
      let currentValue = ''
      if (mode === 'advanced') {
        currentValue = customCron
      } else if (preset === 'disabled') {
        currentValue = ''
      } else {
        const presetOption = PRESET_OPTIONS.find((p) => p.value === preset)
        currentValue = presetOption?.cron || ''
      }

      let nextPreset: SchedulePreset = allowDisabled
        ? 'disabled'
        : FALLBACK_SCHEDULE_PRESET
      if (currentValue.trim()) {
        const matchingPreset = PRESET_OPTIONS.find(
          (p) => p.cron === currentValue,
        )
        if (matchingPreset) {
          nextPreset = matchingPreset.value
        } else if (!allowDisabled) {
          nextPreset = FALLBACK_SCHEDULE_PRESET
        }
      }

      setPreset(nextPreset)
      setMode('preset')
      onChange(getCronFromPreset(nextPreset))
    } else {
      // When switching to advanced, set customCron to current value
      let currentValue = ''
      if (preset === 'disabled') {
        currentValue = ''
      } else {
        const presetOption = PRESET_OPTIONS.find((p) => p.value === preset)
        currentValue = presetOption?.cron || ''
      }
      setCustomCron(currentValue)
      setMode('advanced')
      onChange(currentValue)
    }
  }

  // Get display label for selected preset
  const getPresetDisplayLabel = (presetValue: SchedulePreset): string => {
    if (presetValue === 'disabled') return 'Disabled'
    const preset = PRESET_OPTIONS.find((p) => p.value === presetValue)
    return preset?.label || 'Unknown'
  }

  return (
    <div className="space-y-4">
      {/* Mode Toggle */}
      <div className="flex items-center gap-3">
        <div className="inline-flex items-center rounded-md border border-border bg-muted/30 p-1">
          <button
            type="button"
            onClick={() => handleModeChange('preset')}
            disabled={disabled}
            className={cn(
              'flex items-center gap-1.5 rounded px-2.5 py-1 text-[12px] font-medium transition-all cursor-pointer',
              mode === 'preset'
                ? 'bg-background text-foreground'
                : 'text-muted-foreground hover:text-foreground',
              disabled && 'opacity-50 cursor-not-allowed',
            )}
          >
            <Clock className="h-3.5 w-3.5" />
            {t('Preset')}
          </button>
          <button
            type="button"
            onClick={() => handleModeChange('advanced')}
            disabled={disabled}
            className={cn(
              'flex items-center gap-1.5 rounded px-2.5 py-1 text-[12px] font-medium transition-all cursor-pointer',
              mode === 'advanced'
                ? 'bg-background text-foreground'
                : 'text-muted-foreground hover:text-foreground',
              disabled && 'opacity-50 cursor-not-allowed',
            )}
          >
            <Code className="h-3.5 w-3.5" />
            {t('Advanced')}
          </button>
        </div>
      </div>

      {/* Preset Mode */}
      {mode === 'preset' && (
        <div className="space-y-4">
          <div className="space-y-2">
            <Label
              htmlFor="schedule-preset"
              className="text-[13px] font-medium"
            >
              {t('Schedule preset')}
            </Label>
            <Popover open={popoverOpen} onOpenChange={setPopoverOpen} modal>
              <PopoverTrigger asChild>
                <Button
                  id="schedule-preset"
                  type="button"
                  variant="outline"
                  role="combobox"
                  className={cn(
                    'h-9 w-full justify-between text-[13px] font-normal',
                    !preset && 'text-muted-foreground',
                  )}
                  disabled={disabled}
                >
                  {t(getPresetDisplayLabel(preset || 'disabled'))}
                  <ChevronDown className="ms-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
              </PopoverTrigger>
              <PopoverContent
                className="flex max-h-[min(320px,var(--radix-popover-content-available-height))] w-[var(--radix-popover-trigger-width)] min-w-[280px] flex-col overflow-hidden p-0"
                align="start"
              >
                <Command className="flex max-h-[min(320px,var(--radix-popover-content-available-height))] flex-col overflow-hidden">
                  <CommandInput
                    placeholder={t('Search presets...')}
                    className="h-9"
                  />
                  <CommandList
                    ref={setPresetListEl}
                    className="min-h-0 max-h-[240px] flex-1 overflow-y-auto overscroll-contain"
                  >
                    <CommandEmpty>{t('No preset found.')}</CommandEmpty>

                    {allowDisabled ? (
                      <CommandGroup>
                        <CommandItem
                          value="disabled"
                          onSelect={() => {
                            selectPreset('disabled')
                            setPopoverOpen(false)
                          }}
                          className="px-3 py-2.5"
                        >
                          <div className="flex flex-col gap-0.5">
                            <span className="text-[13px] font-medium">
                              {t('Disabled')}
                            </span>
                            <span className="text-[11px] text-muted-foreground leading-tight">
                              {t(disabledDescription)}
                            </span>
                          </div>
                        </CommandItem>
                      </CommandGroup>
                    ) : null}

                    {/* Frequent */}
                    <CommandGroup heading={t('Frequent')}>
                      {getPresetsByCategory('frequent').map((presetOption) => (
                        <CommandItem
                          key={presetOption.value}
                          value={`${presetOption.label} ${presetOption.description} ${presetOption.cron}`}
                          onSelect={() => {
                            selectPreset(presetOption.value)
                            setPopoverOpen(false)
                          }}
                          className="px-3 py-2.5"
                        >
                          <div className="flex flex-col gap-0.5">
                            <span className="text-[13px] font-medium">
                              {t(presetOption.label)}
                            </span>
                            <span className="text-[11px] text-muted-foreground leading-tight">
                              {t(presetOption.description)} •{' '}
                              <span className="font-mono">
                                {presetOption.cron}
                              </span>
                            </span>
                          </div>
                        </CommandItem>
                      ))}
                    </CommandGroup>

                    {/* Daily */}
                    <CommandGroup heading={t('Daily')}>
                      {getPresetsByCategory('daily').map((presetOption) => (
                        <CommandItem
                          key={presetOption.value}
                          value={`${presetOption.label} ${presetOption.description} ${presetOption.cron}`}
                          onSelect={() => {
                            selectPreset(presetOption.value)
                            setPopoverOpen(false)
                          }}
                          className="px-3 py-2.5"
                        >
                          <div className="flex flex-col gap-0.5">
                            <span className="text-[13px] font-medium">
                              {t(presetOption.label)}
                            </span>
                            <span className="text-[11px] text-muted-foreground leading-tight">
                              {t(presetOption.description)} •{' '}
                              <span className="font-mono">
                                {presetOption.cron}
                              </span>
                            </span>
                          </div>
                        </CommandItem>
                      ))}
                    </CommandGroup>

                    {/* Weekly */}
                    <CommandGroup heading={t('Weekly')}>
                      {getPresetsByCategory('weekly').map((presetOption) => (
                        <CommandItem
                          key={presetOption.value}
                          value={`${presetOption.label} ${presetOption.description} ${presetOption.cron}`}
                          onSelect={() => {
                            selectPreset(presetOption.value)
                            setPopoverOpen(false)
                          }}
                          className="px-3 py-2.5"
                        >
                          <div className="flex flex-col gap-0.5">
                            <span className="text-[13px] font-medium">
                              {t(presetOption.label)}
                            </span>
                            <span className="text-[11px] text-muted-foreground leading-tight">
                              {t(presetOption.description)} •{' '}
                              <span className="font-mono">
                                {presetOption.cron}
                              </span>
                            </span>
                          </div>
                        </CommandItem>
                      ))}
                    </CommandGroup>

                    {/* Monthly */}
                    <CommandGroup heading={t('Monthly')}>
                      {getPresetsByCategory('monthly').map((presetOption) => (
                        <CommandItem
                          key={presetOption.value}
                          value={`${presetOption.label} ${presetOption.description} ${presetOption.cron}`}
                          onSelect={() => {
                            selectPreset(presetOption.value)
                            setPopoverOpen(false)
                          }}
                          className="px-3 py-2.5"
                        >
                          <div className="flex flex-col gap-0.5">
                            <span className="text-[13px] font-medium">
                              {t(presetOption.label)}
                            </span>
                            <span className="text-[11px] text-muted-foreground leading-tight">
                              {t(presetOption.description)} •{' '}
                              <span className="font-mono">
                                {presetOption.cron}
                              </span>
                            </span>
                          </div>
                        </CommandItem>
                      ))}
                    </CommandGroup>
                  </CommandList>
                </Command>
              </PopoverContent>
            </Popover>
          </div>
        </div>
      )}

      {/* Advanced Mode */}
      {mode === 'advanced' && (
        <div className="space-y-2">
          <Label htmlFor="cron-expression" className="text-[13px] font-medium">
            {t('Cron expression')}
          </Label>
          <Input
            id="cron-expression"
            value={customCron}
            onChange={(e) => {
              const next = e.target.value
              setCustomCron(next)
              onChange(next)
            }}
            placeholder="0 0 * * *"
            className="font-mono text-[13px] h-9"
            disabled={disabled}
          />
          <p className="text-[12px] text-muted-foreground">
            {t(
              'Format: minute hour day month weekday (e.g., "0 0 * * *" for daily at midnight)',
            )}
          </p>
        </div>
      )}

      {/* Validation & Preview */}
      {validation.valid && value && value.trim() && (
        <div className="rounded-lg border border-border bg-muted/30 overflow-hidden transition-all duration-200">
          <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-3.5 py-2">
            <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
            <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Schedule preview')}
            </span>
          </div>
          <p className="px-3.5 py-3 text-[13px] font-medium leading-relaxed text-foreground">
            {t(formattedSchedule)}
          </p>
        </div>
      )}
      {!validation.valid && value && value.trim() && (
        <Alert variant="destructive" className="transition-all duration-200">
          <AlertCircle className="h-4 w-4" />
          <AlertDescription className="text-[12px]">
            {validation.error ? t(validation.error) : t('Invalid cron expression')}
          </AlertDescription>
        </Alert>
      )}
    </div>
  )
}
