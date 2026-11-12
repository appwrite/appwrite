import { createFileRoute, redirect } from '@tanstack/react-router'
import { WorkspaceLayout } from '@/components/pages/projects/$projectId/videos/_components/WorkspaceLayout'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  projectQueryOptions,
  videoProfilesQueryOptions,
  videosSidebarQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/videos')({
  beforeLoad: ({ params }) => {
    // Feature overrides live in localStorage, so only the client can resolve them.
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().videos) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Videos') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context

    // Resolves the project region before project-scoped calls.
    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await Promise.all([
      queryClient.ensureQueryData(videosSidebarQueryOptions(projectId)),
      queryClient
        .ensureQueryData(videoProfilesQueryOptions(projectId))
        .catch(() => null),
    ])
  },
  component: WorkspaceLayout,
})
