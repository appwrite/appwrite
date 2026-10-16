import { useMemo } from 'react'
import { Label } from '@/components/ui/label'
import { NumberInput } from '@/components/ui/number-input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  FIREWALL_RATE_LIMIT_INTERVAL_MAX,
  FIREWALL_RATE_LIMIT_INTERVAL_MIN,
} from '@/lib/firewall/actions'
import { useT } from '@/lib/i18n/translate'
import {
  fromSeconds,
  toSeconds,
  type TimeUnit,
} from '@/lib/utils/time-unit-converter'

export const FIREWALL_RATE_LIMIT_INTERVAL_UNITS: TimeUnit[] = [
  'seconds',
  'minutes',
  'hours',
  'days',
]

export function firewallRateLimitIntervalSeconds(
  value: number,
  unit: TimeUnit,
): number {
  return Math.round(toSeconds(value, unit))
}

type FirewallRateLimitIntervalFieldProps = {
  value: number
  unit: TimeUnit
  onValueChange: (value: number) => void
  onUnitChange: (unit: TimeUnit) => void
  disabled?: boolean
}

function roundForUnit(value: number, unit: TimeUnit): number {
  if (unit === 'seconds' || unit === 'minutes') {
    return Math.round(value)
  }
  return Math.round(value * 100) / 100
}

export function FirewallRateLimitIntervalField({
  value,
  unit,
  onValueChange,
  onUnitChange,
  disabled,
}: FirewallRateLimitIntervalFieldProps) {
  const t = useT()

  const maxValueForUnit = useMemo(
    () => Math.floor(fromSeconds(FIREWALL_RATE_LIMIT_INTERVAL_MAX, unit)),
    [unit],
  )

  const intervalSeconds = useMemo(
    () => firewallRateLimitIntervalSeconds(value, unit),
    [value, unit],
  )

  const outOfRange =
    intervalSeconds < FIREWALL_RATE_LIMIT_INTERVAL_MIN ||
    intervalSeconds > FIREWALL_RATE_LIMIT_INTERVAL_MAX

  const handleUnitChange = (newUnit: TimeUnit) => {
    const currentSeconds = toSeconds(value, unit)
    const converted = fromSeconds(currentSeconds, newUnit)
    const maxForNewUnit = Math.floor(
      fromSeconds(FIREWALL_RATE_LIMIT_INTERVAL_MAX, newUnit),
    )
    const rounded = roundForUnit(converted, newUnit)
    onValueChange(
      Math.min(Math.max(FIREWALL_RATE_LIMIT_INTERVAL_MIN, rounded), maxForNewUnit),
    )
    onUnitChange(newUnit)
  }

  return (
    <div className="flex flex-col gap-2.5">
      <div className="space-y-1.5">
        <Label className="text-[13px] font-medium leading-snug">
          {t('Interval')}
        </Label>
        <p className="text-[12px] leading-snug text-muted-foreground">
          {t('Maximum window is 24 hours.')}
        </p>
      </div>
      <div className="flex w-full gap-2">
        <NumberInput
          value={value}
          min={1}
          max={maxValueForUnit}
          onValueChange={onValueChange}
          disabled={disabled}
          className="h-9 w-[5.5rem] shrink-0 text-[13px] tabular-nums"
          aria-invalid={outOfRange}
        />
        <div className="min-w-0 flex-1">
          <Select
            value={unit}
            onValueChange={(next) => handleUnitChange(next as TimeUnit)}
            disabled={disabled}
          >
            <SelectTrigger className="h-9 w-full min-w-0 text-[12px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {FIREWALL_RATE_LIMIT_INTERVAL_UNITS.map((option) => (
                <SelectItem key={option} value={option}>
                  {t(
                    option === 'seconds'
                      ? 'Seconds'
                      : option === 'minutes'
                        ? 'Minutes'
                        : option === 'hours'
                          ? 'Hours'
                          : 'Days',
                  )}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>
    </div>
  )
}
