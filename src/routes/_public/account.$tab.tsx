import { View } from '@/components/pages/account/View'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  accountSessionsQueryOptions,
  accountIdentitiesQueryOptions,
  mfaFactorsQueryOptions,
  paymentMethodsQueryOptions,
  billingAddressesQueryOptions,
  organizationsFullQueryOptions,
  fetchCountries,
  fetchLocale,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

// Valid account tabs
const VALID_TABS = ['overview', 'sessions', 'payments'] as const

const TAB_LABELS: Record<string, string> = {
  overview: 'Overview',
  sessions: 'Sessions',
  payments: 'Payments',
}

export const Route = createFileRoute('/_public/account/$tab')({
  beforeLoad: ({ params }) => {
    const tab = params.tab as string | undefined
    if (tab === 'payments' && !getActiveProfileFeatures().billing) {
      throw redirect({
        to: '/account',
        replace: true,
      })
    }
  },
  head: ({ params }) => {
    const tab = params.tab as string | undefined
    const tabLabel = tab
      ? (TAB_LABELS[tab] ?? tab.charAt(0).toUpperCase() + tab.slice(1))
      : 'Overview'
    return { meta: [{ title: pageTitle(tabLabel, 'Account') }] }
  },
  loader: async ({ params, context }) => {
    // Redirect invalid tabs to overview
    const tab = params.tab as string | undefined
    if (tab && !(VALID_TABS as readonly string[]).includes(tab)) {
      throw redirect({
        to: '/account',
        replace: true,
      })
    }

    // Only run on client side (SDK requires browser environment)
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { queryClient } = context

    // Fetch critical data before rendering to prevent layout shifts
    // Use ensureQueryData to use cache if available, fetch if stale/missing
    if (tab === 'overview') {
      const features = getActiveProfileFeatures()
      const overviewFetches = [
        ...(features.accountIdentities
          ? [queryClient.ensureQueryData(accountIdentitiesQueryOptions())]
          : []),
        ...(features.accountMfa
          ? [queryClient.ensureQueryData(mfaFactorsQueryOptions())]
          : []),
      ]
      if (overviewFetches.length > 0) {
        await Promise.all(overviewFetches)
      }
    } else if (tab === 'sessions') {
      // Fetch sessions - blocks navigation until ready
      await queryClient.ensureQueryData(accountSessionsQueryOptions())
    } else if (tab === 'payments') {
      await Promise.all([
        queryClient.ensureQueryData(paymentMethodsQueryOptions()),
        queryClient.ensureQueryData(billingAddressesQueryOptions()),
        queryClient.ensureQueryData(organizationsFullQueryOptions()),
        queryClient.prefetchQuery({
          queryKey: ['countries', 'console'],
          queryFn: fetchCountries,
          staleTime: 5 * 60 * 1000,
        }),
        queryClient.prefetchQuery({
          queryKey: ['locale', 'console'],
          queryFn: fetchLocale,
          staleTime: 5 * 60 * 1000,
        }),
      ])
    }
  },
  component: AccountPage,
})

function AccountPage() {
  const { tab } = Route.useParams()
  return (
    <RequireAuth>
      <View activeTab={tab} />
    </RequireAuth>
  )
}
