import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessProjectOAuth2Server } from '@/lib/console-rbac-loader'
import {
  projectQueryOptions,
  projectOAuth2AppsQueryOptions,
} from '@/lib/react-query/hooks'

/** `?appId=` opens that app's drawer (used by "Copy link" / "Open in new tab"). */
const oauth2ServerAppsSearchSchema = z.object({
  appId: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/oauth2-server/apps',
)({
  head: () => ({ meta: [{ title: pageTitle('Apps', 'OAuth2 server') }] }),
  validateSearch: oauth2ServerAppsSearchSchema,
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
