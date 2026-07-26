'use client'

import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Link } from '@tanstack/react-router'
import { useT } from '@/lib/i18n/translate'

interface VerifyEmailProps {
  onResend?: () => void
  isResendLoading?: boolean
  /** Optional redirect path to preserve when linking to sign-in (e.g. / or /projects/xyz) */
  redirect?: string
  /** When 'confirming', only show the verifying message (same card layout as sign-in) */
  status?: 'pending' | 'confirming'
}

export function VerifyEmail({
  onResend,
  isResendLoading,
  redirect,
  status = 'pending',
}: VerifyEmailProps) {
  const t = useT()
  return (
    <Card className="overflow-hidden py-0">
      <div className="grid md:grid-cols-2">
        <div className="p-6 md:p-10 min-h-[600px] flex flex-col justify-center">
          <div className="space-y-6">
            <div className="space-y-2">
              <h1 className="text-2xl font-semibold tracking-tight">
                {status === 'confirming'
                  ? t('Verifying your email')
                  : t('Verify your email')}
              </h1>
              <p className="text-sm text-muted-foreground">
                {status === 'confirming'
                  ? t('Please wait while we confirm your email address.')
                  : t(
                      "We've sent a verification link to your email address. Click the link to verify your account and access the console.",
                    )}
              </p>
            </div>
            {status === 'pending' && (
              <>
                <div className="space-y-4">
                  {onResend && (
                    <Button
                      variant="outline"
                      className="w-full"
                      onClick={onResend}
                      disabled={isResendLoading}
                    >
                      {isResendLoading
                        ? t('Sending…')
                        : t('Resend verification email')}
                    </Button>
                  )}
                  <Link to="/sign-out" className="block">
                    <Button
                      variant={onResend ? 'ghost' : 'default'}
                      className="w-full"
                    >
                      {t('Sign out')}
                    </Button>
                  </Link>
                </div>
                <p className="text-center text-sm text-muted-foreground">
                  {t('Already verified?')}{' '}
                  <Link
                    to="/sign-in"
                    search={redirect ? { redirect } : undefined}
                    className="link-neutral"
                  >
                    {t('Sign in')}
                  </Link>
                </p>
              </>
            )}
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
