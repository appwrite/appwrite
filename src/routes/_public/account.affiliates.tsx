import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  affiliateLinksQueryOptions,
  affiliateReferralsQueryOptions,
  affiliateRewardsQueryOptions,
  affiliateUsageQueryOptions,
  countriesQueryOptions,
  organizationsFullQueryOptions,
  DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks'
import { AccountAffiliatesPage } from '@/components/pages/account/Affiliates'

export const Route = createFileRoute('/_public/account/affiliates')({
  head: () => ({ meta: [{ title: pageTitle('Affiliates', 'Account') }] }),
  beforeLoad: () => {
    if (!getActiveProfileFeatures().affiliates) {
      throw redirect({ to: '/account', replace: true })
    }
  },
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    const { queryClient } = context
    const [links, referrals, rewards, usage, organizations] = await Promise.all(
      [
        queryClient.ensureQueryData(
          affiliateLinksQueryOptions(0, DEFAULT_PAGE_SIZE),
        ),
        queryClient.ensureQueryData(
          affiliateReferralsQueryOptions(0, DEFAULT_PAGE_SIZE),
        ),
        queryClient.ensureQueryData(
          affiliateRewardsQueryOptions(0, DEFAULT_PAGE_SIZE),
        ),
        queryClient.ensureQueryData(affiliateUsageQueryOptions()),
        queryClient.ensureQueryData(organizationsFullQueryOptions()),
      ],
    )

    queryClient
      .prefetchQuery(countriesQueryOptions())
      .catch(() => {
        // Optional for referral country labels
      })

    return { links, referrals, rewards, usage, organizations }
  },
  component: AccountAffiliatesRoute,
})

function AccountAffiliatesRoute() {
  const initialData = Route.useLoaderData()
  return <AccountAffiliatesPage initialData={initialData} />
}
