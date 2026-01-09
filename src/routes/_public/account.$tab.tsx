import { AccountView } from '@/components/pages/account/View'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { fetchAccountSessions } from '@/lib/react-query/hooks'

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

    // Prefetch sessions if on sessions tab
    if (tab === 'sessions') {
      await queryClient.prefetchQuery({
        queryKey: ['sessions', 'account'],
        queryFn: fetchAccountSessions,
        staleTime: 30 * 1000, // 30 seconds
      })
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
