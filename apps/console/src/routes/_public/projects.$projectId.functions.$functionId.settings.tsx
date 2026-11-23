import { createFileRoute, redirect } from '@tanstack/react-router'
import { FunctionSettingsLayout } from '@/components/pages/projects/$projectId/functions/settings/FunctionSettingsLayout'
import {
  projectQueryOptions,
  projectFunctionQueryOptions,
  functionVariablesQueryOptions,
  projectRuntimesQueryOptions,
  projectVariablesQueryOptions,
  functionSpecificationsQueryOptions,
} from '@/lib/react-query/hooks'
import { SpecificationType } from '@/lib/specifications'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessFunctionSecuritySettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/settings',
)({
  head: () => ({
    meta: [{ title: pageTitle('Settings', 'Functions') }],
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

    await Promise.all([
      queryClient.ensureQueryData(
        projectFunctionQueryOptions(projectId, functionId),
      ),
      queryClient.ensureQueryData(
        functionVariablesQueryOptions(projectId, functionId),
      ),
      queryClient.ensureQueryData(projectVariablesQueryOptions(projectId)),
      queryClient.ensureQueryData(projectRuntimesQueryOptions(projectId)),
      queryClient.ensureQueryData(
        functionSpecificationsQueryOptions(
          projectId,
          SpecificationType.Runtimes,
        ),
      ),
      queryClient.ensureQueryData(
        functionSpecificationsQueryOptions(
          projectId,
          SpecificationType.Builds,
        ),
      ),
    ])
    const fn = queryClient.getQueryData<{ name?: string }>(
      projectFunctionQueryOptions(projectId, functionId).queryKey,
    )
    return { function: fn }
  },
  component: FunctionSettingsLayout,
})
