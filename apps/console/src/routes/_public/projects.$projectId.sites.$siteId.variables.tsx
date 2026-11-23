import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/Variables'
import {
  siteQueryOptions,
  siteVariablesQueryOptions,
  projectQueryOptions,
  projectVariablesQueryOptions,
} from '@/lib/react-query/hooks'
import { canAccessSiteSettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/variables',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, siteId } = params
    const { queryClient } = context

    const canAccess = await canAccessSiteSettings(queryClient, projectId)
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/sites/$siteId',
        params: { projectId, siteId },
        replace: true,
      })
    }

    await Promise.all([
      queryClient.ensureQueryData(siteQueryOptions(projectId, siteId)),
      queryClient.ensureQueryData(projectQueryOptions(projectId)),
      queryClient.ensureQueryData(projectVariablesQueryOptions(projectId)),
      queryClient.ensureQueryData(siteVariablesQueryOptions(projectId, siteId)),
    ])
  },
  component: SiteVariablesPage,
})

function SiteVariablesPage() {
  return <View />
}
