import { useState } from 'react'
import { AlertCircle, CheckCircle2, Lock } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Label } from '@/components/ui/label'
import { NumberInput } from '@/components/ui/number-input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import {
  DEFAULT_FUNCTION_INTERVAL,
  FUNCTION_INTERVAL_PRESETS,
  INTERVAL_UNIT_MINUTES,
  formatInterval,
  isIntervalBelowMinimum,
  splitInterval,
  type FunctionScheduleMode,
  type IntervalUnit,
} from '@/lib/function-interval'
import { useT } from '@/lib/i18n/translate'
import { CronScheduleEditor } from '../CronScheduleEditor'

const CUSTOM_PRESET = 'custom'

const MODE_ITEM_CLASS =
  'h-8 gap-1.5 px-3 text-[12px] font-medium text-muted-foreground hover:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-secondary-foreground data-[state=on]:hover:bg-secondary data-[state=on]:hover:text-secondary-foreground'

const INTERVAL_UNITS: IntervalUnit[] = ['minutes', 'hours', 'days']

function isPresetInterval(minutes: number): boolean {
  return FUNCTION_INTERVAL_PRESETS.some((preset) => preset.minutes === minutes)
}

type FunctionScheduleEditorProps = {
  mode: FunctionScheduleMode
  onModeChange: (mode: FunctionScheduleMode) => void
  interval: number
  onIntervalChange: (minutes: number) => void
  schedule: string
  onScheduleChange: (schedule: string) => void
  /** Interval stored on the function, which stays allowed after a downgrade. */
  savedInterval: number
  /** Shortest interval the organization plan allows, in minutes. */
  intervalMinimum: number
  orgId?: string | null
  disabled?: boolean
}

export function FunctionScheduleEditor({
  mode,
  onModeChange,
  interval,
  onIntervalChange,
  schedule,
  onScheduleChange,
  savedInterval,
  intervalMinimum,
  orgId,
  disabled,
}: FunctionScheduleEditorProps) {
  const t = useT()
  const [custom, setCustom] = useState(
    () => interval > 0 && !isPresetInterval(interval),
  )
  const [unit, setUnit] = useState<IntervalUnit>(
    () => splitInterval(interval).unit,
  )

  const savedBelowMinimum = isIntervalBelowMinimum(
    savedInterval,
    intervalMinimum,
  )
  const customBelowMinimum =
    custom &&
    interval !== savedInterval &&
    isIntervalBelowMinimum(interval, intervalMinimum)

  const handleModeChange = (next: string) => {
    if (next !== 'none' && next !== 'interval' && next !== 'cron') return
    if (next === 'interval' && interval <= 0) {
      onIntervalChange(Math.max(DEFAULT_FUNCTION_INTERVAL, intervalMinimum))
    }
    onModeChange(next)
  }

  const handlePresetChange = (value: string) => {
    if (value === CUSTOM_PRESET) {
      setCustom(true)
      setUnit(splitInterval(interval).unit)
      return
    }
    setCustom(false)
    onIntervalChange(Number(value))
  }

  const handleUnitChange = (value: string) => {
    const next = value as IntervalUnit
    const count = Math.max(
      1,
      Math.round(interval / INTERVAL_UNIT_MINUTES[unit]),
    )
    setUnit(next)
    onIntervalChange(count * INTERVAL_UNIT_MINUTES[next])
  }

  return (
    <div className="space-y-4">
      <ToggleGroup
        type="single"
        variant="outline"
        size="sm"
        value={mode}
        onValueChange={handleModeChange}
        disabled={disabled}
        aria-label={t('Schedule type')}
      >
        <ToggleGroupItem value="none" className={MODE_ITEM_CLASS}>
          {t('None')}
        </ToggleGroupItem>
        <ToggleGroupItem value="interval" className={MODE_ITEM_CLASS}>
          {t('Interval')}
          <Badge variant="success" className="text-[10px] shrink-0">
            {t('Recommended')}
          </Badge>
        </ToggleGroupItem>
        <ToggleGroupItem value="cron" className={MODE_ITEM_CLASS}>
          {t('Cron')}
        </ToggleGroupItem>
      </ToggleGroup>

      {mode === 'none' && (
        <p className="text-[13px] text-muted-foreground">
          {t('Function will not run on a schedule')}
        </p>
      )}

      {mode === 'interval' && (
        <div className="space-y-4">
          {savedBelowMinimum ? (
            <Alert
              variant="default"
              className="border-amber-500/30 bg-amber-500/5"
            >
              <AlertCircle className="h-4 w-4 text-amber-500" />
              <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                {t('Interval below your plan minimum')}
              </AlertTitle>
              <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                {t(
                  'This function keeps running on its current interval. A new interval must be {minutes} minutes or longer.',
                ).replace('{minutes}', String(intervalMinimum))}{' '}
                <UpgradePlanLink orgId={orgId} />{' '}
                {t('to use shorter intervals.')}
              </AlertDescription>
            </Alert>
          ) : intervalMinimum > 0 ? (
            <Alert
              variant="default"
              className="border-amber-500/30 bg-amber-500/5"
            >
              <AlertCircle className="h-4 w-4 text-amber-500" />
              <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                {t(
                  'Your plan supports intervals of {minutes} minutes or longer.',
                ).replace('{minutes}', String(intervalMinimum))}{' '}
                <UpgradePlanLink orgId={orgId} />{' '}
                {t('to use shorter intervals.')}
              </AlertDescription>
            </Alert>
          ) : null}

          <div className="space-y-2">
            <Label
              htmlFor="function-interval"
              className="text-[13px] font-medium"
            >
              {t('Interval')}
            </Label>
            <Select
              value={custom ? CUSTOM_PRESET : String(interval)}
              onValueChange={handlePresetChange}
              disabled={disabled}
            >
              <SelectTrigger
                id="function-interval"
                className="h-9 w-full text-[13px]"
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <TooltipProvider>
                  {FUNCTION_INTERVAL_PRESETS.map((preset) => {
                    const locked =
                      preset.minutes !== savedInterval &&
                      isIntervalBelowMinimum(preset.minutes, intervalMinimum)
                    const item = (
                      <SelectItem
                        key={preset.minutes}
                        value={String(preset.minutes)}
                        disabled={locked}
                        className="text-[13px]"
                      >
                        {t(preset.label)}
                        {locked ? <Lock className="h-3 w-3" /> : null}
                      </SelectItem>
                    )
                    if (!locked) return item
                    return (
                      <Tooltip key={preset.minutes}>
                        <TooltipTrigger asChild>
                          <div>{item}</div>
                        </TooltipTrigger>
                        <TooltipContent side="right">
                          {t('Upgrade to unlock')}
                        </TooltipContent>
                      </Tooltip>
                    )
                  })}
                </TooltipProvider>
                <SelectItem value={CUSTOM_PRESET} className="text-[13px]">
                  {t('Custom')}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          {custom && (
            <div className="space-y-2">
              <div className="flex gap-2">
                <NumberInput
                  aria-label={t('Interval length')}
                  value={Math.max(
                    1,
                    Math.round(interval / INTERVAL_UNIT_MINUTES[unit]),
                  )}
                  onValueChange={(count) =>
                    onIntervalChange(count * INTERVAL_UNIT_MINUTES[unit])
                  }
                  min={1}
                  step={1}
                  className="h-9 w-32 text-[13px]"
                  aria-invalid={customBelowMinimum || undefined}
                  disabled={disabled}
                />
                <Select
                  value={unit}
                  onValueChange={handleUnitChange}
                  disabled={disabled}
                >
                  <SelectTrigger
                    aria-label={t('Interval unit')}
                    className="h-9 w-32 text-[13px]"
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {INTERVAL_UNITS.map((option) => (
                      <SelectItem
                        key={option}
                        value={option}
                        className="text-[13px]"
                      >
                        {t(option)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              {customBelowMinimum && (
                <p className="text-[12px] text-destructive">
                  {t(
                    'Your plan supports intervals of {minutes} minutes or longer.',
                  ).replace('{minutes}', String(intervalMinimum))}
                </p>
              )}
            </div>
          )}

          {interval > 0 && (
            <div className="rounded-lg border border-border bg-muted/30 overflow-hidden">
              <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-3.5 py-2">
                <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Schedule preview')}
                </span>
              </div>
              <div className="space-y-1 px-3.5 py-3">
                <p className="text-[13px] font-medium leading-relaxed text-foreground">
                  {formatInterval(interval, t)}
                </p>
                <p className="text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'Appwrite picks a fixed time within each interval for this function to spread load. Use cron if you need exact times.',
                  )}
                </p>
              </div>
            </div>
          )}
        </div>
      )}

      {mode === 'cron' && (
        <CronScheduleEditor
          value={schedule}
          onChange={onScheduleChange}
          disabled={disabled}
          allowDisabled={false}
        />
      )}
    </div>
  )
}
