import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { MFAChallenge } from '@/components/global/auth/MFAChallenge'
import {
  getMfaPreviewFactors,
  type MfaPreviewFactor,
} from '@/lib/debug-demos/mfa-preview-factors'
import { pageTitle } from '@/lib/utils/page-title'

const mfaPreviewSearchSchema = z.object({
  factor: z.enum(['totp', 'email', 'phone', 'recovery']).optional(),
})

export const Route = createFileRoute('/_public/debug/mfa-preview')({
  validateSearch: mfaPreviewSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('MFA preview') }] }),
  component: MfaPreviewPage,
})

function MfaPreviewPage() {
  const { factor: factorParam } = Route.useSearch()
  const factor: MfaPreviewFactor = factorParam ?? 'totp'

  return (
    <AuthFlowShell width="illustration">
      <MFAChallenge preview factors={getMfaPreviewFactors(factor)} />
    </AuthFlowShell>
  )
}
