import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/sites/Logs'
import {
  siteQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { listSearchSchema } from '@/lib/table-filters'

const searchSchema = listSearchSchema.extend({
  executionId: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/logs',
)({
  validateSearch: searchSchema,
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Site metadata only - logs load in the view with a skeleton (do not block navigation)
    await queryClient.ensureQueryData(siteQueryOptions(projectId, siteId))
  },
  component: SiteLogsPage,
})

function SiteLogsPage() {
  return <View />
}
