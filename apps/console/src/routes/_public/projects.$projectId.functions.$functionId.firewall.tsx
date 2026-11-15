import { createFileRoute, redirect } from '@tanstack/react-router'
import { Firewall } from '@/components/pages/projects/$projectId/firewall/Firewall'
import {
  prefetchFirewallTabData,
  projectFunctionQueryOptions,
} from '@/lib/react-query/hooks'
import { isCloudProfile } from '@/lib/console-profiles'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/firewall',
)({
  beforeLoad: ({ params }) => {
    if (!isCloudProfile()) {
      throw redirect({
        to: '/projects/$projectId/functions/$functionId',
        params,
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, functionId } = params
    const { queryClient } = context

    await Promise.all([
      queryClient.ensureQueryData(
        projectFunctionQueryOptions(projectId, functionId),
      ),
      prefetchFirewallTabData(queryClient, projectId, {
        resourceType: 'functions',
        resourceId: functionId,
      }),
    ])
  },
  component: FunctionFirewallPage,
})

function FunctionFirewallPage() {
  const { projectId, functionId } = Route.useParams()
  return (
    <Firewall
      projectId={projectId}
      resourceSelection={{ resourceType: 'functions', resourceId: functionId }}
    />
  )
}
