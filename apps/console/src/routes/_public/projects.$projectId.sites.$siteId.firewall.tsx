import { createFileRoute, redirect } from '@tanstack/react-router'
import { Firewall } from '@/components/pages/projects/$projectId/firewall/Firewall'
import {
  prefetchFirewallTabData,
  siteQueryOptions,
} from '@/lib/react-query/hooks'
import { isCloudProfile } from '@/lib/console-profiles'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/firewall',
)({
  beforeLoad: ({ params }) => {
    if (!isCloudProfile()) {
      throw redirect({
        to: '/projects/$projectId/sites/$siteId',
        params,
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, siteId } = params
    const { queryClient } = context

    await Promise.all([
      queryClient.ensureQueryData(siteQueryOptions(projectId, siteId)),
      prefetchFirewallTabData(queryClient, projectId, {
        resourceType: 'sites',
        resourceId: siteId,
      }),
    ])
  },
  component: SiteFirewallPage,
})

function SiteFirewallPage() {
  const { projectId, siteId } = Route.useParams()
  return (
    <Firewall
      projectId={projectId}
      resourceSelection={{ resourceType: 'sites', resourceId: siteId }}
    />
  )
}
