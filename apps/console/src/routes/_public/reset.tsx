import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  createFileRoute,
  useNavigate,
  useRouter,
  useSearch,
} from '@tanstack/react-router'
import { z } from 'zod'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { Reset } from '@/components/global/auth/Reset'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException } from '@appwrite.io/console'
import { Link } from '@tanstack/react-router'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import {
  isConsoleMfaRequiredError,
  purgeConsoleAccountCaches,
  navigateToConsoleMfaAfterSession,
  refreshConsoleAccountAfterAuth,
} from '@/lib/react-query/hooks/auth'
import { prefetchPostAuthDestination } from '@/lib/post-auth-navigation'

const searchSchema = z.object({
  userId: z.string().optional(),
  secret: z.string().optional(),
})

export const Route = createFileRoute('/_public/reset')({
  component: ResetPage,
  validateSearch: searchSchema,
  head: () => ({ meta: [{ title: pageTitle('Reset password') }] }),
})

function ResetPage() {
  const t = useT()
  const search = useSearch({ from: '/_public/reset' })
  const navigate = useNavigate()
  const router = useRouter()
  const queryClient = useQueryClient()
  const [isSuccess, setIsSuccess] = useState(false)
  const [isOpeningMfa, setIsOpeningMfa] = useState(false)

  const resetMutation = useMutation({
    mutationFn: async (data: { password: string }) => {
      try {
        await sdk.forConsole.account.updateRecovery({
          userId: search.userId,
          secret: search.secret,
          password: data.password,
        })

        try {
          await sdk.forConsole.account.get()
        } catch (error: unknown) {
          if (
            error instanceof AppwriteException &&
            error.type === 'user_more_factors_required'
          ) {
            throw { ...error, isMfaRequired: true }
          }
          // Recovery can succeed without creating a session.
        }
      } catch (error: unknown) {
        if (
          typeof error === 'object' &&
          error !== null &&
          'isMfaRequired' in error &&
          (error as { isMfaRequired?: boolean }).isMfaRequired === true
        ) {
          throw error
        }
        if (error instanceof AppwriteException) {
          throw new Error(error.message || 'Failed to reset password')
        }
        throw error
      }
    },
    onSuccess: async () => {
      try {
        const account = await refreshConsoleAccountAfterAuth(queryClient)
        await prefetchPostAuthDestination(queryClient, account)
        await router.invalidate()
        toast.success(t('Password reset successfully'))
        const orgId = await resolvePostAuthOrganizationId(account)
        navigate({
          to: '/organizations/$orgId',
          params: { orgId },
          replace: true,
        })
      } catch {
        purgeConsoleAccountCaches(queryClient)
        toast.success(t('Password reset successfully'))
        setIsSuccess(true)
      }
    },
    onError: async (error: unknown) => {
      const isMfaRequired =
        typeof error === 'object' &&
        error !== null &&
        'isMfaRequired' in error &&
        (error as { isMfaRequired?: boolean }).isMfaRequired === true
      if (isMfaRequired || isConsoleMfaRequiredError(error)) {
        setIsOpeningMfa(true)
        try {
          await navigateToConsoleMfaAfterSession(queryClient, navigate)
        } catch (navigationError: unknown) {
          setIsOpeningMfa(false)
          toast.error(
            getErrorMessage(
              navigationError,
              t('Could not open MFA verification'),
            ),
          )
        }
        return
      }

      console.error('Reset error:', error)
      toast.error(getErrorMessage(error, t('Failed to reset password')))
    },
  })

  // If missing required params, show error state
  if (!search.userId || !search.secret) {
    return (
      <AuthFlowShell width="narrow" showLegal={false}>
        <div className="rounded-lg border bg-card p-6 text-center">
          <h1 className="mb-2 text-2xl font-semibold tracking-tight">
            {t('Invalid reset link')}
          </h1>
          <p className="mb-4 text-sm text-muted-foreground">
            {t(
              'This password reset link is invalid or has expired. Please request a new one.',
            )}
          </p>
          <Link to="/recovery" className="link-neutral text-sm">
            {t('Request new reset link')}
          </Link>
        </div>
      </AuthFlowShell>
    )
  }

  return (
    <AuthFlowShell width="illustration">
      <Reset
        onSubmit={(data) => resetMutation.mutate(data)}
        isLoading={resetMutation.isPending || isOpeningMfa}
        isSuccess={isSuccess}
      />
      {isSuccess ? (
        <p className="mt-4 text-center">
          <button
            type="button"
            className="link-neutral text-sm"
            onClick={() => {
              purgeConsoleAccountCaches(queryClient)
              navigate({ to: '/sign-in', replace: true })
            }}
          >
            {t('Continue to sign in')}
          </button>
        </p>
      ) : null}
    </AuthFlowShell>
  )
}
