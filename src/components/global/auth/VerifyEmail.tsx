'use client'

import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Link } from '@tanstack/react-router'

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
  return (
    <Card className="overflow-hidden py-0">
      <div className="grid md:grid-cols-2">
        <div className="p-6 md:p-10 min-h-[600px] flex flex-col justify-center">
          <div className="space-y-6">
            <div className="space-y-2">
              <h1 className="text-2xl font-semibold tracking-tight">
                {status === 'confirming'
                  ? 'Verifying your email'
                  : 'Verify your email'}
              </h1>
              <p className="text-sm text-muted-foreground">
                {status === 'confirming'
                  ? 'Please wait while we confirm your email address.'
                  : "We've sent a verification link to your email address. Click the link to verify your account and access the console."}
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
                      {isResendLoading ? 'Sending…' : 'Resend verification email'}
                    </Button>
                  )}
                  <Link to="/" search={undefined}>
                    <Button variant={onResend ? 'ghost' : 'default'} className="w-full">
                      Go to console
                    </Button>
                  </Link>
                </div>
                <p className="text-center text-sm text-muted-foreground">
                  Already verified?{' '}
                  <Link
                    to="/sign-in"
                    search={redirect ? { redirect } : undefined}
                    className="text-primary hover:underline"
                  >
                    Sign in
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
            src="/cover.png"
            width="600"
          />
        </div>
      </div>
    </Card>
  )
}
