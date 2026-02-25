import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { createFileRoute, useSearch } from '@tanstack/react-router'
import { z } from 'zod'
import { Recovery } from '@/components/global/auth/Recovery'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException } from '@appwrite.io/console'
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
      toast.success('Recovery email sent')
    },
    onError: (error: unknown) => {
      console.error('Recovery error:', error)
      toast.error(error.message || 'Failed to send recovery email')
    },
  })

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <Recovery
          onSubmit={(data) => recoveryMutation.mutate(data)}
          isLoading={recoveryMutation.isPending}
          isSuccess={isSuccess}
          initialEmail={search.email}
        />
        <p className="mt-6 text-center text-xs text-muted-foreground">
          By clicking continue, you agree to our{' '}
          <a
            href="#"
            className="underline underline-offset-4 hover:text-primary"
          >
            Terms of Service
          </a>{' '}
          and{' '}
          <a
            href="#"
            className="underline underline-offset-4 hover:text-primary"
          >
            Privacy Policy
          </a>
          .
        </p>
        <div className="mt-10 md:mt-16 flex justify-center">
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}
