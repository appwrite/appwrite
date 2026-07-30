import { useState } from 'react'
import { createFileRoute } from '@tanstack/react-router'
import { VerifyEmail } from '@/components/global/auth/VerifyEmail'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { Button } from '@/components/ui/button'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/debug/verify-email-preview')({
  head: () => ({ meta: [{ title: pageTitle('Verify email preview') }] }),
  component: VerifyEmailPreviewPage,
})

function VerifyEmailPreviewPage() {
  const [status, setStatus] = useState<'pending' | 'confirming'>('pending')
  const [isResendLoading, setIsResendLoading] = useState(false)

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="fixed inset-x-0 top-0 z-50 flex items-center justify-center gap-2 border-b border-border bg-background/95 px-4 py-2 backdrop-blur">
        <p className="mr-2 text-[12px] text-muted-foreground">
          Debug preview. Toggle verify-email states.
        </p>
        <Button
          type="button"
          size="sm"
          variant={status === 'pending' ? 'default' : 'outline'}
          className="h-8 text-[12px]"
          onClick={() => setStatus('pending')}
        >
          Pending
        </Button>
        <Button
          type="button"
          size="sm"
          variant={status === 'confirming' ? 'default' : 'outline'}
          className="h-8 text-[12px]"
          onClick={() => setStatus('confirming')}
        >
          Confirming
        </Button>
      </div>

      <div className="w-full max-w-sm md:max-w-4xl pt-12">
        <VerifyEmail
          preview
          status={status}
          isResendLoading={isResendLoading}
          onResend={() => {
            setIsResendLoading(true)
            window.setTimeout(() => setIsResendLoading(false), 1200)
          }}
        />
        <p className="mt-6 text-center text-xs text-muted-foreground">
          By continuing, you agree to our{' '}
          <MarketingSiteLink className="link-neutral" href="/terms">
            Terms of Service
          </MarketingSiteLink>{' '}
          and{' '}
          <MarketingSiteLink className="link-neutral" href="/privacy">
            Privacy Policy
          </MarketingSiteLink>
          .
        </p>
        <div className="mt-10 md:mt-16 flex justify-center">
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}
