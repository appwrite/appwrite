import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { createFileRoute, useSearch } from '@tanstack/react-router'
import { z } from 'zod'
import { Reset } from '@/components/global/auth/Reset'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException } from '@appwrite.io/console'
import { Link } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  userId: z.string().min(1, 'User ID is required'),
  secret: z.string().min(1, 'Secret token is required'),
})

export const Route = createFileRoute('/_public/reset')({
  component: ResetPage,
  validateSearch: searchSchema,
  head: () => ({ meta: [{ title: pageTitle('Reset password') }] }),
})

function ResetPage() {
  const search = useSearch({ from: '/_public/reset' })
  const [isSuccess, setIsSuccess] = useState(false)

  const resetMutation = useMutation({
    mutationFn: async (data: { password: string }) => {
      try {
        await sdk.forConsole.account.updateRecovery({
          userId: search.userId,
          secret: search.secret,
          password: data.password,
        })
      } catch (error) {
        if (error instanceof AppwriteException) {
          throw new Error(error.message || 'Failed to reset password')
        }
        throw error
      }
    },
    onSuccess: () => {
      setIsSuccess(true)
      toast.success('Password reset successfully')
    },
    onError: (error: unknown) => {
      console.error('Reset error:', error)
      toast.error(error.message || 'Failed to reset password')
    },
  })

  // If missing required params, show error state
  if (!search.userId || !search.secret) {
    return (
      <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
        <div className="w-full max-w-sm md:max-w-4xl">
          <div className="rounded-lg border bg-card p-6 text-center">
            <h1 className="text-2xl font-semibold tracking-tight mb-2">
              Invalid reset link
            </h1>
            <p className="text-sm text-muted-foreground mb-4">
              This password reset link is invalid or has expired. Please request
              a new one.
            </p>
            <div className="flex gap-2 justify-center">
              <Link
                to="/recovery"
                className="text-primary hover:underline text-sm"
              >
                Request new reset link
              </Link>
            </div>
          </div>
          <div className="mt-6 flex justify-center">
            <AppwriteLogo className="h-6 w-auto" />
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <Reset
          onSubmit={(data) => resetMutation.mutate(data)}
          isLoading={resetMutation.isPending}
          isSuccess={isSuccess}
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
        <div className="mt-6 flex justify-center">
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}
