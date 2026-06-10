import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { accountSessionsQueryOptions } from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/account/sessions')({
  head: () => ({ meta: [{ title: pageTitle('Sessions', 'Account') }] }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    const { queryClient } = context
    await queryClient.ensureQueryData(accountSessionsQueryOptions())
  },
  component: AccountSessionsPage,
})

function AccountSessionsPage() {
  return null
}
