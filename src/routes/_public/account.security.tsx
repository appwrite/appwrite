import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  accountIdentitiesQueryOptions,
  mfaFactorsQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/account/security')({
  head: () => ({ meta: [{ title: pageTitle('Security', 'Account') }] }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    const { queryClient } = context
    const features = getActiveProfileFeatures()
    const fetches = [
      ...(features.accountIdentities
        ? [queryClient.ensureQueryData(accountIdentitiesQueryOptions())]
        : []),
      ...(features.accountMfa
        ? [queryClient.ensureQueryData(mfaFactorsQueryOptions())]
        : []),
    ]

    if (fetches.length > 0) {
      await Promise.all(fetches)
    }
  },
  component: AccountSecurityPage,
})

function AccountSecurityPage() {
  return null
}
