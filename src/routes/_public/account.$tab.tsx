import { View } from '@/components/pages/account/View'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import {
  accountSessionsQueryOptions,
  accountIdentitiesQueryOptions,
  mfaFactorsQueryOptions,
  paymentMethodsQueryOptions,
  billingAddressesQueryOptions,
  fetchCountries,
  fetchLocale,
} from '@/lib/react-query/hooks'
import { Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { pageTitle } from '@/lib/utils/page-title'

// Valid account tabs
const VALID_TABS = ['overview', 'sessions', 'payments'] as const

const TAB_LABELS: Record<string, string> = {
  overview: 'Overview',
  sessions: 'Sessions',
  payments: 'Payments',
}

export const Route = createFileRoute('/_public/account/$tab')({
  head: ({ params }) => {
    const tab = params.tab as string | undefined
    const tabLabel = tab
      ? TAB_LABELS[tab] ?? tab.charAt(0).toUpperCase() + tab.slice(1)
      : 'Overview'
    return { meta: [{ title: pageTitle(tabLabel, 'Account') }] }
  },
  pendingComponent: () => (
    <div className="flex h-full items-center justify-center">
      <div className="text-muted-foreground">Loading account...</div>
    </div>
  ),
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
      // Fetch identities and MFA factors for overview tab - blocks navigation until ready
      await Promise.all([
        queryClient.ensureQueryData(accountIdentitiesQueryOptions()),
        queryClient.ensureQueryData(mfaFactorsQueryOptions()),
      ])
    } else if (tab === 'sessions') {
      // Fetch sessions - blocks navigation until ready
      await queryClient.ensureQueryData(accountSessionsQueryOptions())
    } else if (tab === 'payments') {
      // Fetch payment data - blocks navigation until ready
      await Promise.all([
        queryClient.ensureQueryData(paymentMethodsQueryOptions()),
        queryClient.ensureQueryData(billingAddressesQueryOptions()),
        // Prefetch supporting data (optional, doesn't block)
        queryClient.prefetchQuery({
          queryKey: ['countries', 'console'],
          queryFn: fetchCountries,
          staleTime: 5 * 60 * 1000, // 5 minutes - countries don't change often
        }),
        queryClient.prefetchQuery({
          queryKey: ['locale', 'console'],
          queryFn: fetchLocale,
          staleTime: 5 * 60 * 1000, // 5 minutes
        }),
        queryClient.prefetchQuery({
          queryKey: ['organizations', 'console', 'full'],
          queryFn: async () => {
            const response = await sdk.forConsole.organizations.list({
              queries: [Query.equal('platform', 'appwrite')],
            })
            return response.teams || []
          },
          staleTime: 30 * 1000,
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
