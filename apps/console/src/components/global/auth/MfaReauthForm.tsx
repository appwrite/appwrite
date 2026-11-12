import { useEffect, useMemo, useRef, useState } from 'react'
import { AuthenticationFactor } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSeparator,
  InputOTPSlot,
} from '@/components/ui/input-otp'
import { Mail, Smartphone } from 'lucide-react'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

type MfaFactorsWithRecovery = Models.MfaFactors & { recoveryCode?: boolean }

/**
 * Verify MFA for sensitive account actions (e.g. regenerate recovery codes).
 * Matches the old console `verify()` in mfaChallengeFormList.svelte.
 */
export async function verifyMfaReauth(
  challenge: Models.MfaChallenge | null,
  code: string,
  challengeType: AuthenticationFactor = AuthenticationFactor.Totp,
  factors?: MfaFactorsWithRecovery,
) {
  let activeChallenge = challenge
  const otp = code.trim()

  if (!otp) {
    throw new Error('Please enter a verification code')
  }

  if (!activeChallenge) {
    if (factors) {
      const factorMap = {
        [AuthenticationFactor.Totp]: factors.totp,
        [AuthenticationFactor.Email]: factors.email,
        [AuthenticationFactor.Phone]: factors.phone,
        [AuthenticationFactor.Recoverycode]: factors.recoveryCode || false,
      }

      if (!factorMap[challengeType]) {
        throw new Error(`Authentication factor ${challengeType} is not enabled`)
      }
    }

    activeChallenge = await sdk.forConsole.account.createMFAChallenge({
      factor: challengeType,
    })
  }

  await sdk.forConsole.account.updateMFAChallenge({
    challengeId: activeChallenge.$id,
    otp,
  })
}

type UseMfaReauthOptions = {
  factors: MfaFactorsWithRecovery
  /** When true, recovery codes cannot be used to verify (e.g. regenerating recovery codes). */
  excludeRecoveryCode?: boolean
  /** When true, initialize an MFA challenge for the default factor. */
  open?: boolean
}

function getDefaultFactor(
  factors: MfaFactorsWithRecovery,
): AuthenticationFactor | null {
  if (factors.totp) return AuthenticationFactor.Totp
  if (factors.email) return AuthenticationFactor.Email
  if (factors.phone) return AuthenticationFactor.Phone
  return null
}

export function useMfaReauth({
  factors,
  excludeRecoveryCode = false,
  open = false,
}: UseMfaReauthOptions) {
  const effectiveFactors = useMemo(
    () =>
      excludeRecoveryCode ? { ...factors, recoveryCode: false } : factors,
    [excludeRecoveryCode, factors],
  )

  const [challengeType, setChallengeTypeState] =
    useState<AuthenticationFactor | null>(null)
  const [challenge, setChallengeState] = useState<Models.MfaChallenge | null>(
    null,
  )
  const [code, setCodeState] = useState('')
  const [disabled, setDisabled] = useState(false)
  const [isChallengeReady, setIsChallengeReady] = useState(false)

  const challengeTypeRef = useRef<AuthenticationFactor | null>(null)
  const challengeRef = useRef<Models.MfaChallenge | null>(null)
  const codeRef = useRef('')
  const challengeRequestIdRef = useRef(0)

  const setChallengeType = (value: AuthenticationFactor | null) => {
    challengeTypeRef.current = value
    setChallengeTypeState(value)
  }

  const setChallenge = (value: Models.MfaChallenge | null) => {
    challengeRef.current = value
    setChallengeState(value)
  }

  const setCode = (value: string) => {
    codeRef.current = value
    setCodeState(value)
  }

  const enabledMainFactors = useMemo(
    () =>
      [
        effectiveFactors.totp && AuthenticationFactor.Totp,
        effectiveFactors.email && AuthenticationFactor.Email,
        effectiveFactors.phone && AuthenticationFactor.Phone,
      ].filter(Boolean) as AuthenticationFactor[],
    [effectiveFactors],
  )

  const clearFormState = () => {
    setChallenge(null)
    setCode('')
    setDisabled(false)
    setIsChallengeReady(false)
    setChallengeType(getDefaultFactor(effectiveFactors))
  }

  const createChallenge = async (factor: AuthenticationFactor) => {
    const requestId = ++challengeRequestIdRef.current
    setDisabled(true)
    setChallengeType(factor)
    setCode('')
    setChallenge(null)

    if (
      factor === AuthenticationFactor.Totp ||
      factor === AuthenticationFactor.Recoverycode
    ) {
      setIsChallengeReady(true)
      setDisabled(false)
      return
    }

    setIsChallengeReady(false)

    try {
      const newChallenge = await sdk.forConsole.account.createMFAChallenge({
        factor,
      })
      if (requestId !== challengeRequestIdRef.current) return
      setChallenge(newChallenge)
      setIsChallengeReady(true)
    } catch (error: unknown) {
      if (requestId !== challengeRequestIdRef.current) return
      throw new Error(getErrorMessage(error, 'Failed to create challenge'))
    } finally {
      if (requestId === challengeRequestIdRef.current) {
        setDisabled(false)
      }
    }
  }

  useEffect(() => {
    if (!open) {
      challengeRequestIdRef.current += 1
      return
    }

    clearFormState()
    const defaultFactor = getDefaultFactor(effectiveFactors)
    if (!defaultFactor) return

    void createChallenge(defaultFactor).catch(() => {
      setIsChallengeReady(false)
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const verify = async (codeOverride?: string) => {
    if (codeOverride !== undefined) {
      setCode(codeOverride)
    }

    const type = challengeTypeRef.current
    const otp = codeRef.current.trim()

    if (!type) {
      throw new Error('Please select an authentication method')
    }

    if (!otp) {
      throw new Error('Please enter a verification code')
    }

    await verifyMfaReauth(
      challengeRef.current,
      otp,
      type,
      effectiveFactors,
    )
  }

  const isCodeValid =
    challengeType === AuthenticationFactor.Recoverycode
      ? code.trim().length > 0
      : code.length === 6

  const reset = () => {
    challengeRequestIdRef.current += 1
    clearFormState()
    if (open) {
      const defaultFactor = getDefaultFactor(effectiveFactors)
      if (defaultFactor) {
        void createChallenge(defaultFactor).catch(() => {
          setIsChallengeReady(false)
        })
      }
    }
  }

  return {
    effectiveFactors,
    enabledMainFactors,
    challengeType,
    challenge,
    code,
    setCode,
    disabled,
    isChallengeReady,
    createChallenge,
    verify,
    isCodeValid,
    reset,
  }
}

type MfaReauthFormProps = {
  reauth: ReturnType<typeof useMfaReauth>
}

function getFactorDescription(challengeType: AuthenticationFactor | null) {
  switch (challengeType) {
    case AuthenticationFactor.Totp:
      return 'Enter a 6-digit one-time code from your authenticator app.'
    case AuthenticationFactor.Email:
      return 'A 6-digit verification code was sent to your email. Enter it below.'
    case AuthenticationFactor.Phone:
      return 'A 6-digit verification code was sent to your phone. Enter it below.'
    case AuthenticationFactor.Recoverycode:
      return 'Enter one of the recovery codes you received when enabling MFA.'
    default:
      return ''
  }
}

export function MfaReauthForm({ reauth }: MfaReauthFormProps) {
  const t = useT()
  const {
    effectiveFactors,
    enabledMainFactors,
    challengeType,
    code,
    setCode,
    disabled,
    isChallengeReady,
    createChallenge,
  } = reauth

  if (enabledMainFactors.length === 0) {
    return null
  }

  const showSecondaryOptions =
    enabledMainFactors.length > 1 ||
    (effectiveFactors.recoveryCode &&
      challengeType !== AuthenticationFactor.Recoverycode)

  return (
    <div className="space-y-4">
      {!isChallengeReady && (
        <p className="text-[13px] text-muted-foreground">
          {t('Preparing verification...')}
        </p>
      )}
      {challengeType && (
        <div className="space-y-3">
          <Label htmlFor="mfa-reauth-code">
            {challengeType === AuthenticationFactor.Recoverycode
              ? t('Recovery code')
              : t('Verification code')}
          </Label>
          <p className="text-[13px] text-muted-foreground">
            {t(getFactorDescription(challengeType))}
          </p>
          {challengeType === AuthenticationFactor.Recoverycode ? (
            <Input
              id="mfa-reauth-code"
              type="text"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder={t('Enter recovery code')}
              disabled={disabled || !isChallengeReady}
              autoFocus
              className="font-mono text-[13px]"
            />
          ) : (
            <InputOTP
              maxLength={6}
              value={code}
              onChange={setCode}
              disabled={disabled || !isChallengeReady}
              containerClassName="w-full justify-center"
            >
              <InputOTPGroup className="flex-1">
                <InputOTPSlot index={0} className="h-12 w-full text-xl" />
                <InputOTPSlot index={1} className="h-12 w-full text-xl" />
                <InputOTPSlot index={2} className="h-12 w-full text-xl" />
              </InputOTPGroup>
              <InputOTPSeparator />
              <InputOTPGroup className="flex-1">
                <InputOTPSlot index={3} className="h-12 w-full text-xl" />
                <InputOTPSlot index={4} className="h-12 w-full text-xl" />
                <InputOTPSlot index={5} className="h-12 w-full text-xl" />
              </InputOTPGroup>
            </InputOTP>
          )}
        </div>
      )}

      {showSecondaryOptions && (
        <>
          <div className="relative py-1">
            <div className="absolute inset-0 flex items-center">
              <span className="w-full border-t border-border" />
            </div>
            <div className="relative flex justify-center text-[11px] uppercase tracking-wider">
              <span className="bg-background px-2 text-muted-foreground">
                {t('or')}
              </span>
            </div>
          </div>
          <div className="flex flex-col gap-2">
            {effectiveFactors.totp &&
              challengeType !== AuthenticationFactor.Totp && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 justify-start text-[13px]"
                  onClick={() => void createChallenge(AuthenticationFactor.Totp)}
                  disabled={disabled}
                >
                  <Smartphone className="me-1.5 h-4 w-4" />
                  {t('Authenticator app')}
                </Button>
              )}
            {effectiveFactors.email &&
              challengeType !== AuthenticationFactor.Email && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 justify-start text-[13px]"
                  onClick={() => void createChallenge(AuthenticationFactor.Email)}
                  disabled={disabled}
                >
                  <Mail className="me-1.5 h-4 w-4" />
                  {t('Email verification')}
                </Button>
              )}
            {effectiveFactors.phone &&
              challengeType !== AuthenticationFactor.Phone && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 justify-start text-[13px]"
                  onClick={() => void createChallenge(AuthenticationFactor.Phone)}
                  disabled={disabled}
                >
                  <Smartphone className="me-1.5 h-4 w-4" />
                  {t('Phone verification')}
                </Button>
              )}
            {effectiveFactors.recoveryCode &&
              challengeType !== AuthenticationFactor.Recoverycode && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() =>
                    void createChallenge(AuthenticationFactor.Recoverycode)
                  }
                  disabled={disabled}
                >
                  {t('Use recovery code')}
                </Button>
              )}
          </div>
        </>
      )}
    </div>
  )
}
