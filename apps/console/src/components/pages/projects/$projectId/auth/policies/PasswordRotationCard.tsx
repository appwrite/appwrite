import { useEffect, useState } from 'react'
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

const MIN_MAX_AGE_SECONDS = 86_400 // 1 day
const MAX_MAX_AGE_SECONDS = 315_360_000 // 10 years

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

  useEffect(() => {
    const pair = createTimeUnitPair(currentPolicy.maxAge, {
      units: MAX_AGE_UNITS,
    })
    setEnabled(currentPolicy.enabled)
    setMaxAge(pair.value)
    setUnit(pair.unit)
  }, [currentPolicy.enabled, currentPolicy.maxAge])

  const maxAgeSeconds = Math.round(toSeconds(maxAge, unit))
  const belowMin = maxAgeSeconds < MIN_MAX_AGE_SECONDS
  const invalid = enabled && (belowMin || maxAgeSeconds > MAX_MAX_AGE_SECONDS)
  const hasChanges =
    enabled !== currentPolicy.enabled ||
    (enabled && maxAgeSeconds !== currentPolicy.maxAge)

  const handleSubmit = () => {
    mutation.mutate(
      enabled ? { enabled, maxAge: maxAgeSeconds } : { enabled },
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
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
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
                  max={Math.floor(fromSeconds(MAX_MAX_AGE_SECONDS, unit))}
                  value={maxAge}
                  onChange={(e) => {
                    const value = parseFloat(e.target.value)
                    if (!isNaN(value) && value >= 0) {
                      setMaxAge(value)
                    } else if (e.target.value === '') {
                      setMaxAge(0)
                    }
                  }}
                  disabled={mutation.isPending || !enabled}
                  className={invalid ? 'border-destructive' : ''}
                />
                {invalid ? (
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
                onValueChange={(value) => setUnit(value as TimeUnit)}
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
          disabled={!hasChanges || mutation.isPending || invalid}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
