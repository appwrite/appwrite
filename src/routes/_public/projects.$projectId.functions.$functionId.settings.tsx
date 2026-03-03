import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/Settings'
import {
  projectQueryOptions,
  projectFunctionQueryOptions,
  functionVariablesQueryOptions,
  projectRuntimesQueryOptions,
  projectVariablesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessFunctionSecuritySettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/settings',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.function?.name ?? 'Function', 'Functions'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, functionId } = params
    const { queryClient } = context

    const canAccess = await canAccessFunctionSecuritySettings(
      queryClient,
      projectId,
    )
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/functions/$functionId',
        params: { projectId, functionId },
        replace: true,
      })
    }

    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      queryClient.ensureQueryData(
        projectFunctionQueryOptions(projectId, functionId),
      ),
      // Fetch function variables - blocks navigation until ready
      queryClient.ensureQueryData(
        functionVariablesQueryOptions(projectId, functionId),
      ),
      // Fetch global variables - blocks navigation until ready
      queryClient.ensureQueryData(projectVariablesQueryOptions(projectId)),
      // Fetch runtimes - blocks navigation until ready
      queryClient.ensureQueryData(projectRuntimesQueryOptions(projectId)),
    ])
    const fn = queryClient.getQueryData<{ name?: string }>(
      projectFunctionQueryOptions(projectId, functionId).queryKey,
    )
    return { function: fn }
  },
  component: View,
})
