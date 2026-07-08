import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { accountConnectedAppsQueryOptions } from '@/lib/react-query/hooks/account-applications'
import { AccountApplications } from '@/components/pages/account/Applications'

export const Route = createFileRoute('/_public/account/applications')({
  head: () => ({ meta: [{ title: pageTitle('Applications', 'Account') }] }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    const { queryClient } = context
    return await queryClient.ensureQueryData(accountConnectedAppsQueryOptions())
  },
  component: AccountApplicationsPage,
})

function AccountApplicationsPage() {
  const initialData = Route.useLoaderData()
  return <AccountApplications initialData={initialData} />
}
