import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/functions/Executions'
import {
  projectQueryOptions,
  projectFunctionQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { listSearchSchema } from '@/lib/table-filters'

const searchSchema = listSearchSchema.extend({
  executionId: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/executions',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.function?.name ?? 'Function', 'Functions'),
      },
    ],
  }),
  validateSearch: searchSchema,
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Function metadata only - executions load in the view with a skeleton (do not block navigation)
    await queryClient.ensureQueryData(
      projectFunctionQueryOptions(projectId, functionId),
    )
    const fn = queryClient.getQueryData<{ name?: string }>(
      projectFunctionQueryOptions(projectId, functionId).queryKey,
    )
    return { function: fn }
  },
  component: View,
})
