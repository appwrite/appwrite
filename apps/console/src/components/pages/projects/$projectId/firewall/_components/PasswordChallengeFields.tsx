import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { NumberInput } from '@/components/ui/number-input'
import {
  CHALLENGE_ATTEMPTS_INTERVAL_MAX,
  CHALLENGE_ATTEMPTS_INTERVAL_MIN,
  CHALLENGE_ATTEMPTS_MAX,
  CHALLENGE_ATTEMPTS_MIN,
  isChallengePasswordValid,
} from '@/lib/firewall/actions'
import { useT } from '@/lib/i18n/translate'

type PasswordChallengeFieldsProps = {
  /** Prefix for the inputs' ids, unique per form. */
  idPrefix: string
  password: string
  onPasswordChange: (password: string) => void
  /** The rule already has a password: leaving the field blank keeps it. */
  hasStoredPassword: boolean
  attempts: number
  onAttemptsChange: (attempts: number) => void
  attemptsInterval: number
  onAttemptsIntervalChange: (attemptsInterval: number) => void
  disabled?: boolean
}

/** The password, guess limit and window of a password challenge rule. */
export function PasswordChallengeFields({
  idPrefix,
  password,
  onPasswordChange,
  hasStoredPassword,
  attempts,
  onAttemptsChange,
  attemptsInterval,
  onAttemptsIntervalChange,
  disabled,
}: PasswordChallengeFieldsProps) {
  const t = useT()
  const attemptsOutOfRange =
    !Number.isInteger(attempts) ||
    attempts < CHALLENGE_ATTEMPTS_MIN ||
    attempts > CHALLENGE_ATTEMPTS_MAX
  const intervalOutOfRange =
    !Number.isInteger(attemptsInterval) ||
    attemptsInterval < CHALLENGE_ATTEMPTS_INTERVAL_MIN ||
    attemptsInterval > CHALLENGE_ATTEMPTS_INTERVAL_MAX

  return (
    <>
      <div className="space-y-1.5 sm:col-span-2">
        <Label htmlFor={`${idPrefix}-password`} className="text-[12px]">
          {t('Password')}
        </Label>
        <Input
          id={`${idPrefix}-password`}
          type="password"
          autoComplete="new-password"
          value={password}
          onChange={(e) => onPasswordChange(e.target.value)}
          placeholder={t('At least 8 characters')}
          disabled={disabled}
          aria-invalid={
            password !== '' && !isChallengePasswordValid(password, true)
          }
        />
        {password && !isChallengePasswordValid(password, true) ? (
          <p className="text-[12px] text-destructive">
            {t('Use 8 to 256 characters.')}
          </p>
        ) : (
          <p className="text-[12px] text-muted-foreground">
            {hasStoredPassword
              ? t('Leave blank to keep the current password.')
              : t(
                  'Visitors must enter this password before they can continue.',
                )}
          </p>
        )}
      </div>
      <div className="space-y-1.5">
        <Label htmlFor={`${idPrefix}-attempts`} className="text-[12px]">
          {t('Wrong guesses allowed')}
        </Label>
        <NumberInput
          id={`${idPrefix}-attempts`}
          min={CHALLENGE_ATTEMPTS_MIN}
          max={CHALLENGE_ATTEMPTS_MAX}
          step={1}
          value={attempts}
          onValueChange={onAttemptsChange}
          disabled={disabled}
          aria-invalid={attemptsOutOfRange}
        />
        <p
          className={
            attemptsOutOfRange
              ? 'text-[12px] text-destructive'
              : 'text-[12px] text-muted-foreground'
          }
        >
          {t('1 to 100 per visitor network.')}
        </p>
      </div>
      <div className="space-y-1.5">
        <Label
          htmlFor={`${idPrefix}-attempts-interval`}
          className="text-[12px]"
        >
          {t('Counted over (seconds)')}
        </Label>
        <NumberInput
          id={`${idPrefix}-attempts-interval`}
          min={CHALLENGE_ATTEMPTS_INTERVAL_MIN}
          max={CHALLENGE_ATTEMPTS_INTERVAL_MAX}
          step={1}
          value={attemptsInterval}
          onValueChange={onAttemptsIntervalChange}
          disabled={disabled}
          aria-invalid={intervalOutOfRange}
        />
        <p
          className={
            intervalOutOfRange
              ? 'text-[12px] text-destructive'
              : 'text-[12px] text-muted-foreground'
          }
        >
          {t('60 (1 minute) to 86400 (24 hours).')}
        </p>
      </div>
    </>
  )
}
