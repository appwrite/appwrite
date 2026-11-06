import { useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import {
  AlertCircle,
  CheckCircle2,
  ChevronDown,
  Eye,
  EyeOff,
  Minus,
  Plus,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  useAuthPasswordStrengthComplianceOpen,
  useUpdateAuthPasswordStrength,
} from '@/lib/react-query/hooks'
import {
  clampPasswordMinLength,
  checkStandardCompliance,
  MAX_PASSWORD_LENGTH,
  MIN_PASSWORD_LENGTH,
  PASSWORD_STRENGTH_PRESETS,
  PASSWORD_STRENGTH_STANDARDS,
  findMatchingPasswordStrengthPreset,
  policiesEqual,
  type PasswordStrengthPolicy,
  validatePasswordAgainstPolicy,
} from '@/lib/password-strength'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type PasswordStrengthCardProps = {
  projectId: string
  currentPolicy: PasswordStrengthPolicy
}

export function PasswordStrengthCard({
  projectId,
  currentPolicy,
}: PasswordStrengthCardProps) {
  const t = useT()
  const [policy, setPolicy] = useState<PasswordStrengthPolicy>(currentPolicy)
  const [demoEmail, setDemoEmail] = useState('user@example.com')
  const [demoPassword, setDemoPassword] = useState('')
  const [showDemoPassword, setShowDemoPassword] = useState(false)
  const [demoChecked, setDemoChecked] = useState(false)
  const { account } = useAuth()
  const { isOpen: complianceOpen, setIsOpen: setComplianceOpen } =
    useAuthPasswordStrengthComplianceOpen(account)
  const mutation = useUpdateAuthPasswordStrength(projectId)
  const lastSubmittedValue = useRef<string | null>(null)

  useEffect(() => {
    if (!mutation.isPending) {
      const currentStr = JSON.stringify(currentPolicy)
      if (
        lastSubmittedValue.current === null ||
        currentStr === lastSubmittedValue.current
      ) {
        setPolicy(currentPolicy)
        if (
          lastSubmittedValue.current !== null &&
          currentStr === lastSubmittedValue.current
        ) {
          lastSubmittedValue.current = null
        }
      }
    }
  }, [currentPolicy, mutation.isPending])

  const hasChanges = !policiesEqual(policy, currentPolicy)

  const demoValidation = useMemo(() => {
    if (!demoChecked || !demoPassword) return null
    return validatePasswordAgainstPolicy(demoPassword, policy)
  }, [demoChecked, demoPassword, policy])

  const standardResults = useMemo(
    () =>
      PASSWORD_STRENGTH_STANDARDS.map((standard) =>
        checkStandardCompliance(policy, standard),
      ),
    [policy],
  )

  const standardResultColumns = useMemo(() => {
    const mid = Math.ceil(standardResults.length / 2)
    return [
      standardResults.slice(0, mid),
      standardResults.slice(mid),
    ]
  }, [standardResults])

  const matchingPresetId = useMemo(
    () => findMatchingPasswordStrengthPreset(policy)?.id,
    [policy],
  )

  const complianceSummary = useMemo(() => {
    const compliantCount = standardResults.filter((r) => r.compliant).length
    const total = standardResults.length
    return {
      compliantCount,
      gapCount: total - compliantCount,
      total,
    }
  }, [standardResults])

  const handleSubmit = () => {
    const payload = { ...policy }
    lastSubmittedValue.current = JSON.stringify(payload)
    mutation.mutate(payload, {
      onSuccess: () => {
        toast.success(t('Updated password strength requirements'))
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to update password strength'))
        lastSubmittedValue.current = null
      },
    })
  }

  const handlePresetChange = (presetId: string) => {
    const preset = PASSWORD_STRENGTH_PRESETS.find((p) => p.id === presetId)
    if (preset) {
      setPolicy(preset.policy)
      setDemoChecked(false)
    }
  }

  const handleValidateDemo = () => {
    if (!demoPassword.trim()) {
      toast.error(t('Enter a password to validate'))
      return
    }
    setDemoChecked(true)
  }

  const updatePolicyField = <K extends keyof PasswordStrengthPolicy>(
    key: K,
    value: PasswordStrengthPolicy[K],
  ) => {
    setPolicy((prev) => ({ ...prev, [key]: value }))
    setDemoChecked(false)
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Strength')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Set minimum length and character requirements for user passwords. Rules apply when users sign up, reset their password, or change their password through your app. Existing passwords stay valid until the user sets a new one. Password updates from the Appwrite console also validate against this policy.', // pragma: allowlist secret
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="grid lg:grid-cols-2 divide-y lg:divide-y-0 lg:divide-x divide-border">
        <div className="px-6 py-4 space-y-6 bg-muted/10">
          <div className="space-y-2">
            <Label className="text-[13px]">{t('Preset')}</Label>
            <Select
              value={matchingPresetId ?? undefined}
              onValueChange={handlePresetChange}
            >
              <SelectTrigger className="w-full h-9 text-[13px]">
                <SelectValue placeholder={t('Custom settings')} />
              </SelectTrigger>
              <SelectContent>
                {PASSWORD_STRENGTH_PRESETS.map((preset) => (
                  <SelectItem key={preset.id} value={preset.id}>
                    {t(preset.label)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-[12px] text-muted-foreground">
              {t(
                'Presets adjust the fields below. Review and click Update to save.',
              )}
            </p>
          </div>

          <div className="space-y-2">
            <Label
              id="password-strength-min-label"
              className="text-[13px]"
            >
              {t('Minimum length')}
            </Label>
            <MinLengthStepper
              id="password-strength-min"
              labelId="password-strength-min-label"
              value={policy.min}
              onChange={(min) => updatePolicyField('min', min)}
              disabled={mutation.isPending}
              min={MIN_PASSWORD_LENGTH}
              max={MAX_PASSWORD_LENGTH}
            />
            <p className="text-[12px] text-muted-foreground">
              {MIN_PASSWORD_LENGTH}–{MAX_PASSWORD_LENGTH} {t('characters')}
            </p>
          </div>

          <div className="space-y-3">
            <p className="text-[13px] font-medium text-foreground">
              {t('Required character types')}
            </p>
            <div className="space-y-3">
              <CharacterTypeRow
                id="password-strength-uppercase"
                label={t('Uppercase letters (A-Z)')}
                checked={policy.uppercase}
                disabled={mutation.isPending}
                onCheckedChange={(checked) =>
                  updatePolicyField('uppercase', checked === true)
                }
              />
              <CharacterTypeRow
                id="password-strength-lowercase"
                label={t('Lowercase letters (a-z)')}
                checked={policy.lowercase}
                disabled={mutation.isPending}
                onCheckedChange={(checked) =>
                  updatePolicyField('lowercase', checked === true)
                }
              />
              <CharacterTypeRow
                id="password-strength-number"
                label={t('Numbers (0-9)')}
                checked={policy.number}
                disabled={mutation.isPending}
                onCheckedChange={(checked) =>
                  updatePolicyField('number', checked === true)
                }
              />
              <CharacterTypeRow
                id="password-strength-symbols"
                label={t('Symbols (non-alphanumeric)')}
                checked={policy.symbols}
                disabled={mutation.isPending}
                onCheckedChange={(checked) =>
                  updatePolicyField('symbols', checked === true)
                }
              />
            </div>
          </div>
        </div>

        <div className="px-6 py-4 bg-muted/10 flex flex-col">
          <div className="mb-4">
            <p className="text-[13px] font-medium text-foreground">
              {t('Demo sign-up')}
            </p>
            <p className="text-[12px] text-muted-foreground mt-1">
              {t(
                'Test a password against the settings on the left before you save. This preview uses the same rules as Appwrite auth validation.', // pragma: allowlist secret
              )}
            </p>
          </div>

          <Card
            className="w-full max-w-sm mx-auto overflow-hidden py-0 bg-background border-border ring-1 ring-border/80"
          >
            <div className="px-5 py-6 space-y-5">
              <div className="space-y-1">
                <h4 className="text-[17px] font-semibold tracking-tight text-foreground">
                  {t('Create an account')}
                </h4>
                <p className="text-[13px] text-muted-foreground">
                  {t('Enter your details to test password rules')}
                </p>
              </div>

              <form
                className="space-y-5"
                onSubmit={(e) => {
                  e.preventDefault()
                  handleValidateDemo()
                }}
              >
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="demo-email" className="text-[13px]">
                    {t('Email')}
                  </Label>
                  <Input
                    id="demo-email"
                    type="email"
                    placeholder="m@example.com"
                    value={demoEmail}
                    onChange={(e) => setDemoEmail(e.target.value)}
                    disabled={mutation.isPending}
                    className="h-9 text-[13px]"
                    autoComplete="off"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="demo-password" className="text-[13px]">
                    {t('Password')}
                  </Label>
                  <div className="flex gap-2">
                    <Input
                      id="demo-password"
                      type={showDemoPassword ? 'text' : 'password'}
                      value={demoPassword}
                      onChange={(e) => {
                        setDemoPassword(e.target.value)
                        setDemoChecked(false)
                      }}
                      disabled={mutation.isPending}
                      className="h-9 text-[13px]"
                      autoComplete="new-password"
                      placeholder={t('Try a password...')}
                    />
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      className="h-9 w-9 shrink-0"
                      onClick={() => setShowDemoPassword((v) => !v)}
                      aria-label={
                        showDemoPassword
                          ? t('Hide password')
                          : t('Show password')
                      }
                    >
                      {showDemoPassword ? (
                        <EyeOff className="h-4 w-4" />
                      ) : (
                        <Eye className="h-4 w-4" />
                      )}
                    </Button>
                  </div>
                </div>
              </div>

              {demoValidation && (
                <div className="space-y-2 rounded-lg border border-border bg-muted/50 px-3 py-3">
                  {demoValidation.valid ? (
                    <div className="flex items-start gap-2 text-[13px] text-emerald-600 dark:text-emerald-400">
                      <CheckCircle2 className="h-4 w-4 shrink-0 mt-0.5" />
                      <span>
                        {t('This password meets your current policy settings.')}
                      </span>
                    </div>
                  ) : (
                    <div className="space-y-2">
                      <div className="flex items-start gap-2 text-[13px] text-red-600 dark:text-red-400">
                        <AlertCircle className="h-4 w-4 shrink-0 mt-0.5" />
                        <span>
                          {t('This password does not meet your policy.')}
                        </span>
                      </div>
                      <ul className="space-y-2">
                        {demoValidation.failures.map((failure) => (
                          <li
                            key={failure}
                            className="flex items-start gap-2 text-[12px] text-muted-foreground"
                          >
                            <AlertCircle
                              className="h-3.5 w-3.5 shrink-0 mt-0.5 text-red-600 dark:text-red-400"
                              aria-hidden
                            />
                            <span>{t(failure)}</span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              )}

              <Button
                type="submit"
                className="w-full h-9 text-[13px]"
                disabled={mutation.isPending}
              >
                {t('Validate password')}
              </Button>

              <p className="text-center text-[12px] text-muted-foreground">
                {t('Preview only. No account is created.')}
              </p>
              </form>
            </div>
          </Card>
        </div>
      </div>

      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <Collapsible open={complianceOpen} onOpenChange={setComplianceOpen}>
          <CollapsibleTrigger asChild>
            <button
              type="button"
              className="flex w-full cursor-pointer items-center justify-between gap-4 py-1 text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md"
              aria-expanded={complianceOpen}
            >
              <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                <span className="text-[13px] font-semibold text-foreground">
                  {t('Compliance')}
                </span>
                <Badge variant="success" className="text-[10px] shrink-0">
                  {complianceSummary.compliantCount}/{complianceSummary.total}{' '}
                  {t('compliant')}
                </Badge>
                {complianceSummary.gapCount > 0 && (
                  <Badge variant="error" className="text-[10px] shrink-0">
                    {complianceSummary.gapCount}{' '}
                    {complianceSummary.gapCount === 1 ? t('gap') : t('gaps')}
                  </Badge>
                )}
              </div>
              <ChevronDown
                className={cn(
                  'h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200',
                  complianceOpen && 'rotate-180',
                )}
                aria-hidden
              />
            </button>
          </CollapsibleTrigger>
          <CollapsibleContent className="space-y-3 pt-3">
            <p className="text-[12px] text-muted-foreground leading-relaxed">
              {t(
                'See whether your current settings meet widely used password guidance. Stricter policies than a standard still count as compliant.',
              )}
            </p>
            <div className="rounded-lg border border-border overflow-hidden grid lg:grid-cols-2 divide-y lg:divide-y-0 lg:divide-x divide-border">
              {standardResultColumns.map((column, columnIndex) => (
                <div
                  key={columnIndex}
                  className="divide-y divide-border bg-muted/10"
                >
                  {column.map((result) => (
                    <StandardRow key={result.standard.id} result={result} />
                  ))}
                </div>
              ))}
            </div>
          </CollapsibleContent>
        </Collapsible>
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

function MinLengthStepper({
  id,
  labelId,
  value,
  onChange,
  disabled,
  min,
  max,
}: {
  id: string
  labelId: string
  value: number
  onChange: (value: number) => void
  disabled?: boolean
  min: number
  max: number
}) {
  const t = useT()
  const [draft, setDraft] = useState<string | null>(null)
  const clamp = (n: number) => clampPasswordMinLength(n, min, max)
  const maxDigits = String(max).length

  useEffect(() => {
    setDraft(null)
  }, [value])

  const displayValue = draft ?? String(value)
  const parsedDraft =
    draft !== null && draft !== '' ? parseInt(draft, 10) : null
  const effectiveValue =
    parsedDraft !== null && Number.isFinite(parsedDraft)
      ? clamp(parsedDraft)
      : value

  const commitDraft = (raw: string) => {
    const trimmed = raw.trim()
    if (trimmed === '') {
      onChange(clamp(value))
    } else {
      const parsed = parseInt(trimmed, 10)
      onChange(
        Number.isFinite(parsed) ? clamp(parsed) : clamp(value),
      )
    }
    setDraft(null)
  }

  const stepValue = (delta: number) => {
    const parsedDraft =
      draft !== null && draft !== '' ? parseInt(draft, 10) : NaN
    const base = Number.isFinite(parsedDraft) ? parsedDraft : value
    onChange(clamp(base + delta))
    setDraft(null)
  }

  return (
    <div
      id={id}
      className="flex items-center gap-1.5"
      role="group"
      aria-labelledby={labelId}
    >
      <Button
        type="button"
        variant="outline"
        size="icon"
        className="size-8"
        onClick={() => stepValue(-1)}
        disabled={disabled || effectiveValue <= min}
        aria-label={t('Decrease minimum length')}
      >
        <Minus className="size-3.5" />
      </Button>
      <Input
        type="text"
        inputMode="numeric"
        pattern="[0-9]*"
        value={displayValue}
        disabled={disabled}
        aria-label={t('Minimum password length')}
        aria-valuemin={min}
        aria-valuemax={max}
        aria-valuenow={value}
        className="h-8 min-w-10 w-12 px-1 text-center text-[13px] font-semibold tabular-nums bg-muted/40 shadow-none focus-visible:ring-1"
        onChange={(e) => {
          const raw = e.target.value.replace(/\D/g, '').slice(0, maxDigits)
          if (raw === '') {
            setDraft('')
            return
          }
          const parsed = parseInt(raw, 10)
          if (!Number.isFinite(parsed)) return
          if (parsed > max) {
            onChange(max)
            setDraft(String(max))
            return
          }
          setDraft(raw)
          if (parsed >= min) {
            onChange(parsed)
          }
        }}
        onBlur={() => {
          if (draft !== null) {
            commitDraft(draft)
          }
        }}
        onKeyDown={(e) => {
          if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault()
            const step = e.shiftKey ? 10 : 1
            const delta = e.key === 'ArrowUp' ? step : -step
            stepValue(delta)
            return
          }
          if (e.key === 'Enter') {
            e.preventDefault()
            if (draft !== null) {
              commitDraft(draft)
            }
            e.currentTarget.blur()
          }
        }}
      />
      <Button
        type="button"
        variant="outline"
        size="icon"
        className="size-8"
        onClick={() => stepValue(1)}
        disabled={disabled || effectiveValue >= max}
        aria-label={t('Increase minimum length')}
      >
        <Plus className="size-3.5" />
      </Button>
    </div>
  )
}

function CharacterTypeRow({
  id,
  label,
  checked,
  disabled,
  onCheckedChange,
}: {
  id: string
  label: string
  checked: boolean
  disabled?: boolean
  onCheckedChange: (checked: boolean) => void
}) {
  return (
    <div className="flex items-center gap-3">
      <Checkbox
        id={id}
        checked={checked}
        onCheckedChange={(value) => onCheckedChange(value === true)}
        disabled={disabled}
      />
      <Label
        htmlFor={id}
        className="text-[13px] text-foreground cursor-pointer"
      >
        {label}
      </Label>
    </div>
  )
}

function StandardRow({
  result,
}: {
  result: ReturnType<typeof checkStandardCompliance>
}) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const { standard, compliant, reasons } = result

  return (
    <Collapsible open={open} onOpenChange={setOpen}>
      <div className="px-4 py-3">
        <div className="flex items-start gap-3">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-[13px] font-medium text-foreground">
                {standard.name}
              </p>
              <Badge
                variant={compliant ? 'success' : 'error'}
                className="text-[10px] shrink-0"
              >
                {compliant ? t('Compliant') : t('Not compliant')}
              </Badge>
            </div>
            <p className="text-[12px] text-muted-foreground mt-1">
              {t(standard.description)}
            </p>
          </div>
          {!compliant && (
            <CollapsibleTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 shrink-0 text-[12px] text-muted-foreground"
              >
                {t('Why')}
                <ChevronDown
                  className={`h-3.5 w-3.5 ms-1 transition-transform ${open ? 'rotate-180' : ''}`}
                />
              </Button>
            </CollapsibleTrigger>
          )}
        </div>
        <CollapsibleContent>
          {!compliant && reasons.length > 0 && (
            <ul className="mt-2 space-y-2">
              {reasons.map((reason) => (
                <li
                  key={reason}
                  className="flex items-start gap-2 text-[12px] text-muted-foreground"
                >
                  <AlertCircle
                    className="h-3.5 w-3.5 shrink-0 mt-0.5 text-red-600 dark:text-red-400"
                    aria-hidden
                  />
                  <span>{t(reason)}</span>
                </li>
              ))}
            </ul>
          )}
        </CollapsibleContent>
      </div>
    </Collapsible>
  )
}
