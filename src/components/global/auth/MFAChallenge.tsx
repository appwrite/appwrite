'use client'

import { useEffect, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useNavigate, useRouter } from '@tanstack/react-router'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { AuthenticationFactor } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSeparator,
  InputOTPSlot,
} from '@/components/ui/input-otp'
import { Label } from '@/components/ui/label'
import { Card } from '@/components/ui/card'
import { ArrowLeft, Smartphone, Mail } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import { refreshConsoleAccountAfterAuth } from '@/lib/react-query/hooks/auth'
import {
  prefetchPostAuthDestination,
  requiresConsoleEmailVerification,
  resolvePostAuthRedirect,
  toRedirectNavigateOptions,
} from '@/lib/post-auth-navigation'
import { useT } from '@/lib/i18n/translate'

interface MFAChallengeProps {
  factors: Models.MfaFactors & { recoveryCode?: boolean }
  redirect?: string
}

function getDefaultChallengeType(
  factors: Models.MfaFactors & { recoveryCode?: boolean },
): AuthenticationFactor | null {
  if (factors.totp) return AuthenticationFactor.Totp
  if (factors.email) return AuthenticationFactor.Email
  if (factors.phone) return AuthenticationFactor.Phone
  if (factors.recoveryCode) return AuthenticationFactor.Recoverycode
  return null
}

function hasAnyMfaFactor(
  factors: Models.MfaFactors & { recoveryCode?: boolean },
) {
  return Boolean(
    factors.totp ||
      factors.email ||
      factors.phone ||
      factors.recoveryCode,
  )
}

function readOtpFromForm(form: HTMLFormElement, fallback: string) {
  const otpInput = form.querySelector(
    'input[data-input-otp]',
  ) as HTMLInputElement | null
  return (otpInput?.value ?? fallback).trim()
}

/**
 * Verify MFA challenge with the provided code
 */
export async function verifyMFAChallenge(
  challenge: Models.MfaChallenge | null,
  code: string,
  challengeType: AuthenticationFactor,
  factors?: Models.MfaFactors & { recoveryCode?: boolean },
) {
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

  let activeChallenge = challenge
  const otp = code.trim()

  if (!otp) {
    throw new Error('Please enter a verification code')
  }

  if (!activeChallenge) {
    if (
      challengeType === AuthenticationFactor.Email ||
      challengeType === AuthenticationFactor.Phone
    ) {
      throw new Error('Challenge must be created for Email/Phone factors')
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

export function MFAChallenge({ factors, redirect }: MFAChallengeProps) {
  const t = useT()
  const navigate = useNavigate()
  const router = useRouter()
  const queryClient = useQueryClient()
  const [challengeType, setChallengeType] = useState<AuthenticationFactor | null>(
    () => getDefaultChallengeType(factors),
  )
  const [challenge, setChallenge] = useState<Models.MfaChallenge | null>(null)
  const [code, setCode] = useState('')
  const [disabled, setDisabled] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [isChallengeReady, setIsChallengeReady] = useState(() => {
    const defaultType = getDefaultChallengeType(factors)
    return (
      defaultType === AuthenticationFactor.Totp ||
      defaultType === AuthenticationFactor.Recoverycode ||
      defaultType === null
    )
  })

  const codeRef = useRef(code)
  const challengeRef = useRef(challenge)
  const challengeTypeRef = useRef(challengeType)
  const challengeRequestIdRef = useRef(0)

  const setCodeValue = (value: string) => {
    codeRef.current = value
    setCode(value)
  }

  const setChallengeValue = (value: Models.MfaChallenge | null) => {
    challengeRef.current = value
    setChallenge(value)
  }

  const setChallengeTypeValue = (value: AuthenticationFactor | null) => {
    challengeTypeRef.current = value
    setChallengeType(value)
  }

  const createChallenge = async (factor: AuthenticationFactor) => {
    const requestId = ++challengeRequestIdRef.current
    setDisabled(true)
    setChallengeTypeValue(factor)
    setCodeValue('')
    setError(null)
    setChallengeValue(null)

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
      setChallengeValue(newChallenge)
      setIsChallengeReady(true)
    } catch (error: unknown) {
      if (requestId !== challengeRequestIdRef.current) return
      const message = getErrorMessage(error, t('Failed to create challenge'))
      setError(message)
      toast.error(message)
    } finally {
      if (requestId === challengeRequestIdRef.current) {
        setDisabled(false)
      }
    }
  }

  useEffect(() => {
    const defaultType = getDefaultChallengeType(factors)
    if (
      defaultType === AuthenticationFactor.Email ||
      defaultType === AuthenticationFactor.Phone
    ) {
      void createChallenge(defaultType)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    if (!challengeType) return

    const focusInput = () => {
      if (challengeType === AuthenticationFactor.Recoverycode) {
        const recoveryInput = document.getElementById(
          'mfa-code',
        ) as HTMLInputElement | null
        recoveryInput?.focus()
        return
      }

      const otpContainer = document.querySelector(
        '[data-slot="input-otp"]',
      ) as HTMLElement | null
      if (!otpContainer) return

      const input = otpContainer.querySelector('input') as HTMLInputElement | null
      if (input) {
        input.focus()
      } else {
        otpContainer.click()
        otpContainer.focus()
      }
    }

    requestAnimationFrame(() => {
      requestAnimationFrame(focusInput)
    })
  }, [challengeType])

  const verifyMutation = useMutation({
    mutationFn: async (otp: string) => {
      const activeChallengeType = challengeTypeRef.current
      if (!activeChallengeType) {
        throw new Error('Please select an authentication factor')
      }

      await verifyMFAChallenge(
        challengeRef.current,
        otp,
        activeChallengeType,
        factors,
      )
    },
    onSuccess: async () => {
      setError(null)

      try {
        const account = await refreshConsoleAccountAfterAuth(queryClient)

        if (requiresConsoleEmailVerification(account)) {
          navigate({
            to: '/verify-email',
            search: redirect ? { redirect } : undefined,
          })
          return
        }

        await prefetchPostAuthDestination(queryClient, account, redirect)
        await router.invalidate()

        const targetRedirect = resolvePostAuthRedirect(redirect)
        if (targetRedirect) {
          navigate(toRedirectNavigateOptions(targetRedirect))
          return
        }

        const orgId = await resolvePostAuthOrganizationId(account)
        navigate({
          to: '/organizations/$orgId',
          params: { orgId },
          replace: true,
        })
      } catch (error: unknown) {
        console.error('Post MFA navigation error:', error)
        toast.error(
          getErrorMessage(error, t('Verified but could not open the console')),
        )
      }
    },
    onError: (error: unknown) => {
      const errorMessage = getErrorMessage(error, t('Failed to verify code'))
      setError(errorMessage)
      setCodeValue('')
    },
  })

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (!challengeTypeRef.current) return

    const otp = readOtpFromForm(e.currentTarget, codeRef.current)
    setCodeValue(otp)

    const isValidCode =
      challengeTypeRef.current === AuthenticationFactor.Recoverycode
        ? otp.length > 0
        : otp.length === 6

    if (isValidCode) {
      verifyMutation.mutate(otp)
    }
  }

  const handleBack = async () => {
    try {
      await sdk.forConsole.account.deleteSession({ sessionId: 'current' })
    } catch {
      // Ignore errors - session might not exist
    }
    navigate({ to: '/sign-in' })
  }

  const getFactorDescription = () => {
    switch (challengeType) {
      case AuthenticationFactor.Totp:
        return t('Enter a 6-digit one-time code from your authenticator app.')
      case AuthenticationFactor.Email:
        return t(
          'A 6-digit verification code was sent to your email. Enter it below.',
        )
      case AuthenticationFactor.Phone:
        return t(
          'A 6-digit verification code was sent to your phone. Enter it below.',
        )
      case AuthenticationFactor.Recoverycode:
        return t(
          'Enter one of the recovery codes you received when enabling MFA.',
        )
      default:
        return ''
    }
  }

  const enabledMainFactors = [
    factors.totp && AuthenticationFactor.Totp,
    factors.email && AuthenticationFactor.Email,
    factors.phone && AuthenticationFactor.Phone,
  ].filter(Boolean) as AuthenticationFactor[]

  const isCodeValid =
    challengeType === AuthenticationFactor.Recoverycode
      ? code.length > 0
      : code.length === 6

  if (!hasAnyMfaFactor(factors)) {
    return (
      <Card className="overflow-hidden p-6 md:p-10">
        <div className="space-y-4 text-center">
          <h1 className="text-2xl font-semibold tracking-tight">
            {t('Two-factor authentication')}
          </h1>
          <p className="text-sm text-muted-foreground">
            {t(
              'No verification methods are available for this account. Contact support if you need help signing in.',
            )}
          </p>
          <Button variant="outline" onClick={() => void handleBack()}>
            {t('Back to sign in')}
          </Button>
        </div>
      </Card>
    )
  }

  return (
    <Card className="overflow-hidden py-0">
      <div className="grid md:grid-cols-2">
        <div className="p-6 md:p-10 min-h-[600px] flex flex-col justify-center">
          <div className="space-y-6">
            <div>
              <h1 className="text-2xl font-semibold tracking-tight">
                {t('Two-factor authentication')}
              </h1>
            </div>

            <form onSubmit={handleSubmit} className="space-y-6">
              {!isChallengeReady && (
                <p className="text-sm text-muted-foreground">
                  {t('Preparing verification...')}
                </p>
              )}

              {enabledMainFactors.length > 1 && (
                <div className="space-y-3">
                  <Label>{t('Authentication method')}</Label>
                  <div className="grid gap-2">
                    {factors.totp && (
                      <Button
                        type="button"
                        variant={
                          challengeType === AuthenticationFactor.Totp
                            ? 'default'
                            : 'outline'
                        }
                        className="justify-start"
                        onClick={() => void createChallenge(AuthenticationFactor.Totp)}
                        disabled={disabled}
                      >
                        <Smartphone className="me-1.5 h-4 w-4" />
                        {t('Authenticator app')}
                      </Button>
                    )}
                    {factors.email && (
                      <Button
                        type="button"
                        variant={
                          challengeType === AuthenticationFactor.Email
                            ? 'default'
                            : 'outline'
                        }
                        className="justify-start"
                        onClick={() => void createChallenge(AuthenticationFactor.Email)}
                        disabled={disabled}
                      >
                        <Mail className="me-1.5 h-4 w-4" />
                        {t('Email')}
                      </Button>
                    )}
                    {factors.phone && (
                      <Button
                        type="button"
                        variant={
                          challengeType === AuthenticationFactor.Phone
                            ? 'default'
                            : 'outline'
                        }
                        className="justify-start"
                        onClick={() => void createChallenge(AuthenticationFactor.Phone)}
                        disabled={disabled}
                      >
                        <Smartphone className="me-1.5 h-4 w-4" />
                        {t('Phone')}
                      </Button>
                    )}
                  </div>
                </div>
              )}

              {challengeType && (
                <div className="space-y-3">
                  <Label htmlFor="mfa-code">
                    {challengeType === AuthenticationFactor.Recoverycode
                      ? t('Recovery code')
                      : t('Verification code')}
                  </Label>
                  <p className="text-sm text-muted-foreground">
                    {getFactorDescription()}
                  </p>
                  {challengeType === AuthenticationFactor.Recoverycode ? (
                    <Input
                      id="mfa-code"
                      type="text"
                      value={code}
                      onChange={(e) => {
                        setCodeValue(e.target.value)
                        setError(null)
                      }}
                      placeholder={t('Enter recovery code')}
                      disabled={disabled || verifyMutation.isPending}
                      autoFocus
                      className="font-mono"
                    />
                  ) : (
                    <div className="w-full">
                      <InputOTP
                        maxLength={6}
                        value={code}
                        onChange={(value) => {
                          setCodeValue(value)
                          setError(null)
                        }}
                        disabled={
                          disabled ||
                          verifyMutation.isPending ||
                          !isChallengeReady
                        }
                        containerClassName="w-full justify-center"
                      >
                        <InputOTPGroup className="flex-1">
                          <InputOTPSlot
                            index={0}
                            className="h-16 w-full text-2xl"
                          />
                          <InputOTPSlot
                            index={1}
                            className="h-16 w-full text-2xl"
                          />
                          <InputOTPSlot
                            index={2}
                            className="h-16 w-full text-2xl"
                          />
                        </InputOTPGroup>
                        <InputOTPSeparator />
                        <InputOTPGroup className="flex-1">
                          <InputOTPSlot
                            index={3}
                            className="h-16 w-full text-2xl"
                          />
                          <InputOTPSlot
                            index={4}
                            className="h-16 w-full text-2xl"
                          />
                          <InputOTPSlot
                            index={5}
                            className="h-16 w-full text-2xl"
                          />
                        </InputOTPGroup>
                      </InputOTP>
                    </div>
                  )}

                  {error && (
                    <div className="rounded-md bg-destructive/10 border border-destructive/20 p-3">
                      <p className="text-sm text-destructive">{error}</p>
                    </div>
                  )}
                </div>
              )}

              {factors.recoveryCode &&
                challengeType !== AuthenticationFactor.Recoverycode && (
                  <div className="text-center">
                    <button
                      type="button"
                      onClick={() => {
                        void createChallenge(AuthenticationFactor.Recoverycode)
                      }}
                      disabled={disabled || verifyMutation.isPending}
                      className="text-sm text-muted-foreground hover:text-foreground underline underline-offset-4 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                      {t('Use a recovery code instead')}
                    </button>
                  </div>
                )}

              <div className="flex flex-col gap-2">
                <Button
                  type="submit"
                  className="w-full"
                  disabled={
                    !challengeType ||
                    !isCodeValid ||
                    !isChallengeReady ||
                    disabled ||
                    verifyMutation.isPending
                  }
                >
                  {t('Verify')}
                </Button>
                <div className="text-center pt-4">
                  <button
                    type="button"
                    onClick={() => void handleBack()}
                    disabled={disabled || verifyMutation.isPending}
                    className="text-sm text-muted-foreground hover:text-foreground underline underline-offset-4 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <ArrowLeft className="me-1.5 h-3.5 w-3.5 inline" />
                    {t('Back to sign in')}
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
        <div className="hidden bg-background md:block min-h-[600px]">
          <img
            alt="Image"
            className="h-full w-full object-cover"
            height="600"
            src="/cover.avif"
            width="600"
          />
        </div>
      </div>
    </Card>
  )
}
