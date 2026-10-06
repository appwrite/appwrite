import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  accountIdentitiesQueryOptions,
  mfaFactorsQueryOptions,
} from '@/lib/react-query/hooks'
import { AccountSecurity } from '@/components/pages/account/Security'

export const Route = createFileRoute('/_public/account/security')({
  head: () => ({ meta: [{ title: pageTitle('Security', 'Account') }] }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    const { queryClient } = context
    const features = getActiveProfileFeatures()

    const [identities, mfaFactors] = await Promise.all([
      features.accountIdentities
        ? queryClient.ensureQueryData(accountIdentitiesQueryOptions())
        : Promise.resolve(undefined),
      features.accountMfa
        ? queryClient.ensureQueryData(mfaFactorsQueryOptions())
        : Promise.resolve(undefined),
    ])

    return { identities, mfaFactors }
  },
  component: AccountSecurityPage,
})

function AccountSecurityPage() {
  const initialData = Route.useLoaderData()
  return <AccountSecurity initialData={initialData} />
}
