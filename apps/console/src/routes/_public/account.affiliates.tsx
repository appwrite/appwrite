import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  affiliateLinksQueryOptions,
  affiliatePendingRewardsQueryOptions,
  affiliateReferralsQueryOptions,
  affiliateRewardsQueryOptions,
  affiliateUsageQueryOptions,
  countriesQueryOptions,
  getDefaultAffiliateUsageQueryParams,
  organizationsFullQueryOptions,
  DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks'
import {
  AccountAffiliatesPage,
  type AccountAffiliatesInitialData,
} from '@/components/pages/account/Affiliates'

const AFFILIATE_LINK_LOOKUP_LIMIT = 100

export const Route = createFileRoute('/_public/account/affiliates')({
  head: () => ({ meta: [{ title: pageTitle('Affiliates', 'Account') }] }),
  beforeLoad: () => {
    if (!getActiveProfileFeatures().affiliates) {
      throw redirect({ to: '/account', replace: true })
    }
  },
  loader: async ({ context }): Promise<AccountAffiliatesInitialData | undefined> => {
    if (typeof window === 'undefined') return undefined

    const { queryClient } = context

    // Links decide empty vs dashboard. Block navigation on this first.
    const links = await queryClient.ensureQueryData(
      affiliateLinksQueryOptions(0, DEFAULT_PAGE_SIZE),
    )

    if (links.total === 0) {
      return {
        links,
        isProgramEmpty: true,
      }
    }

    const [referrals, rewards, pendingRewards, usage, organizations] =
      await Promise.all([
        queryClient.ensureQueryData(
          affiliateReferralsQueryOptions(0, DEFAULT_PAGE_SIZE),
        ),
        queryClient.ensureQueryData(
          affiliateRewardsQueryOptions(0, DEFAULT_PAGE_SIZE),
        ),
        queryClient.ensureQueryData(affiliatePendingRewardsQueryOptions()),
        queryClient.ensureQueryData(
          affiliateUsageQueryOptions(getDefaultAffiliateUsageQueryParams()),
        ),
        queryClient.ensureQueryData(organizationsFullQueryOptions()),
      ])

    // Warm link-name lookup cache used by referrals/rewards tables.
    await queryClient.ensureQueryData(
      affiliateLinksQueryOptions(0, AFFILIATE_LINK_LOOKUP_LIMIT),
    )

    queryClient.prefetchQuery(countriesQueryOptions()).catch(() => {
      // Optional for referral country labels
    })

    return {
      links,
      referrals,
      rewards,
      pendingRewards,
      usage,
      organizations,
      isProgramEmpty: false,
    }
  },
  component: AccountAffiliatesRoute,
})

function AccountAffiliatesRoute() {
  const initialData = Route.useLoaderData()
  return <AccountAffiliatesPage initialData={initialData} />
}
