import { useState } from 'react'
import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { toast } from 'sonner'
import { Recovery } from '@/components/global/auth/Recovery'
import { AuthFlowPreviewLayout } from '@/components/global/auth/debug/AuthFlowPreviewLayout'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  email: z.string().optional(),
})

export const Route = createFileRoute('/_public/debug/recovery-preview')({
  validateSearch: searchSchema,
  head: () => ({ meta: [{ title: pageTitle('Password recovery preview') }] }),
  component: RecoveryPreviewPage,
})

function RecoveryPreviewPage() {
  const { email } = Route.useSearch({ from: '/_public/debug/recovery-preview' })
  const [isSuccess, setIsSuccess] = useState(false)

  return (
    <AuthFlowPreviewLayout>
      <Recovery
        preview
        onSubmit={() => {
          setIsSuccess(true)
          toast.message('Preview only', {
            description: 'Recovery email is not sent from this preview route.',
          })
        }}
        isLoading={false}
        isSuccess={isSuccess}
        initialEmail={email}
      />
    </AuthFlowPreviewLayout>
  )
}
