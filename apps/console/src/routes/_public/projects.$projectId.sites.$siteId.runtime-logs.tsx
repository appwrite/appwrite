import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/sites/RuntimeLogs'
import { projectQueryOptions, siteQueryOptions } from '@/lib/react-query/hooks'
import { isCloudProfile } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  deploymentId: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/runtime-logs',
)({
  validateSearch: searchSchema,
  beforeLoad: ({ params }) => {
    // Runtime logs are served by Cloud only.
    if (!isCloudProfile()) {
      throw redirect({
        to: '/projects/$projectId/sites/$siteId',
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

    const { projectId, siteId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Site metadata only - logs load in the view with a skeleton (do not block navigation)
    await queryClient.ensureQueryData(siteQueryOptions(projectId, siteId))
    const site = queryClient.getQueryData<{ name?: string }>(
      siteQueryOptions(projectId, siteId).queryKey,
    )
    return { site }
  },
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.site?.name ?? 'Site', 'Sites'),
      },
    ],
  }),
  component: View,
})
