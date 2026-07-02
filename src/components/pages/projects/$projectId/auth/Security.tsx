import { useState, useEffect, useMemo, useRef } from 'react'
import { useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  DEFAULT_AUTH_SECURITY,
  projectAuthSecurityQueryOptions,
} from '@/lib/project-settings'
import { X, RefreshCw, Plus, Copy, Check } from 'lucide-react'
import {
  useUpdateAuthLimit,
  useUpdateAuthDuration,
  useUpdateAuthSessionsLimit,
  useUpdateAuthPasswordHistory,
  useUpdateAuthPasswordDictionary,
  useUpdatePersonalDataCheck,
  useUpdateSessionAlerts,
  useUpdateSessionInvalidation,
  useUpdateMockNumbers,
  useUpdateMembershipsPrivacy,
  useProject,
  useOrganizationPlan,
  MAX_AUTH_POLICY_TOTAL,
} from '@/lib/react-query/hooks'
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
import { Checkbox } from '@/components/ui/checkbox'
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSlot,
} from '@/components/ui/input-otp'
import { UpgradeCurtain } from '@/components/ui/upgrade-curtain'
import {
  toSeconds,
  fromSeconds,
  createTimeUnitPair,
  type TimeUnit,
  type TimeUnitPair,
} from '@/lib/utils/time-unit-converter'
import { useT } from '@/lib/i18n/translate'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'

const SESSION_LENGTH_UNITS: TimeUnit[] = [
  'weeks',
  'days',
  'hours',
  'minutes',
  'seconds',
]

export function useAuthSecuritySnapshot(projectId: string) {
  const { project } = useProject(projectId)
  const { data: projectData } = useQuery(
    projectAuthSecurityQueryOptions(projectId, project?.region),
  )
  return projectData ?? DEFAULT_AUTH_SECURITY
}

// ============================================================================
// INDIVIDUAL FEATURE CARDS
// ============================================================================

export function UsersLimitCard({
  projectId,
  currentLimit,
}: {
  projectId: string
  currentLimit: number
}) {
  const t = useT()
  const [isUnlimited, setIsUnlimited] = useState(currentLimit === 0)
  const [limit, setLimit] = useState(currentLimit === 0 ? 1000 : currentLimit)
  const mutation = useUpdateAuthLimit(projectId)
  const lastSubmittedValue = useRef<number | null>(null)

  useEffect(() => {
    // Only sync from server if:
    // 1. Mutation is not pending
    // 2. Server value matches what we expect (last submitted value), or we haven't submitted anything
    if (!mutation.isPending) {
      if (
        lastSubmittedValue.current === null ||
        currentLimit === lastSubmittedValue.current
      ) {
        setIsUnlimited(currentLimit === 0)
        setLimit(currentLimit === 0 ? 1000 : currentLimit)
        // Reset ref once we've synced to the expected value
        if (
          lastSubmittedValue.current !== null &&
          currentLimit === lastSubmittedValue.current
        ) {
          lastSubmittedValue.current = null
        }
      }
    }
  }, [currentLimit, mutation.isPending])

  const hasChanges = useMemo(() => {
    const newLimit = isUnlimited ? 0 : limit
    return newLimit !== currentLimit
  }, [isUnlimited, limit, currentLimit])

  const handleSubmit = () => {
    const newLimit = isUnlimited ? 0 : limit
    lastSubmittedValue.current = newLimit
    mutation.mutate(newLimit, {
      onSuccess: () => {
        toast.success(t('Updated project users limit successfully'))
        // Track analytics: Submit.AuthLimitUpdate
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to update users limit'))
        // Revert on error
        lastSubmittedValue.current = null
        // Track analytics: trackError(error, Submit.AuthLimitUpdate)
      },
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Users limit')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-1">
              {t(
                'Limit new users from signing up for your project, regardless of authentication method. You can still create users and team memberships from your Appwrite console.', // pragma: allowlist secret
              )}
            </p>
          </div>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-4">
          <div className="flex items-center gap-3">
            <Switch
              id="users-limit-unlimited"
              checked={isUnlimited}
              onCheckedChange={setIsUnlimited}
              disabled={mutation.isPending}
            />
            <Label
              htmlFor="users-limit-unlimited"
              className="text-[13px] text-foreground cursor-pointer"
            >
              {t('Allow unlimited users (Recommended)')}
            </Label>
          </div>
          {!isUnlimited && (
            <div className="space-y-2">
              <Label htmlFor="users-limit-value" className="text-[13px]">
                {t('Maximum number of users')}
              </Label>
              <Input
                id="users-limit-value"
                type="number"
                min={1}
                max={MAX_AUTH_POLICY_TOTAL}
                value={limit}
                onChange={(e) => {
                  const value = parseInt(e.target.value, 10)
                  if (
                    !isNaN(value) &&
                    value >= 1 &&
                    value <= MAX_AUTH_POLICY_TOTAL
                  ) {
                    setLimit(value)
                  }
                }}
                disabled={mutation.isPending}
                className="max-w-[200px]"
              />
              <p className="text-[12px] text-muted-foreground">
                {t('Between 1 and')} {MAX_AUTH_POLICY_TOTAL.toLocaleString()}{' '}
                {t('users')}
              </p>
            </div>
          )}
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || mutation.isPending}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function SessionLengthCard({
  projectId,
  currentDuration,
}: {
  projectId: string
  currentDuration: number
}) {
  const t = useT()
  const MAX_DURATION_SECONDS = 31_536_000 // 1 year in seconds (365 days)

  const getInitialPair = (seconds: number): TimeUnitPair =>
    createTimeUnitPair(seconds, { units: SESSION_LENGTH_UNITS })

  const initialPair = getInitialPair(currentDuration)
  const [duration, setDuration] = useState(initialPair.value)
  const [unit, setUnit] = useState<TimeUnit>(initialPair.unit)
  const [isInitialLoad, setIsInitialLoad] = useState(true)
  const mutation = useUpdateAuthDuration(projectId)

  // Only auto-select unit on initial load, preserve user's choice after that
  useEffect(() => {
    if (isInitialLoad) {
      const pair = getInitialPair(currentDuration)
      setDuration(pair.value)
      setUnit(pair.unit)
      setIsInitialLoad(false)
    } else {
      // After initial load, keep the current unit and just update the value
      const newValue = fromSeconds(currentDuration, unit)
      // Round appropriately based on unit
      if (unit === 'seconds' || unit === 'minutes') {
        setDuration(Math.round(newValue))
      } else if (unit === 'hours') {
        setDuration(Math.round(newValue * 10) / 10)
      } else {
        setDuration(Math.round(newValue * 100) / 100)
      }
    }
  }, [currentDuration, unit, isInitialLoad])

  // Calculate max value for current unit to prevent exceeding 1 year (365 days)
  const maxValueForUnit = useMemo(() => {
    return Math.floor(fromSeconds(MAX_DURATION_SECONDS, unit))
  }, [unit])

  // Check if current value exceeds the maximum
  const currentDurationSeconds = useMemo(() => {
    return toSeconds(duration, unit)
  }, [duration, unit])

  const exceedsMax = currentDurationSeconds > MAX_DURATION_SECONDS

  // When unit changes, convert the current duration to the new unit
  const handleUnitChange = (newUnit: TimeUnit) => {
    // Convert current duration to seconds first
    const currentSeconds = toSeconds(duration, unit)
    // Convert to the new unit
    const newValue = fromSeconds(currentSeconds, newUnit)
    const maxForNewUnit = Math.floor(fromSeconds(MAX_DURATION_SECONDS, newUnit))

    // Round to reasonable precision based on unit
    let roundedValue: number
    if (newUnit === 'seconds' || newUnit === 'minutes') {
      roundedValue = Math.round(newValue)
    } else if (newUnit === 'hours') {
      roundedValue = Math.round(newValue * 10) / 10
    } else {
      roundedValue = Math.round(newValue * 100) / 100
    }

    // If the new value exceeds max for the new unit, clamp it
    if (roundedValue > maxForNewUnit) {
      setDuration(maxForNewUnit)
    } else {
      setDuration(roundedValue)
    }

    setUnit(newUnit)
  }

  const hasChanges = useMemo(() => {
    const newDurationSeconds = toSeconds(duration, unit)
    return Math.abs(newDurationSeconds - currentDuration) > 0.5 // Allow small floating point differences
  }, [duration, unit, currentDuration])

  const handleSubmit = () => {
    if (exceedsMax) {
      toast.error(t('Session length cannot exceed 365 days (1 year)'))
      return
    }

    const durationSeconds = toSeconds(duration, unit)
    // Clamp to valid range before submitting
    const clampedDuration = Math.max(
      0,
      Math.min(durationSeconds, MAX_DURATION_SECONDS),
    )
    mutation.mutate(clampedDuration, {
      onSuccess: () => {
        toast.success(t('Updated session length successfully'))
        // Track analytics: Submit.SessionsLengthUpdate
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to update session length'))
        // Track analytics: trackError(error, Submit.SessionsLengthUpdate)
      },
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Session length')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-1">
              {t(
                'If you reduce the limit, users who are currently logged in will be logged out of the application.',
              )}
            </p>
          </div>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex gap-3">
          <div className="space-y-2 flex-1 max-w-[200px]">
            <Label htmlFor="session-length-value" className="text-[13px]">
              {t('Length')}
            </Label>
            <div className="space-y-1">
              <Input
                id="session-length-value"
                type="number"
                min={0}
                max={maxValueForUnit}
                value={duration}
                onChange={(e) => {
                  const value = parseFloat(e.target.value)
                  if (!isNaN(value) && value >= 0) {
                    setDuration(value)
                  } else if (e.target.value === '') {
                    setDuration(0)
                  }
                }}
                disabled={mutation.isPending}
                className={exceedsMax ? 'border-destructive' : ''}
              />
              {exceedsMax && (
                <p className="text-[12px] text-destructive">
                  {t('Maximum is')} {maxValueForUnit} {t(unit)}{' '}
                  {t('(365 days)')}
                </p>
              )}
              {!exceedsMax && (
                <p className="text-[12px] text-muted-foreground">
                  {t('Maximum:')} {maxValueForUnit} {t(unit)} {t('(365 days)')}
                </p>
              )}
            </div>
          </div>
          <div className="space-y-2 flex-1 max-w-[200px]">
            <Label htmlFor="session-length-unit" className="text-[13px]">
              {t('Time period')}
            </Label>
            <Select
              value={unit}
              onValueChange={handleUnitChange}
              disabled={mutation.isPending}
            >
              <SelectTrigger id="session-length-unit">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="seconds">{t('Seconds')}</SelectItem>
                <SelectItem value="minutes">{t('Minutes')}</SelectItem>
                <SelectItem value="hours">{t('Hours')}</SelectItem>
                <SelectItem value="days">{t('Days')}</SelectItem>
                <SelectItem value="weeks">{t('Weeks')}</SelectItem>
              </SelectContent>
            </Select>
            {/* Spacer to match the height of helper text in the value field */}
            <div className="h-5" />
          </div>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || mutation.isPending || exceedsMax}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function SessionsLimitCard({
  projectId,
  currentLimit,
}: {
  projectId: string
  currentLimit: number
}) {
  const t = useT()
  const [isUnlimited, setIsUnlimited] = useState(currentLimit === 0)
  const [limit, setLimit] = useState(
    currentLimit === 0 ? DEFAULT_AUTH_SECURITY.authSessionsLimit : currentLimit,
  )
  const mutation = useUpdateAuthSessionsLimit(projectId)
  const lastSubmittedValue = useRef<number | null>(null)

  useEffect(() => {
    if (!mutation.isPending) {
      if (
        lastSubmittedValue.current === null ||
        currentLimit === lastSubmittedValue.current
      ) {
        setIsUnlimited(currentLimit === 0)
        setLimit(
          currentLimit === 0
            ? DEFAULT_AUTH_SECURITY.authSessionsLimit
            : currentLimit,
        )
        if (
          lastSubmittedValue.current !== null &&
          currentLimit === lastSubmittedValue.current
        ) {
          lastSubmittedValue.current = null
        }
      }
    }
  }, [currentLimit, mutation.isPending])

  const hasChanges = useMemo(() => {
    const newLimit = isUnlimited ? 0 : limit
    return newLimit !== currentLimit
  }, [isUnlimited, limit, currentLimit])

  const handleSubmit = () => {
    const newLimit = isUnlimited ? 0 : limit
    lastSubmittedValue.current = newLimit
    mutation.mutate(newLimit, {
      onSuccess: () => {
        toast.success(t('Sessions limit has been updated'))
        // Track analytics: Submit.SessionsLimitUpdate
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to update sessions limit'))
        lastSubmittedValue.current = null
        // Track analytics: trackError(error, Submit.SessionsLimitUpdate)
      },
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Sessions limit')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-1">
              {t('Maximum number of active sessions allowed per user.')}
            </p>
          </div>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-4">
          <div className="flex items-center gap-3">
            <Switch
              id="sessions-limit-unlimited"
              checked={isUnlimited}
              onCheckedChange={setIsUnlimited}
              disabled={mutation.isPending}
            />
            <Label
              htmlFor="sessions-limit-unlimited"
              className="text-[13px] text-foreground cursor-pointer"
            >
              {t('Allow unlimited sessions per user')}
            </Label>
          </div>
          {!isUnlimited && (
            <div className="space-y-2 max-w-[200px]">
              <Label htmlFor="sessions-limit-value" className="text-[13px]">
                {t('Limit')}
              </Label>
              <Input
                id="sessions-limit-value"
                type="number"
                min={1}
                max={MAX_AUTH_POLICY_TOTAL}
                value={limit}
                onChange={(e) => {
                  const value = parseInt(e.target.value, 10)
                  if (
                    !isNaN(value) &&
                    value >= 1 &&
                    value <= MAX_AUTH_POLICY_TOTAL
                  ) {
                    setLimit(value)
                  }
                }}
                disabled={mutation.isPending}
              />
              <p className="text-[12px] text-muted-foreground">
                {t('Between 1 and')} {MAX_AUTH_POLICY_TOTAL.toLocaleString()}{' '}
                {t('sessions')}
              </p>
            </div>
          )}
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || mutation.isPending}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function PasswordHistoryCard({
  projectId,
  currentLimit,
}: {
  projectId: string
  currentLimit: number
}) {
  const t = useT()
  const [enabled, setEnabled] = useState(currentLimit > 0)
  const [limit, setLimit] = useState(currentLimit > 0 ? currentLimit : 5)
  const mutation = useUpdateAuthPasswordHistory(projectId)
  const lastSubmittedValue = useRef<number | null>(null)

  useEffect(() => {
    // Only sync from server if:
    // 1. Mutation is not pending
    // 2. Server value matches what we expect (last submitted value), or we haven't submitted anything
    if (!mutation.isPending) {
      if (
        lastSubmittedValue.current === null ||
        currentLimit === lastSubmittedValue.current
      ) {
        setEnabled(currentLimit > 0)
        setLimit(currentLimit > 0 ? currentLimit : 5)
        // Reset ref once we've synced to the expected value
        if (
          lastSubmittedValue.current !== null &&
          currentLimit === lastSubmittedValue.current
        ) {
          lastSubmittedValue.current = null
        }
      }
    }
  }, [currentLimit, mutation.isPending])

  const hasChanges = useMemo(() => {
    const newLimit = enabled ? limit : 0
    return newLimit !== currentLimit
  }, [enabled, limit, currentLimit])

  const handleSubmit = () => {
    const newLimit = enabled ? limit : 0
    lastSubmittedValue.current = newLimit
    mutation.mutate(newLimit, {
      onSuccess: () => {
        toast.success(t('Updated password history limit.'))
        // Track analytics: Submit.AuthPasswordHistoryUpdate
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to update password history'))
        // Revert on error
        lastSubmittedValue.current = null
        // Track analytics: trackError(error, Submit.AuthPasswordHistoryUpdate)
      },
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('History')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-1">
              {t(
                'Set the maximum number of passwords saved per user. Enabling this option prevents users from reusing recent passwords by comparing the new password with their password history.',
              )}
            </p>
          </div>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-4">
          <div className="flex items-center gap-3">
            <Switch
              id="password-history-enabled"
              checked={enabled}
              onCheckedChange={setEnabled}
              disabled={mutation.isPending}
            />
            <Label
              htmlFor="password-history-enabled"
              className="text-[13px] text-foreground cursor-pointer"
            >
              {t('Deny password reuse')}
            </Label>
          </div>
          {enabled && (
            <div className="space-y-2 max-w-[200px]">
              <Label htmlFor="password-history-limit" className="text-[13px]">
                {t('Limit')}
              </Label>
              <Input
                id="password-history-limit"
                type="number"
                min={1}
                max={MAX_AUTH_POLICY_TOTAL}
                value={limit}
                onChange={(e) => {
                  const value = parseInt(e.target.value, 10)
                  if (
                    !isNaN(value) &&
                    value >= 1 &&
                    value <= MAX_AUTH_POLICY_TOTAL
                  ) {
                    setLimit(value)
                  }
                }}
                disabled={mutation.isPending}
              />
              <p className="text-[12px] text-muted-foreground">
                {t('Between 1 and')} {MAX_AUTH_POLICY_TOTAL.toLocaleString()}{' '}
                {t('passwords')}
              </p>
            </div>
          )}
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || mutation.isPending}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function PasswordDictionaryCard({
  projectId,
  currentEnabled,
}: {
  projectId: string
  currentEnabled: boolean
}) {
  const t = useT()
  const [enabled, setEnabled] = useState(currentEnabled)
  const mutation = useUpdateAuthPasswordDictionary(projectId)
  const lastSubmittedValue = useRef<boolean | null>(null)

  useEffect(() => {
    // Only sync from server if:
    // 1. Mutation is not pending
    // 2. Server value matches what we expect (last submitted value), or we haven't submitted anything
    if (!mutation.isPending) {
      if (
        lastSubmittedValue.current === null ||
        currentEnabled === lastSubmittedValue.current
      ) {
        setEnabled(currentEnabled)
        // Reset ref once we've synced to the expected value
        if (
          lastSubmittedValue.current !== null &&
          currentEnabled === lastSubmittedValue.current
        ) {
          lastSubmittedValue.current = null
        }
      }
    }
  }, [currentEnabled, mutation.isPending])

  const hasChanges = enabled !== currentEnabled

  const handleSubmit = () => {
    lastSubmittedValue.current = enabled
    mutation.mutate(enabled, {
      onSuccess: () => {
        toast.success(t('Updated password dictionary check.'))
        // Track analytics: Submit.AuthPasswordDictionaryUpdate
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to update password dictionary'))
        // Revert on error
        lastSubmittedValue.current = null
        // Track analytics: trackError(error, Submit.AuthPasswordDictionaryUpdate)
      },
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Dictionary')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-1">
              {t(
                "Enabling this option prevents users from setting insecure passwords by comparing the user's password with the",
              )}{' '}
              <a
                href="https://github.com/danielmiessler/SecLists/blob/master/Passwords/Common-Credentials/10k-most-common.txt"
                target="_blank"
                rel="noopener noreferrer"
                className="link-neutral"
              >
                {t('10k most commonly used passwords')}
              </a>
              .
            </p>
          </div>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <Switch
            id="password-dictionary-enabled"
            checked={enabled}
            onCheckedChange={setEnabled}
            disabled={mutation.isPending}
          />
          <Label
            htmlFor="password-dictionary-enabled"
            className="text-[13px] text-foreground cursor-pointer"
          >
            {t('Deny common passwords')}
          </Label>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || mutation.isPending}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function PersonalDataCard({
  projectId,
  currentEnabled,
}: {
  projectId: string
  currentEnabled: boolean
}) {
  const t = useT()
  const [enabled, setEnabled] = useState(currentEnabled)
  const mutation = useUpdatePersonalDataCheck(projectId)
  const lastSubmittedValue = useRef<boolean | null>(null)

  useEffect(() => {
    // Only sync from server if:
    // 1. Mutation is not pending
    // 2. Server value matches what we expect (last submitted value), or we haven't submitted anything
    if (!mutation.isPending) {
      if (
        lastSubmittedValue.current === null ||
        currentEnabled === lastSubmittedValue.current
      ) {
        setEnabled(currentEnabled)
        // Reset ref once we've synced to the expected value
        if (
          lastSubmittedValue.current !== null &&
          currentEnabled === lastSubmittedValue.current
        ) {
          lastSubmittedValue.current = null
        }
      }
    }
  }, [currentEnabled, mutation.isPending])

  const hasChanges = enabled !== currentEnabled

  const handleSubmit = () => {
    lastSubmittedValue.current = enabled
    mutation.mutate(enabled, {
      onSuccess: () => {
        toast.success(t('Toggled personal data checks for passwords'))
        // Track analytics: Submit.AuthPersonalDataCheckUpdate
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to update personal data check'))
        // Revert on error
        lastSubmittedValue.current = null
        // Track analytics: trackError(error, Submit.AuthPersonalDataCheckUpdate)
      },
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Personal data')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-1">
              {t(
                "Do not allow passwords that contain any part of the user's personal data. This includes the user's",
              )}{' '}
              <code className="text-[12px] bg-muted px-1 py-0.5 rounded">
                name
              </code>
              ,{' '}
              <code className="text-[12px] bg-muted px-1 py-0.5 rounded">
                email
              </code>
              , {t('or')}{' '}
              <code className="text-[12px] bg-muted px-1 py-0.5 rounded">
                phone
              </code>
              .
            </p>
          </div>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <Switch
            id="personal-data-enabled"
            checked={enabled}
            onCheckedChange={setEnabled}
            disabled={mutation.isPending}
          />
          <Label
            htmlFor="personal-data-enabled"
            className="text-[13px] text-foreground cursor-pointer"
          >
            {t('Deny personal data in passwords')}
          </Label>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || mutation.isPending}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function SessionAlertsCard({
  projectId,
  currentEnabled,
}: {
  projectId: string
  currentEnabled: boolean
}) {
  const t = useT()
  const [enabled, setEnabled] = useState(currentEnabled)
  const mutation = useUpdateSessionAlerts(projectId)
  const lastSubmittedValue = useRef<boolean | null>(null)

  useEffect(() => {
    // Only sync from server if:
    // 1. Mutation is not pending
    // 2. Server value matches what we expect (last submitted value), or we haven't submitted anything
    if (!mutation.isPending) {
      if (
        lastSubmittedValue.current === null ||
        currentEnabled === lastSubmittedValue.current
      ) {
        setEnabled(currentEnabled)
        // Reset ref once we've synced to the expected value
        if (
          lastSubmittedValue.current !== null &&
          currentEnabled === lastSubmittedValue.current
        ) {
          lastSubmittedValue.current = null
        }
      }
    }
  }, [currentEnabled, mutation.isPending])

  const hasChanges = enabled !== currentEnabled

  const handleSubmit = () => {
    lastSubmittedValue.current = enabled
    mutation.mutate(enabled, {
      onSuccess: () => {
        toast.success(t('Updated session alerts.'))
        // Track analytics: Submit.AuthSessionAlertsUpdate
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to update session alerts'))
        // Revert on error
        lastSubmittedValue.current = null
        // Track analytics: trackError(error, Submit.AuthSessionAlertsUpdate)
      },
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Session alerts')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-1">
              {t(
                'Enabling this option will send an email to the users when a new session is created.',
              )}
            </p>
          </div>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <Switch
            id="session-alerts-enabled"
            checked={enabled}
            onCheckedChange={setEnabled}
            disabled={mutation.isPending}
          />
          <Label
            htmlFor="session-alerts-enabled"
            className="text-[13px] text-foreground cursor-pointer"
          >
            {t('Allow session alerts')}
          </Label>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || mutation.isPending}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function InvalidateSessionsCard({
  projectId,
  currentEnabled,
}: {
  projectId: string
  currentEnabled: boolean
}) {
  const t = useT()
  const [enabled, setEnabled] = useState(currentEnabled)
  const mutation = useUpdateSessionInvalidation(projectId)
  const lastSubmittedValue = useRef<boolean | null>(null)

  useEffect(() => {
    // Only sync from server if:
    // 1. Mutation is not pending
    // 2. Server value matches what we expect (last submitted value), or we haven't submitted anything
    if (!mutation.isPending) {
      if (
        lastSubmittedValue.current === null ||
        currentEnabled === lastSubmittedValue.current
      ) {
        setEnabled(currentEnabled)
        // Reset ref once we've synced to the expected value
        if (
          lastSubmittedValue.current !== null &&
          currentEnabled === lastSubmittedValue.current
        ) {
          lastSubmittedValue.current = null
        }
      }
    }
  }, [currentEnabled, mutation.isPending])

  const hasChanges = enabled !== currentEnabled

  const handleSubmit = () => {
    lastSubmittedValue.current = enabled
    mutation.mutate(enabled, {
      onSuccess: () => {
        toast.success(t('Updated session invalidation check.'))
        // Track analytics: Submit.AuthInvalidateSesssion
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to update session invalidation'))
        // Revert on error
        lastSubmittedValue.current = null
        // Track analytics: trackError(error, Submit.AuthInvalidateSesssion)
      },
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Invalidate sessions')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-1">
              {t(
                'Enabling this option will clear all existing sessions when the user changes their password.',
              )}
            </p>
          </div>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <Switch
            id="invalidate-sessions-enabled"
            checked={enabled}
            onCheckedChange={setEnabled}
            disabled={mutation.isPending}
          />
          <Label
            htmlFor="invalidate-sessions-enabled"
            className="text-[13px] text-foreground cursor-pointer"
          >
            {t('Allow invalidation on password change')}
          </Label>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || mutation.isPending}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

function serializeMockNumbers(
  list: Array<{ phone: string; otp: string }>,
): string {
  return [...list]
    .sort((a, b) => a.phone.localeCompare(b.phone))
    .map((n) => `${n.phone}:${n.otp}`)
    .join('|')
}

function mockNumbersFromCurrent(
  currentNumbers: Array<{ phone: string; otp: string }>,
): Array<{ phone: string; otp: string; id: string }> {
  return currentNumbers.map((n) => ({
    ...n,
    id: n.phone,
  }))
}

export function MockPhoneNumbersCard({
  projectId,
  currentNumbers,
}: {
  projectId: string
  currentNumbers: Array<{ phone: string; otp: string }>
}) {
  const t = useT()
  const [numbers, setNumbers] = useState(() =>
    mockNumbersFromCurrent(currentNumbers),
  )
  const [copiedItem, setCopiedItem] = useState<{
    id: string
    type: 'phone' | 'otp'
  } | null>(null)
  const mutation = useUpdateMockNumbers(projectId)
  const lastSubmittedSnapshot = useRef<string | null>(null)

  // Get project to access teamId (organization ID)
  const { project } = useProject(projectId)
  const orgId = project?.teamId

  // Get organization plan to check if mock numbers are supported
  const { plan: organizationPlan } = useOrganizationPlan(orgId)
  const supportsMockNumbers = organizationPlan?.supportsMockNumbers ?? false

  useEffect(() => {
    if (mutation.isPending) return
    const serverSnapshot = serializeMockNumbers(currentNumbers)
    if (
      lastSubmittedSnapshot.current === null ||
      serverSnapshot === lastSubmittedSnapshot.current
    ) {
      setNumbers(mockNumbersFromCurrent(currentNumbers))
      if (
        lastSubmittedSnapshot.current !== null &&
        serverSnapshot === lastSubmittedSnapshot.current
      ) {
        lastSubmittedSnapshot.current = null
      }
    }
  }, [currentNumbers, mutation.isPending])

  const hasChanges = useMemo(
    () =>
      serializeMockNumbers(numbers) !== serializeMockNumbers(currentNumbers),
    [numbers, currentNumbers],
  )

  const generatePhoneNumber = () => {
    const areaCode = Math.floor(Math.random() * 800) + 200 // 200-999
    const lineNumber = Math.floor(Math.random() * 10000) // 0000-9999
    return `+1${areaCode}555${lineNumber.toString().padStart(4, '0')}`
  }

  const generateOTP = () => {
    return Math.floor(Math.random() * 900000 + 100000).toString() // 100000-999999
  }

  const handleAddNumber = () => {
    if (numbers.length >= 10) return
    setNumbers([
      ...numbers,
      {
        id: `mock-${Date.now()}`,
        phone: generatePhoneNumber(),
        otp: generateOTP(),
      },
    ])
  }

  const handleDeleteNumber = (id: string) => {
    setNumbers(numbers.filter((n) => n.id !== id))
  }

  const handleRegeneratePhone = (id: string) => {
    setNumbers(
      numbers.map((n) =>
        n.id === id ? { ...n, phone: generatePhoneNumber() } : n,
      ),
    )
  }

  const handleRegenerateOTP = (id: string) => {
    setNumbers(
      numbers.map((n) => (n.id === id ? { ...n, otp: generateOTP() } : n)),
    )
  }

  const handlePhoneChange = (id: string, phone: string) => {
    // Validate phone format (9-16 characters, starts with +)
    if (phone.length >= 9 && phone.length <= 16 && phone.startsWith('+')) {
      setNumbers(numbers.map((n) => (n.id === id ? { ...n, phone } : n)))
    } else if (phone === '') {
      setNumbers(numbers.map((n) => (n.id === id ? { ...n, phone: '' } : n)))
    }
  }

  const handleOTPChange = (id: string, otp: string) => {
    // Validate OTP format (6 digits)
    if (/^[0-9]{0,6}$/.test(otp)) {
      setNumbers(numbers.map((n) => (n.id === id ? { ...n, otp } : n)))
    }
  }

  const handleCopy = (id: string, type: 'phone' | 'otp', value: string) => {
    navigator.clipboard.writeText(value)
    setCopiedItem({ id, type })
    setTimeout(() => setCopiedItem(null), 2000)
  }

  const handleSubmit = () => {
    const numbersToSubmit = numbers.map(({ phone, otp }) => ({ phone, otp }))
    lastSubmittedSnapshot.current = serializeMockNumbers(numbersToSubmit)
    mutation.mutate(numbersToSubmit, {
      onSuccess: () => {
        toast.success(t('Mock phone numbers have been updated'))
        // Track analytics: Submit.AuthMockNumbersUpdate
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to update mock phone numbers'))
        lastSubmittedSnapshot.current = null
        // Track analytics: trackError(error, Submit.AuthMockNumbersUpdate)
      },
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Mock phone numbers')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-1">
              {t('Generate')} <strong>{t('fictional')}</strong>{' '}
              {t(
                'numbers to simulate phone verification when testing demo accounts for submitting your application to the App Store or Google Play.',
              )}{' '}
              <DocsRouteLink className="link-neutral" href="/docs/products/auth/security#mock-phone-numbers">
                {t('Learn more')}
              </DocsRouteLink>
              .
            </p>
          </div>
        </div>
      </div>
      <div className="border-t border-border" />
      <UpgradeCurtain
        isLocked={!supportsMockNumbers}
        orgId={orgId}
        message={t(
          'Mock phone numbers are available on Appwrite Cloud Pro and higher plans.', // pragma: allowlist secret
        )}
      >
        <div>
          <div className="px-4 py-4 sm:px-6">
            {numbers.length === 0 ? (
              <div className="text-center py-8">
                <p className="text-[13px] text-muted-foreground mb-4">
                  {t('No mock phone numbers configured')}
                </p>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={handleAddNumber}
                  disabled={mutation.isPending}
                >
                  <Plus className="h-4 w-4 me-2" />
                  {t('Generate number')}
                </Button>
              </div>
            ) : (
              <div className="space-y-3">
                {numbers.map((number) => (
                  <div
                    key={number.id}
                    className="relative rounded-lg border border-border bg-muted/30 p-3 pe-11"
                  >
                    <div className="min-w-0 space-y-3">
                      <div className="space-y-2">
                        <Label className="text-[12px]">
                          {t('Phone number')}
                        </Label>
                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                          <Input
                            type="tel"
                            value={number.phone}
                            onChange={(e) =>
                              handlePhoneChange(number.id, e.target.value)
                            }
                            placeholder="+1234567890"
                            minLength={9}
                            maxLength={16}
                            disabled={mutation.isPending}
                            className="min-w-0 w-full sm:w-auto sm:flex-1"
                          />
                          <div className="flex shrink-0 items-center gap-2">
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() =>
                                handleCopy(number.id, 'phone', number.phone)
                              }
                              disabled={mutation.isPending}
                              className="h-9 w-9 p-0"
                              title={t('Copy phone number')}
                            >
                              {copiedItem?.id === number.id &&
                              copiedItem?.type === 'phone' ? (
                                <Check className="h-4 w-4 text-emerald-500" />
                              ) : (
                                <Copy className="h-4 w-4" />
                              )}
                            </Button>
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleRegeneratePhone(number.id)}
                              disabled={mutation.isPending}
                              className="h-9 w-9 p-0"
                              title={t('Regenerate phone number')}
                            >
                              <RefreshCw className="h-4 w-4" />
                            </Button>
                          </div>
                        </div>
                      </div>
                      <div className="space-y-2">
                        <Label className="text-[12px]">
                          {t('Verification code')}
                        </Label>
                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                          <InputOTP
                            maxLength={6}
                            value={number.otp}
                            onChange={(value) =>
                              handleOTPChange(number.id, value)
                            }
                            disabled={mutation.isPending}
                            containerClassName="min-w-0"
                          >
                            <InputOTPGroup>
                              {Array.from({ length: 6 }).map((_, i) => (
                                <InputOTPSlot
                                  key={i}
                                  index={i}
                                  className="h-8 w-8 text-xs sm:h-9 sm:w-9 sm:text-sm"
                                />
                              ))}
                            </InputOTPGroup>
                          </InputOTP>
                          <div className="flex shrink-0 items-center gap-2">
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() =>
                                handleCopy(number.id, 'otp', number.otp)
                              }
                              disabled={mutation.isPending}
                              className="h-9 w-9 p-0"
                              title={t('Copy verification code')}
                            >
                              {copiedItem?.id === number.id &&
                              copiedItem?.type === 'otp' ? (
                                <Check className="h-4 w-4 text-emerald-500" />
                              ) : (
                                <Copy className="h-4 w-4" />
                              )}
                            </Button>
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleRegenerateOTP(number.id)}
                              disabled={mutation.isPending}
                              className="h-9 w-9 p-0"
                              title={t('Regenerate verification code')}
                            >
                              <RefreshCw className="h-4 w-4" />
                            </Button>
                          </div>
                        </div>
                      </div>
                    </div>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => handleDeleteNumber(number.id)}
                      disabled={mutation.isPending}
                      className="absolute end-1 top-1 h-8 w-8 shrink-0 p-0"
                      aria-label={t('Remove mock phone number')}
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  </div>
                ))}
                {numbers.length < 10 && (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={handleAddNumber}
                    disabled={mutation.isPending}
                    className="w-full"
                  >
                    <Plus className="h-4 w-4 me-2" />
                    {t('Add number')}
                  </Button>
                )}
              </div>
            )}
          </div>
          <div className="border-t border-border px-4 py-4 sm:px-6 bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={!hasChanges || mutation.isPending}
              onClick={handleSubmit}
            >
              {t('Update')}
            </Button>
          </div>
        </div>
      </UpgradeCurtain>
    </div>
  )
}

export function PrivacyCard({
  projectId,
  currentPrivacy,
}: {
  projectId: string
  currentPrivacy: {
    userName: boolean
    userEmail: boolean
    mfa: boolean
    userId?: boolean
    userPhone?: boolean
  }
}) {
  const t = useT()
  const [privacy, setPrivacy] = useState(currentPrivacy)
  const mutation = useUpdateMembershipsPrivacy(projectId)
  const lastSubmittedValue = useRef<string | null>(null)

  useEffect(() => {
    // Only sync from server if:
    // 1. Mutation is not pending
    // 2. Server value matches what we expect (last submitted value), or we haven't submitted anything
    if (!mutation.isPending) {
      const currentPrivacyStr = JSON.stringify(currentPrivacy)
      if (
        lastSubmittedValue.current === null ||
        currentPrivacyStr === lastSubmittedValue.current
      ) {
        setPrivacy(currentPrivacy)
        // Reset ref once we've synced to the expected value
        if (
          lastSubmittedValue.current !== null &&
          currentPrivacyStr === lastSubmittedValue.current
        ) {
          lastSubmittedValue.current = null
        }
      }
    }
  }, [currentPrivacy, mutation.isPending])

  const hasChanges = useMemo(() => {
    return (
      privacy.userName !== currentPrivacy.userName ||
      privacy.userEmail !== currentPrivacy.userEmail ||
      privacy.mfa !== currentPrivacy.mfa
    )
  }, [privacy, currentPrivacy])

  const handleSubmit = () => {
    lastSubmittedValue.current = JSON.stringify(privacy)
    mutation.mutate(
      {
        userName: privacy.userName,
        userEmail: privacy.userEmail,
        mfa: privacy.mfa,
        userId: currentPrivacy.userId,
        userPhone: currentPrivacy.userPhone,
      },
      {
      onSuccess: () => {
        toast.success(t('Updated privacy'))
        // Track analytics: Submit.AuthMembershipPrivacyUpdate
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to update privacy'))
        // Revert on error
        lastSubmittedValue.current = null
        // Track analytics: trackError(error, Submit.AuthMembershipPrivacyUpdate)
      },
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Privacy')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-1">
              {t(
                'Choose which membership details stay private in team workflows. Many apps do not need other members to see names, emails, or MFA status - private fields stay hidden without affecting auth or team features.',
              )}{' '}
              <DocsRouteLink className="link-neutral" href="/docs/products/auth/security#memberships-privacy">
                {t('Learn more')}
              </DocsRouteLink>
              .
            </p>
          </div>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <p className="text-[12px] text-muted-foreground mb-4">
          {t(
            'Checked fields are private and hidden from other team members unless your app explicitly needs them.',
          )}
        </p>
        <div className="space-y-4">
          <div className="flex items-start gap-3">
            <Checkbox
              id="privacy-user-name"
              checked={privacy.userName}
              onCheckedChange={(checked) =>
                setPrivacy({ ...privacy, userName: checked === true })
              }
              disabled={mutation.isPending}
              className="mt-0.5"
            />
            <div className="min-w-0 flex-1">
              <Label
                htmlFor="privacy-user-name"
                className="text-[13px] font-medium text-foreground cursor-pointer"
              >
                {t('Name')}
              </Label>
              <p className="text-[12px] text-muted-foreground mt-0.5">
                {t('Hide member display names in team and membership views.')}
              </p>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <Checkbox
              id="privacy-user-email"
              checked={privacy.userEmail}
              onCheckedChange={(checked) =>
                setPrivacy({ ...privacy, userEmail: checked === true })
              }
              disabled={mutation.isPending}
              className="mt-0.5"
            />
            <div className="min-w-0 flex-1">
              <Label
                htmlFor="privacy-user-email"
                className="text-[13px] font-medium text-foreground cursor-pointer"
              >
                {t('Email')}
              </Label>
              <p className="text-[12px] text-muted-foreground mt-0.5">
                {t(
                  "Hide email addresses so members cannot see each other's contact details.",
                )}
              </p>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <Checkbox
              id="privacy-mfa"
              checked={privacy.mfa}
              onCheckedChange={(checked) =>
                setPrivacy({ ...privacy, mfa: checked === true })
              }
              disabled={mutation.isPending}
              className="mt-0.5"
            />
            <div className="min-w-0 flex-1">
              <Label
                htmlFor="privacy-mfa"
                className="text-[13px] font-medium text-foreground cursor-pointer"
              >
                {t('MFA status')}
              </Label>
              <p className="text-[12px] text-muted-foreground mt-0.5">
                {t(
                  'Hide whether a member has multi-factor authentication enabled.',
                )}
              </p>
            </div>
          </div>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || mutation.isPending}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
