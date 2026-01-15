import { AccountView } from '@/components/pages/account/View'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import {
  fetchAccountSessions,
  fetchPaymentMethods,
  fetchBillingAddresses,
  fetchCountries,
  fetchLocale,
} from '@/lib/react-query/hooks'
import { Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

// Valid account tabs
const VALID_TABS = ['overview', 'sessions', 'payments'] as const

export const Route = createFileRoute('/_public/account/$tab')({
  beforeLoad: ({ params }) => {
    // Redirect invalid tabs to overview
    if (params.tab && !VALID_TABS.includes(params.tab as any)) {
      throw redirect({
        to: '/account',
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { tab } = params
    const { queryClient } = context

    // Prefetch data based on tab
    if (tab === 'sessions') {
      await queryClient.prefetchQuery({
        queryKey: ['sessions', 'account'],
        queryFn: fetchAccountSessions,
        staleTime: 30 * 1000, // 30 seconds
      })
    } else if (tab === 'payments') {
      // Prefetch payment data in parallel
      await Promise.all([
        queryClient.prefetchQuery({
          queryKey: ['payment-methods', 'account'],
          queryFn: fetchPaymentMethods,
          staleTime: 30 * 1000,
        }),
        queryClient.prefetchQuery({
          queryKey: ['billing-addresses', 'account'],
          queryFn: fetchBillingAddresses,
          staleTime: 30 * 1000,
        }),
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
            const response = await sdk.forConsole.organizations.list(
              [Query.equal('platform', 'appwrite')],
            )
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
      <AccountView activeTab={tab} />
    </RequireAuth>
  )
}
