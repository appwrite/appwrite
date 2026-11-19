import { createFileRoute, redirect } from '@tanstack/react-router'
import { MonitorsLayout } from '@/components/pages/projects/$projectId/monitors/Layout'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  monitorsQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/monitors')({
  head: () => ({ meta: [{ title: pageTitle('Monitors') }] }),
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().alerts) {
      throw redirect({
        to: '/projects/$projectId/overview',
        params,
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { queryClient } = context
    // Region must be cached before the project-scoped monitors and alerts calls.
    await queryClient.ensureQueryData(projectQueryOptions(params.projectId))
    // The Alerts tab counts firing monitors; load them with the page so the
    // count does not pop in. Editions without monitors answer with an error,
    // which leaves the count off.
    await queryClient
      .ensureQueryData(monitorsQueryOptions(params.projectId))
      .catch(() => undefined)
  },
  component: MonitorsLayoutPage,
})

function MonitorsLayoutPage() {
  const { projectId } = Route.useParams()
  return <MonitorsLayout projectId={projectId} />
}
