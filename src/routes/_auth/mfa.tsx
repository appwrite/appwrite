import { createFileRoute, redirect, isRedirect } from '@tanstack/react-router'
import { z } from 'zod'
import { MFAChallenge } from '@/components/global/auth/MFAChallenge'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import {
  prefetchPostAuthDestination,
  requiresConsoleEmailVerification,
  resolvePostAuthRedirect,
  toRedirectNavigateOptions,
} from '@/lib/post-auth-navigation'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import {
  mfaFactorsQueryOptions,
  refreshConsoleAccountAfterAuth,
} from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

function isValidRelativeRedirect(url: string): boolean {
  try {
    return url.startsWith('/') && !url.includes('://')
  } catch {
    return false
  }
}

const searchSchema = z.object({
  redirect: z
    .string()
    .optional()
    .refine((val) => !val || isValidRelativeRedirect(val), {
      message: 'Redirect must be a relative URL',
    }),
})

export type MfaFactorsWithRecovery = Models.MfaFactors & { recoveryCode: boolean }

export const Route = createFileRoute('/_auth/mfa')({
  validateSearch: searchSchema,
  loader: async ({ context, location }) => {
    if (typeof window === 'undefined') return undefined

    const { queryClient } = context
    const redirectSearch =
      typeof location.search === 'object' &&
      location.search !== null &&
      'redirect' in location.search &&
      typeof location.search.redirect === 'string'
        ? location.search.redirect
        : undefined

    try {
      await sdk.forConsole.account.get()

      const account = await refreshConsoleAccountAfterAuth(queryClient)

      if (requiresConsoleEmailVerification(account)) {
        throw redirect({
          to: '/verify-email',
          search: redirectSearch ? { redirect: redirectSearch } : undefined,
          replace: true,
        })
      }

      await prefetchPostAuthDestination(queryClient, account, redirectSearch)

      const targetRedirect = resolvePostAuthRedirect(redirectSearch)
      if (targetRedirect) {
        throw redirect({
          ...toRedirectNavigateOptions(targetRedirect),
          replace: true,
        })
      }

      const orgId = await resolvePostAuthOrganizationId(account)
      throw redirect({
        to: '/organizations/$orgId',
        params: { orgId },
        replace: true,
      })
    } catch (error: unknown) {
      if (isRedirect(error)) throw error

      if (!(error instanceof AppwriteException)) {
        throw redirect({ to: '/sign-in', replace: true })
      }

      if (error.type === 'user_more_factors_required') {
        const availableFactors = await queryClient.ensureQueryData(
          mfaFactorsQueryOptions(),
        )
        return {
          factors: {
            ...availableFactors,
            recoveryCode: true,
          } satisfies MfaFactorsWithRecovery,
        }
      }

      if (error.code === 401) {
        throw redirect({
          to: '/sign-in',
          search:
            redirectSearch && isValidRelativeRedirect(redirectSearch)
              ? { redirect: redirectSearch }
              : undefined,
          replace: true,
        })
      }

      throw redirect({ to: '/sign-in', replace: true })
    }
  },
  component: MFAPage,
  head: () => ({ meta: [{ title: pageTitle('Two-factor authentication') }] }),
})

function MFAPage() {
  const search = Route.useSearch({ from: '/_auth/mfa' })
  const loaderData = Route.useLoaderData()

  if (!loaderData?.factors) {
    return null
  }

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <MFAChallenge factors={loaderData.factors} redirect={search.redirect} />
      </div>
    </div>
  )
}
