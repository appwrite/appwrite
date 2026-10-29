import { useState } from 'react'
import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { AuthFlowAccountSwitcher } from '@/components/global/auth/AuthFlowAccountSwitcher'
import { VerifyEmail } from '@/components/global/auth/VerifyEmail'
import { AuthFlowPreviewLayout } from '@/components/global/auth/debug/AuthFlowPreviewLayout'
import { pageTitle } from '@/lib/utils/page-title'

const verifyEmailPreviewSearchSchema = z.object({
  status: z.enum(['pending', 'confirming']).optional(),
})

export const Route = createFileRoute('/_public/debug/verify-email-preview')({
  validateSearch: verifyEmailPreviewSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('Verify email preview') }] }),
  component: VerifyEmailPreviewPage,
})

function VerifyEmailPreviewPage() {
  const { status: statusParam } = Route.useSearch()
  const status = statusParam ?? 'pending'
  const [isResendLoading, setIsResendLoading] = useState(false)

  return (
    <AuthFlowPreviewLayout
      accountSwitcher={<AuthFlowAccountSwitcher preview />}
    >
      <VerifyEmail
        preview
        status={status}
        isResendLoading={isResendLoading}
        onResend={() => {
          setIsResendLoading(true)
          window.setTimeout(() => setIsResendLoading(false), 1200)
        }}
      />
    </AuthFlowPreviewLayout>
  )
}
