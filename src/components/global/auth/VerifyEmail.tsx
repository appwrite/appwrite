'use client'

import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Link } from '@tanstack/react-router'
import {
  AuthFlowDescription,
  AuthFlowIllustrationCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowIllustrationColumn } from '@/components/global/auth/AuthFlowShell'
import { performConsoleSignOut } from '@/lib/react-query/hooks/auth'
import { useT } from '@/lib/i18n/translate'

interface VerifyEmailProps {
  onResend?: () => void
  isResendLoading?: boolean
  /** Optional redirect path to preserve when linking to sign-in (e.g. / or /projects/xyz) */
  redirect?: string
  /** When 'confirming', only show the verifying message (same card layout as sign-in) */
  status?: 'pending' | 'confirming'
  /** Debug preview: render actions without navigating (avoids /sign-out preload side effects). */
  preview?: boolean
}

export function VerifyEmail({
  onResend,
  isResendLoading,
  redirect,
  status = 'pending',
  preview = false,
}: VerifyEmailProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [isSigningOutToSignIn, setIsSigningOutToSignIn] = useState(false)

  // Session is still active on this page, so /sign-in would bounce back here.
  // Sign out first, then open sign-in (optionally preserving a pending redirect).
  const handleSignIn = () => {
    if (preview || isSigningOutToSignIn) return
    setIsSigningOutToSignIn(true)
    void performConsoleSignOut(queryClient, {
      redirect: redirect || undefined,
    })
  }

  return (
    <AuthFlowIllustrationCard illustration={<AuthFlowIllustrationColumn />}>
      <div className="space-y-6">
        <div className="space-y-2">
          <AuthFlowTitle>
            {status === 'confirming'
              ? t('Verifying your email')
              : t('Verify your email')}
          </AuthFlowTitle>
          <AuthFlowDescription>
            {status === 'confirming'
              ? t('Please wait while we confirm your email address.')
              : t(
                  "We've sent a verification link to your email address. Click the link to verify your account and access the console.",
                )}
          </AuthFlowDescription>
        </div>
        {status === 'pending' && (
          <>
            <div className="space-y-4">
              {onResend && (
                <Button
                  variant="brandCta"
                  className="w-full"
                  onClick={onResend}
                  disabled={isResendLoading || isSigningOutToSignIn}
                >
                  {t('Resend verification email')}
                </Button>
              )}
              {preview ? (
                <Button
                  type="button"
                  variant={onResend ? 'ghost' : 'brandCta'}
                  className="w-full"
                >
                  {t('Sign out')}
                </Button>
              ) : (
                <Link to="/sign-out" className="block" preload={false}>
                  <Button
                    variant={onResend ? 'ghost' : 'brandCta'}
                    className="w-full"
                    disabled={isSigningOutToSignIn}
                  >
                    {t('Sign out')}
                  </Button>
                </Link>
              )}
            </div>
            <AuthFlowDescription className="text-center">
              {t('Already verified?')}{' '}
              {preview ? (
                <span className="link-neutral">{t('Sign in')}</span>
              ) : (
                <button
                  type="button"
                  className="link-neutral disabled:opacity-50"
                  onClick={handleSignIn}
                  disabled={isSigningOutToSignIn}
                >
                  {t('Sign in')}
                </button>
              )}
            </AuthFlowDescription>
          </>
        )}
      </div>
    </AuthFlowIllustrationCard>
  )
}
