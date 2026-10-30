import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/partners/$partnerId/onboard/View'
import { pageTitle } from '@/lib/utils/page-title'
import {
  HANDOFF_RESOURCES_PAGE_SIZE,
  activeProjectsQueryOptions,
  billingPlansQueryOptions,
  organizationAppsQueryOptions,
  organizationDomainsQueryOptions,
  organizationQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/partners/$partnerId/onboard')({
  head: () => ({ meta: [{ title: pageTitle('New client') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { partnerId } = params
    const { queryClient } = context
    if (!partnerId) return

    await Promise.all([
      queryClient.ensureQueryData(organizationQueryOptions(partnerId)),
      queryClient.ensureQueryData(billingPlansQueryOptions()),
      queryClient.ensureQueryData(
        activeProjectsQueryOptions(partnerId, 0, HANDOFF_RESOURCES_PAGE_SIZE, ''),
      ),
      queryClient.ensureQueryData(
        organizationDomainsQueryOptions(partnerId, 0, HANDOFF_RESOURCES_PAGE_SIZE),
      ),
      queryClient.ensureQueryData(
        organizationAppsQueryOptions(
          partnerId,
          undefined,
          undefined,
          0,
          HANDOFF_RESOURCES_PAGE_SIZE,
        ),
      ),
    ])
  },
  component: OnboardPage,
})

function OnboardPage() {
  const { partnerId } = Route.useParams()
  return <View partnerId={partnerId} />
}
