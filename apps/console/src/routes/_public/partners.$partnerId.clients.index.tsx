import { createFileRoute } from '@tanstack/react-router'
import { prefetchPartnerClientsIndexPage } from '@/lib/partner-workspace-prefetch'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/partners/$partnerId/clients/')({
  head: () => ({ meta: [{ title: pageTitle('Clients', 'Partners') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { partnerId } = params
    if (!partnerId) return
    await prefetchPartnerClientsIndexPage(
      context.queryClient,
      partnerId,
    ).catch(() => {})
  },
  component: Page,
})

function Page() {
  return null
}
