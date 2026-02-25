import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { MFAChallenge } from '@/components/global/auth/MFAChallenge'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException } from '@appwrite.io/console'
import { fetchMFAFactors } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

// Helper function to validate that a redirect URL is relative
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

export const Route = createFileRoute('/_auth/mfa')({
  component: MFAPage,
  validateSearch: searchSchema,
  head: () => ({ meta: [{ title: pageTitle('Two-factor authentication') }] }),
  loader: async ({ location }) => {
    // Check if MFA is required by trying to get account
    // If we get 'user_more_factors_required' error, MFA is required
    let mfaRequired = false

    try {
      await sdk.forConsole.account.get()
      // If we can get account successfully, MFA is not required - redirect to home
      throw redirect({
        to: '/',
        throw: true,
      })
    } catch (error: unknown) {
      // Check if this is a redirect (from the success case above)
      const status =
        typeof error === 'object' &&
        error !== null &&
        'status' in error &&
        typeof (error as { status: number }).status === 'number'
          ? (error as { status: number }).status
          : undefined
      if (status === 302 || status === 303) {
        throw error
      }

      if (error instanceof AppwriteException) {
        // Check for MFA requirement error - this is the key check
        if (error.type === 'user_more_factors_required') {
          mfaRequired = true
        } else if (error.code === 401) {
          // Not authenticated at all - redirect to sign-in
          const redirectUrl =
            location.pathname + (location.search ? `?${location.search}` : '')
          throw redirect({
            to: '/sign-in',
            search:
              redirectUrl !== '/mfa' ? { redirect: redirectUrl } : undefined,
            throw: true,
          })
        } else {
          // Other error - redirect to sign-in
          throw redirect({
            to: '/sign-in',
            throw: true,
          })
        }
      } else {
        // Unknown error - redirect to sign-in
        throw redirect({
          to: '/sign-in',
          throw: true,
        })
      }
    }

    // Double-check: if MFA is not required, redirect to home
    if (!mfaRequired) {
      throw redirect({
        to: '/',
        throw: true,
      })
    }

    // Fetch available MFA factors
    const factors = await fetchMFAFactors()

    return {
      factors: {
        ...factors,
        recoveryCode: true, // Recovery codes are always available if MFA is enabled
      },
    }
  },
})

function MFAPage() {
  const { factors } = Route.useLoaderData()
  const search = Route.useSearch({ from: '/_auth/mfa' })

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <MFAChallenge factors={factors} redirect={search.redirect} />
      </div>
    </div>
  )
}
