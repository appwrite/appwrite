import { createFileRoute, redirect } from '@tanstack/react-router'
import { parsePartnerWorkspacePageSearch } from '@/lib/partners'
import { prefetchPartnerWorkspacePage } from '@/lib/partner-workspace-prefetch'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/partners/$partnerId/')({
  head: () => ({ meta: [{ title: pageTitle('Workspace', 'Partners') }] }),
  validateSearch: parsePartnerWorkspacePageSearch,
  beforeLoad: ({ params, search }) => {
    if (search.view === 'development') return
    if (search.client) {
      throw redirect({
        to: '/partners/$partnerId/clients/$clientId',
        params: { partnerId: params.partnerId, clientId: search.client },
        search: search.section ? { section: search.section } : {},
        replace: true,
      })
    }
  },
  loaderDeps: ({ search }) => ({ section: search.section }),
  loader: async ({ params, context, deps }) => {
    if (typeof window === 'undefined') return
    const { partnerId } = params
    if (!partnerId) return
    await prefetchPartnerWorkspacePage(
      context.queryClient,
      partnerId,
      deps.section === 'domains' || deps.section === 'apps'
        ? deps.section
        : 'projects',
    ).catch(() => {})
  },
  component: Page,
})

function Page() {
  return null
}
