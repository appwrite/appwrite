import { createFileRoute, redirect } from '@tanstack/react-router'
import { CreateAppView } from '@/components/pages/projects/$projectId/distribution/create/CreateAppView'
import { canAccessDistributionAppCreate } from '@/lib/console-rbac-loader'
import { vcsInstallationsQueryOptions } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/create',
)({
  head: () => ({ meta: [{ title: pageTitle('Create app', 'Distribution') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context
    if (!(await canAccessDistributionAppCreate(queryClient, projectId))) {
      throw redirect({
        to: '/projects/$projectId/distribution',
        params: { projectId },
        replace: true,
      })
    }
    await queryClient
      .ensureQueryData(vcsInstallationsQueryOptions(projectId))
      .catch(() => undefined)
  },
  component: CreateAppPage,
})

function CreateAppPage() {
  const { projectId } = Route.useParams()
  return (
    <CreateAppView
      key={`distribution-${projectId}-create`}
      projectId={projectId}
    />
  )
}
