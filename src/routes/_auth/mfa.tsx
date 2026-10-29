import { createFileRoute, redirect, isRedirect } from '@tanstack/react-router'
import { z } from 'zod'
import { MFAChallenge } from '@/components/global/auth/MFAChallenge'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import {
  isValidRelativeRedirect,
  prefetchPostAuthDestination,
  requiresConsoleEmailVerification,
  resolvePostAuthHomeTarget,
} from '@/lib/post-auth-navigation'
import { PartnerOrganizationRequiredError } from '@/lib/ensure-personal-org'
import {
  PARTNER_AUTH_ENTRY_PATH,
  getAuthEmailSearch,
  getAuthSignInPath,
  resolveAuthAudience,
} from '@/lib/auth-audience'
import {
  mfaFactorsQueryOptions,
  refreshConsoleAccountAfterAuth,
} from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

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

      try {
        throw redirect(
          await resolvePostAuthHomeTarget(account, queryClient, {
            redirect: redirectSearch,
          }),
        )
      } catch (homeError) {
        if (isRedirect(homeError)) throw homeError
        if (homeError instanceof PartnerOrganizationRequiredError) {
          throw redirect({ to: PARTNER_AUTH_ENTRY_PATH, replace: true })
        }
        throw homeError
      }
    } catch (error: unknown) {
      if (isRedirect(error)) throw error

      if (!(error instanceof AppwriteException)) {
        const audience = resolveAuthAudience()
        throw redirect({
          to: getAuthSignInPath(audience),
          search: getAuthEmailSearch(audience),
          replace: true,
        })
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
        const audience = resolveAuthAudience()
        throw redirect({
          to: getAuthSignInPath(audience),
          search: getAuthEmailSearch(
            audience,
            redirectSearch && isValidRelativeRedirect(redirectSearch)
              ? redirectSearch
              : undefined,
          ),
          replace: true,
        })
      }

      const audience = resolveAuthAudience()
      throw redirect({
        to: getAuthSignInPath(audience),
        search: getAuthEmailSearch(audience),
        replace: true,
      })
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
    <div className="bg-background relative h-full overflow-y-auto">
      <div className="flex min-h-full flex-col items-center p-6 md:p-10">
        <div className="my-auto w-full max-w-sm md:max-w-4xl">
          <MFAChallenge factors={loaderData.factors} redirect={search.redirect} />
        </div>
      </div>
    </div>
  )
}
