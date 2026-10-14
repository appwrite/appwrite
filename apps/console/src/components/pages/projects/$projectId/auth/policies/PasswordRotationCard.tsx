import { useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useUpdatePasswordRotationPolicy } from '@/lib/react-query/hooks'
import { type PasswordRotationPolicy } from '@/lib/project-settings'
import {
  createTimeUnitPair,
  fromSeconds,
  toSeconds,
  type TimeUnit,
} from '@/lib/utils/time-unit-converter'
import { useT } from '@/lib/i18n/translate'

// The API accepts a maximum password age between 1 day and 10 years.
const MIN_MAX_AGE_SECONDS = 86_400
const MAX_MAX_AGE_SECONDS = 315_360_000

// A password age under a day is refused by the API, so sub-day units are left out.
const MAX_AGE_UNITS: TimeUnit[] = ['years', 'months', 'weeks', 'days']

export function PasswordRotationCard({
  projectId,
  currentPolicy,
}: {
  projectId: string
  currentPolicy: PasswordRotationPolicy
}) {
  const t = useT()
  const initialPair = createTimeUnitPair(currentPolicy.maxAge, {
    units: MAX_AGE_UNITS,
  })
  const [enabled, setEnabled] = useState(currentPolicy.enabled)
  const [maxAge, setMaxAge] = useState(initialPair.value)
  const [unit, setUnit] = useState<TimeUnit>(initialPair.unit)
  const mutation = useUpdatePasswordRotationPolicy(projectId)
  const syncedPolicy = useRef(currentPolicy)

  useEffect(() => {
    // Adopt the stored policy only when it changes, so a refused save keeps the
    // edit on screen. The unit stays the one the user picked; only the value is
    // converted, which is why a unit change cannot reach this.
    if (mutation.isPending || syncedPolicy.current === currentPolicy) return
    syncedPolicy.current = currentPolicy
    setEnabled(currentPolicy.enabled)
    setMaxAge(roundToUnitPrecision(fromSeconds(currentPolicy.maxAge, unit)))
  }, [currentPolicy, mutation.isPending, unit])

  // Max value for the current unit, so the stepper cannot pass 10 years.
  const maxValueForUnit = useMemo(
    () => Math.floor(fromSeconds(MAX_MAX_AGE_SECONDS, unit)),
    [unit],
  )

  const maxAgeSeconds = useMemo(() => toSeconds(maxAge, unit), [maxAge, unit])
  const belowMin = maxAgeSeconds < MIN_MAX_AGE_SECONDS
  const exceedsMax = maxAgeSeconds > MAX_MAX_AGE_SECONDS
  // The maximum age is only sent while the policy is on, so an out of range
  // value left behind by a disabled policy does not block the toggle.
  const rangeError = enabled && (belowMin || exceedsMax)

  const hasChanges = useMemo(() => {
    if (enabled !== currentPolicy.enabled) return true
    // Allow small floating point differences
    return enabled && Math.abs(maxAgeSeconds - currentPolicy.maxAge) > 0.5
  }, [enabled, maxAgeSeconds, currentPolicy])

  // When unit changes, convert the current maximum age to the new unit
  const handleUnitChange = (newUnit: TimeUnit) => {
    const currentSeconds = toSeconds(maxAge, unit)
    const maxForNewUnit = Math.floor(fromSeconds(MAX_MAX_AGE_SECONDS, newUnit))
    const converted = roundToUnitPrecision(fromSeconds(currentSeconds, newUnit))

    setMaxAge(Math.min(converted, maxForNewUnit))
    setUnit(newUnit)
  }

  const handleSubmit = () => {
    mutation.mutate(
      enabled ? { enabled, maxAge: Math.round(maxAgeSeconds) } : { enabled },
      {
        onSuccess: () => {
          toast.success(t('Updated periodic password change policy'))
        },
        onError: (error: Error) => {
          toast.error(
            error.message ||
              t('Failed to update periodic password change policy'),
          )
        },
      },
    )
  }

  return (
    <div
      data-testid="password-rotation-card"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Periodic password change')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-1">
          {t(
            'Require users to set a new password once the one they have reaches the maximum age. An email/password sign-in with a password past that age is refused until the user resets it through password recovery. Sign-ins with other methods are not affected.', // pragma: allowlist secret
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-4">
          <div className="flex items-center gap-3">
            <Switch
              id="password-rotation-enabled"
              checked={enabled}
              onCheckedChange={setEnabled}
              disabled={mutation.isPending}
            />
            <Label
              htmlFor="password-rotation-enabled"
              className="text-[13px] text-foreground cursor-pointer"
            >
              {t('Require a periodic password change')}
            </Label>
          </div>
          <div className="flex gap-3">
            <div className="space-y-2 flex-1 max-w-[200px]">
              <Label
                htmlFor="password-rotation-max-age"
                className="text-[13px]"
              >
                {t('Maximum password age')}
              </Label>
              <div className="space-y-1">
                <Input
                  id="password-rotation-max-age"
                  type="number"
                  min={1}
                  max={maxValueForUnit}
                  value={maxAge}
                  onChange={(e) => {
                    const value = parseFloat(e.target.value)
                    if (!isNaN(value) && value >= 0) {
                      setMaxAge(value)
                    } else if (e.target.value === '') {
                      setMaxAge(0)
                    }
                  }}
                  // The maximum age only applies while the policy is on.
                  disabled={mutation.isPending || !enabled}
                  className={rangeError ? 'border-destructive' : ''}
                />
                {rangeError ? (
                  <p className="text-[12px] text-destructive">
                    {belowMin
                      ? t('Minimum is 1 day')
                      : t('Maximum is 10 years')}
                  </p>
                ) : (
                  <p className="text-[12px] text-muted-foreground">
                    {t('Between 1 day and 10 years')}
                  </p>
                )}
              </div>
            </div>
            <div className="space-y-2 flex-1 max-w-[200px]">
              <Label htmlFor="password-rotation-unit" className="text-[13px]">
                {t('Time period')}
              </Label>
              <Select
                value={unit}
                onValueChange={handleUnitChange}
                disabled={mutation.isPending || !enabled}
              >
                <SelectTrigger id="password-rotation-unit">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="days">{t('Days')}</SelectItem>
                  <SelectItem value="weeks">{t('Weeks')}</SelectItem>
                  <SelectItem value="months">{t('Months')}</SelectItem>
                  <SelectItem value="years">{t('Years')}</SelectItem>
                </SelectContent>
              </Select>
              {/* Spacer to match the height of helper text in the value field */}
              <div className="h-5" />
            </div>
          </div>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || mutation.isPending || rangeError}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

/** Keeps a converted value readable instead of a long floating point tail. */
function roundToUnitPrecision(value: number): number {
  return Math.round(value * 100) / 100
}
