import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/Variables'
import {
  projectQueryOptions,
  projectFunctionQueryOptions,
  functionVariablesQueryOptions,
  projectVariablesQueryOptions,
} from '@/lib/react-query/hooks'
import { canAccessFunctionSecuritySettings } from '@/lib/console-rbac-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/variables',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.function?.name ?? 'Function', 'Functions'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

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

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      queryClient.ensureQueryData(
        projectFunctionQueryOptions(projectId, functionId),
      ),
      queryClient.ensureQueryData(
        functionVariablesQueryOptions(projectId, functionId),
      ),
      queryClient.ensureQueryData(projectVariablesQueryOptions(projectId)),
    ])
    const fn = queryClient.getQueryData<{ name?: string }>(
      projectFunctionQueryOptions(projectId, functionId).queryKey,
    )
    return { function: fn }
  },
  component: View,
})
