'use client'

import { useState, useEffect } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
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

interface MFAChallengeProps {
  factors: Models.MfaFactors & { recoveryCode?: boolean }
  redirect?: string
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
  // Validate factor
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

  // For Email/Phone, challenge must exist (created when factor is selected)
  // For TOTP/Recovery, create challenge if it doesn't exist
  if (!activeChallenge) {
    if (
      challengeType === AuthenticationFactor.Email ||
      challengeType === AuthenticationFactor.Phone
    ) {
      throw new Error('Challenge must be created for Email/Phone factors')
    }

    // Create challenge for TOTP/Recovery (though guide says not required,
    // SDK may still need it for verification)
    try {
      activeChallenge = await sdk.forConsole.account.createMFAChallenge({
        factor: challengeType,
      })
    } catch (error: any) {
      // If challenge creation fails for TOTP/Recovery, try verification without challenge
      // Some SDKs might handle this differently
      throw new Error(
        error.message || 'Failed to create challenge. Please try again.',
      )
    }
  }

  // Verify challenge
  await sdk.forConsole.account.updateMFAChallenge({
    challengeId: activeChallenge.$id,
    otp: code,
  })
}

export function MFAChallenge({ factors, redirect }: MFAChallengeProps) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [challengeType, setChallengeType] =
    useState<AuthenticationFactor | null>(null)
  const [challenge, setChallenge] = useState<Models.MfaChallenge | null>(null)
  const [code, setCode] = useState('')
  const [disabled, setDisabled] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Auto-select first available factor (priority: TOTP > Email > Phone)
  useEffect(() => {
    if (challengeType) return

    if (factors.totp) {
      setChallengeType(AuthenticationFactor.Totp)
      setChallenge(null) // TOTP doesn't need challenge creation
    } else if (factors.email) {
      setChallengeType(AuthenticationFactor.Email)
      createChallenge(AuthenticationFactor.Email)
    } else if (factors.phone) {
      setChallengeType(AuthenticationFactor.Phone)
      createChallenge(AuthenticationFactor.Phone)
    }
  }, [factors, challengeType])

  // Focus the input when challengeType changes
  useEffect(() => {
    if (!challengeType) return

    // Use requestAnimationFrame to ensure the input is rendered
    const focusInput = () => {
      if (challengeType === AuthenticationFactor.Recoverycode) {
        // Focus recovery code input
        const recoveryInput = document.getElementById(
          'mfa-code',
        ) as HTMLInputElement | null
        recoveryInput?.focus()
      } else {
        // For OTP input, find the container and focus it
        // The input-otp library uses a hidden input that we can focus
        const otpContainer = document.querySelector(
          '[data-slot="input-otp"]',
        ) as HTMLElement | null
        if (otpContainer) {
          // Find the actual input element (input-otp uses a hidden input)
          const input = otpContainer.querySelector(
            'input',
          ) as HTMLInputElement | null
          if (input) {
            input.focus()
          } else {
            // Fallback: click the container to activate the first slot
            otpContainer.click()
            // Also try to focus the container itself
            otpContainer.focus()
          }
        }
      }
    }

    // Double requestAnimationFrame to ensure DOM is ready
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        focusInput()
      })
    })
  }, [challengeType])

  const createChallenge = async (factor: AuthenticationFactor) => {
    setDisabled(true)
    setChallengeType(factor)
    setCode('')
    setError(null)

    try {
      if (
        factor !== AuthenticationFactor.Totp &&
        factor !== AuthenticationFactor.Recoverycode
      ) {
        const newChallenge = await sdk.forConsole.account.createMFAChallenge({
          factor,
        })
        setChallenge(newChallenge)
      } else {
        setChallenge(null)
      }
    } catch (error: any) {
      setError(error.message || 'Failed to create challenge')
      toast.error(error.message || 'Failed to create challenge')
    } finally {
      setDisabled(false)
    }
  }

  const verifyMutation = useMutation({
    mutationFn: async () => {
      if (!challengeType) {
        throw new Error('Please select an authentication factor')
      }

      // For TOTP and Recovery codes, create challenge if needed
      let activeChallenge = challenge
      if (
        !activeChallenge &&
        (challengeType === AuthenticationFactor.Totp ||
          challengeType === AuthenticationFactor.Recoverycode)
      ) {
        try {
          activeChallenge = await sdk.forConsole.account.createMFAChallenge({
            factor: challengeType,
          })
          setChallenge(activeChallenge)
        } catch (error: any) {
          // If challenge creation fails, try to verify anyway (SDK might handle it)
          console.warn(
            'Failed to create challenge for TOTP/Recovery, attempting verification:',
            error,
          )
        }
      }

      await verifyMFAChallenge(activeChallenge, code, challengeType, factors)
    },
    onSuccess: async () => {
      // After MFA verification, the session is now fully authenticated
      // Clear any error state and prepare for navigation
      setError(null)

      // Determine target URL
      const targetUrl =
        redirect && redirect.startsWith('/') && !redirect.includes('://')
          ? redirect
          : '/'

      // Clear the account query cache to force a fresh fetch on next page
      queryClient.removeQueries({ queryKey: ['account', 'console'] })

      // Use window.location for a full page reload
      // This ensures all components and loaders start fresh with the authenticated state
      window.location.href = targetUrl
    },
    onError: (error: any) => {
      const errorMessage = error.message || 'Failed to verify code'
      setError(errorMessage)
      setCode('')
    },
  })

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!challengeType || !code) return

    const isValidCode =
      challengeType === AuthenticationFactor.Recoverycode
        ? code.length > 0
        : code.length === 6

    if (isValidCode) {
      verifyMutation.mutate()
    }
  }

  const handleBack = async () => {
    try {
      // Delete current session to cancel MFA flow
      await sdk.forConsole.account.deleteSession({ sessionId: 'current' })
    } catch (error) {
      // Ignore errors - session might not exist
    }
    navigate({ to: '/sign-in' })
  }

  const getFactorDescription = () => {
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

  const enabledMainFactors = [
    factors.totp && AuthenticationFactor.Totp,
    factors.email && AuthenticationFactor.Email,
    factors.phone && AuthenticationFactor.Phone,
  ].filter(Boolean) as AuthenticationFactor[]

  const isCodeValid =
    challengeType === AuthenticationFactor.Recoverycode
      ? code.length > 0
      : code.length === 6

  return (
    <Card className="overflow-hidden py-0">
      <div className="grid md:grid-cols-2">
        <div className="p-6 md:p-10 min-h-[600px] flex flex-col justify-center">
          <div className="space-y-6">
            <div>
              <h1 className="text-2xl font-semibold tracking-tight">
                Two-factor authentication
              </h1>
            </div>

            <form onSubmit={handleSubmit} className="space-y-6">
              {/* Factor Selection */}
              {enabledMainFactors.length > 1 && (
                <div className="space-y-3">
                  <Label>Authentication method</Label>
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
                        onClick={() => {
                          setChallengeType(AuthenticationFactor.Totp)
                          setChallenge(null)
                          setCode('')
                          setError(null)
                        }}
                        disabled={disabled}
                      >
                        <Smartphone className="mr-1.5 h-4 w-4" />
                        Authenticator app
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
                        onClick={() =>
                          createChallenge(AuthenticationFactor.Email)
                        }
                        disabled={disabled}
                      >
                        <Mail className="mr-1.5 h-4 w-4" />
                        Email
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
                        onClick={() =>
                          createChallenge(AuthenticationFactor.Phone)
                        }
                        disabled={disabled}
                      >
                        <Smartphone className="mr-1.5 h-4 w-4" />
                        Phone
                      </Button>
                    )}
                  </div>
                </div>
              )}

              {/* Code Input */}
              {challengeType && (
                <div className="space-y-3">
                  <Label htmlFor="mfa-code">
                    {challengeType === AuthenticationFactor.Recoverycode
                      ? 'Recovery code'
                      : 'Verification code'}
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
                        setCode(e.target.value)
                        setError(null)
                      }}
                      placeholder="Enter recovery code"
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
                          setCode(value)
                          setError(null)
                        }}
                        disabled={disabled || verifyMutation.isPending}
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

                  {/* Error Message */}
                  {error && (
                    <div className="rounded-md bg-destructive/10 border border-destructive/20 p-3">
                      <p className="text-sm text-destructive">{error}</p>
                    </div>
                  )}
                </div>
              )}

              {/* Recovery Code Link */}
              {challengeType !== AuthenticationFactor.Recoverycode && (
                <div className="text-center">
                  <button
                    type="button"
                    onClick={() => {
                      setChallengeType(AuthenticationFactor.Recoverycode)
                      setChallenge(null)
                      setCode('')
                      setError(null)
                    }}
                    disabled={disabled || verifyMutation.isPending}
                    className="text-sm text-muted-foreground hover:text-foreground underline underline-offset-4 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Use a recovery code instead
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
                    disabled ||
                    verifyMutation.isPending
                  }
                >
                  Verify
                </Button>
                <div className="text-center pt-4">
                  <button
                    type="button"
                    onClick={handleBack}
                    disabled={disabled || verifyMutation.isPending}
                    className="text-sm text-muted-foreground hover:text-foreground underline underline-offset-4 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <ArrowLeft className="mr-1.5 h-3.5 w-3.5 inline" />
                    Back to sign in
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
            src="/cover.png"
            width="600"
          />
        </div>
      </div>
    </Card>
  )
}
