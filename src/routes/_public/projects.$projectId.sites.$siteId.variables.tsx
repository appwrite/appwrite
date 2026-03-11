import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/Variables'
import {
  siteQueryOptions,
  siteVariablesQueryOptions,
  projectQueryOptions,
  projectVariablesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessSiteSettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/variables',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          loaderData?.site?.name ?? loaderData?.site?.resourceId ?? 'Site',
          'Sites',
        ),
      },
    ],
  }),
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

    const site = await queryClient.ensureQueryData(
      siteQueryOptions(projectId, siteId),
    )

    await Promise.all([
      queryClient.ensureQueryData(projectQueryOptions(projectId)),
      queryClient.ensureQueryData(projectVariablesQueryOptions(projectId)),
      queryClient.ensureQueryData(
        siteVariablesQueryOptions(projectId, siteId),
      ),
    ])

    return { site }
  },
  component: SiteVariablesPage,
})

function SiteVariablesPage() {
  return <View />
}
