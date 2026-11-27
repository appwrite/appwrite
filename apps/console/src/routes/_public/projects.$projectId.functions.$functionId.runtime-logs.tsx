import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/functions/RuntimeLogs'
import {
  projectQueryOptions,
  projectFunctionQueryOptions,
} from '@/lib/react-query/hooks'
import { isCloudProfile, isFeatureEnabled } from '@/lib/console-profiles'
import { runtimeLogsSearchSchema } from '@/lib/runtime-log-filters'
import { pageTitle } from '@/lib/utils/page-title'

// The deployment and the viewer's filters live in the URL
const searchSchema = runtimeLogsSearchSchema.extend({
  deploymentId: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/runtime-logs',
)({
  validateSearch: searchSchema,
  beforeLoad: ({ params }) => {
    // Cloud only: self-hosted doesn't serve `/v1/logs`, even with the flag on.
    if (!isCloudProfile() || !isFeatureEnabled('runtimeLogs')) {
      throw redirect({
        to: '/projects/$projectId/functions/$functionId',
        params,
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Function metadata only - logs load in the view with a skeleton (do not block navigation)
    await queryClient.ensureQueryData(
      projectFunctionQueryOptions(projectId, functionId),
    )
    const fn = queryClient.getQueryData<{ name?: string }>(
      projectFunctionQueryOptions(projectId, functionId).queryKey,
    )
    return { function: fn }
  },
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.function?.name ?? 'Function', 'Functions'),
      },
    ],
  }),
  component: View,
})
