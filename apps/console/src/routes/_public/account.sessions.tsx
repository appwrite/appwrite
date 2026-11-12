import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { accountSessionsQueryOptions } from '@/lib/react-query/hooks'
import { AccountSessions } from '@/components/pages/account/Sessions'

export const Route = createFileRoute('/_public/account/sessions')({
  head: () => ({ meta: [{ title: pageTitle('Sessions', 'Account') }] }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    const { queryClient } = context
    return await queryClient.ensureQueryData(accountSessionsQueryOptions())
  },
  component: AccountSessionsPage,
})

function AccountSessionsPage() {
  const initialData = Route.useLoaderData()
  return <AccountSessions initialData={initialData} />
}
