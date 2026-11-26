import { createFileRoute, redirect } from '@tanstack/react-router'
import { MonitorLayout } from '@/components/pages/projects/$projectId/monitors/$monitorId/Layout'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  monitorQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/monitors_/$monitorId',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          (loaderData as { name?: string } | undefined)?.name ?? 'Monitor',
          'Monitors',
        ),
      },
    ],
  }),
  // A sibling of the monitors list, not inside its layout, so the list keeps
  // its header until this page has loaded, as function and user pages do.
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().alerts) {
      throw redirect({
        to: '/projects/$projectId/overview',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    // Region must be cached before the project-scoped monitor call.
    await context.queryClient.ensureQueryData(
      projectQueryOptions(params.projectId),
    )
    // A missing monitor renders the not-found state instead of failing the route.
    const monitor = await context.queryClient
      .ensureQueryData(monitorQueryOptions(params.projectId, params.monitorId))
      .catch(() => undefined)
    return { name: monitor?.name }
  },
  component: MonitorLayoutPage,
})

function MonitorLayoutPage() {
  const { projectId, monitorId } = Route.useParams()
  return <MonitorLayout projectId={projectId} monitorId={monitorId} />
}
