import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { createFileRoute, useSearch } from '@tanstack/react-router'
import { z } from 'zod'
import { Recovery } from '@/components/global/auth/Recovery'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  email: z.string().optional(),
})

export const Route = createFileRoute('/_auth/recovery')({
  component: RecoveryPage,
  validateSearch: searchSchema,
  head: () => ({ meta: [{ title: pageTitle('Password recovery') }] }),
})

function RecoveryPage() {
  const t = useT()
  const search = useSearch({ from: '/_auth/recovery' })
  const [isSuccess, setIsSuccess] = useState(false)

  const recoveryMutation = useMutation({
    mutationFn: async (data: { email: string }) => {
      try {
        // Get the current origin to build the redirect URL
        const origin =
          typeof window !== 'undefined' ? window.location.origin : ''
        // The recovery URL should point to a reset password page
        // Appwrite will append userId and secret as query parameters
        const redirectUrl = `${origin}/reset`

        await sdk.forConsole.account.createRecovery({
          email: data.email,
          url: redirectUrl,
        })
      } catch (error) {
        if (error instanceof AppwriteException) {
          throw new Error(error.message || 'Failed to send recovery email')
        }
        throw error
      }
    },
    onSuccess: () => {
      setIsSuccess(true)
      toast.success(t('Recovery email sent'))
    },
    onError: (error: unknown) => {
      console.error('Recovery error:', error)
      toast.error(error.message || t('Failed to send recovery email'))
    },
  })

  return (
    <AuthFlowShell width="illustration">
      <Recovery
        onSubmit={(data) => recoveryMutation.mutate(data)}
        isLoading={recoveryMutation.isPending}
        isSuccess={isSuccess}
        initialEmail={search.email}
      />
    </AuthFlowShell>
  )
}
