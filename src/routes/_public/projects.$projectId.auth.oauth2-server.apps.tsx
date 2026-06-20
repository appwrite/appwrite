import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessProjectOAuth2Server } from '@/lib/console-rbac-loader'
import {
  projectQueryOptions,
  projectOAuth2AppsQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/oauth2-server/apps',
)({
  head: () => ({ meta: [{ title: pageTitle('Apps', 'OAuth2 server') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined

    const canAccess = await canAccessProjectOAuth2Server(queryClient, projectId)
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/auth',
        params: { projectId },
        replace: true,
      })
    }

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const project = queryClient.getQueryData(
      projectQueryOptions(projectId).queryKey,
    ) as { region?: string } | undefined

    await queryClient.ensureQueryData(
      projectOAuth2AppsQueryOptions(projectId, project?.region),
    )
  },
  component: () => null,
})
