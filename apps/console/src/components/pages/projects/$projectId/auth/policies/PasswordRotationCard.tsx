import { useEffect, useRef, useState } from 'react'
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
import {
  hasPasswordRotationChanges,
  maxAgeRangeInUnit,
  maxAgeSecondsFromValue,
  maxAgeUnitPair,
  maxAgeValueInUnit,
  PASSWORD_MAX_AGE_UNITS,
  passwordMaxAgeRangeError,
  passwordRotationUpdate,
  type PasswordMaxAgeUnit,
  type PasswordRotationPolicy,
} from '@/lib/password-rotation'
import { type TimeUnit } from '@/lib/utils/time-unit-converter'
import { useT } from '@/lib/i18n/translate'

/** The dropdown reads smallest first; the constant is ordered largest first. */
const UNIT_OPTIONS = [...PASSWORD_MAX_AGE_UNITS].reverse()

export function PasswordRotationCard({
  projectId,
  currentPolicy,
}: {
  projectId: string
  currentPolicy: PasswordRotationPolicy
}) {
  const t = useT()
  // The maximum age is kept in seconds, the way the API stores it, and the unit
  // only decides how it is written. Switching unit is then never an edit, and
  // converting back and forth cannot drift the stored age.
  const [enabled, setEnabled] = useState(currentPolicy.enabled)
  const [maxAgeSeconds, setMaxAgeSeconds] = useState(currentPolicy.maxAge)
  const [unit, setUnit] = useState<TimeUnit>(
    () => maxAgeUnitPair(currentPolicy.maxAge).unit,
  )
  const mutation = useUpdatePasswordRotationPolicy(projectId)
  const syncedPolicy = useRef(currentPolicy)

  useEffect(() => {
    // Adopt the stored policy only when its values change, so a refused save
    // keeps the edit on screen. Compare the values, not the object: the
    // snapshot hook reparses the policy list on every refetch and any sibling
    // policy's save invalidates it, so holding an edit through that must not
    // rest on React Query reusing the reference for an unchanged policy.
    // The unit stays the one the user picked.
    const synced = syncedPolicy.current
    if (
      mutation.isPending ||
      (currentPolicy.enabled === synced.enabled &&
        currentPolicy.maxAge === synced.maxAge)
    ) {
      return
    }
    syncedPolicy.current = currentPolicy
    setEnabled(currentPolicy.enabled)
    setMaxAgeSeconds(currentPolicy.maxAge)
  }, [currentPolicy, mutation.isPending])

  // The range the field advertises, in the unit on screen rather than seconds,
  // so it never refuses an age the API accepts or admits one it does not.
  const { min, max } = maxAgeRangeInUnit(unit)
  const unitLabels: Record<PasswordMaxAgeUnit, string> = {
    days: t('Days'),
    weeks: t('Weeks'),
    months: t('Months'),
    years: t('Years'),
  }

  const maxAge = maxAgeValueInUnit(maxAgeSeconds, unit)
  const rangeError = passwordMaxAgeRangeError(enabled, maxAgeSeconds)
  const hasChanges = hasPasswordRotationChanges(
    { enabled, maxAgeSeconds },
    currentPolicy,
  )

  const handleSubmit = () => {
    mutation.mutate(passwordRotationUpdate(enabled, maxAgeSeconds), {
      onSuccess: () => {
        toast.success(t('Updated periodic password change policy'))
      },
      onError: (error: Error) => {
        toast.error(
          error.message ||
            t('Failed to update periodic password change policy'),
        )
      },
    })
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
                  min={min}
                  max={max}
                  value={maxAge}
                  onChange={(e) => {
                    const value = parseFloat(e.target.value)
                    if (!isNaN(value) && value >= 0) {
                      setMaxAgeSeconds(maxAgeSecondsFromValue(value, unit))
                    } else if (e.target.value === '') {
                      setMaxAgeSeconds(0)
                    }
                  }}
                  // The maximum age only applies while the policy is on.
                  disabled={mutation.isPending || !enabled}
                  className={rangeError ? 'border-destructive' : ''}
                />
                {rangeError ? (
                  <p className="text-[12px] text-destructive">
                    {rangeError === 'below-min'
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
                onValueChange={(value) => setUnit(value as TimeUnit)}
                disabled={mutation.isPending || !enabled}
              >
                <SelectTrigger id="password-rotation-unit">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {UNIT_OPTIONS.map((option) => (
                    <SelectItem key={option} value={option}>
                      {unitLabels[option]}
                    </SelectItem>
                  ))}
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
          disabled={!hasChanges || mutation.isPending || rangeError !== null}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
