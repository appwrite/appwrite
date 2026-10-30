import { createFileRoute } from '@tanstack/react-router'
import { parsePartnerClientPageSearch } from '@/lib/partners'
import { prefetchPartnerClientPage } from '@/lib/partner-workspace-prefetch'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/partners/$partnerId/clients/$clientId',
)({
  head: () => ({ meta: [{ title: pageTitle('Clients', 'Partners') }] }),
  validateSearch: parsePartnerClientPageSearch,
  loaderDeps: ({ search }) => ({ section: search.section }),
  loader: async ({ context, params, deps }) => {
    if (typeof window === 'undefined') return
    const { partnerId, clientId } = params
    if (!partnerId || !clientId) return
    await prefetchPartnerClientPage(
      context.queryClient,
      partnerId,
      clientId,
      deps.section,
    ).catch(() => {})
  },
  component: Page,
})

function Page() {
  return null
}
