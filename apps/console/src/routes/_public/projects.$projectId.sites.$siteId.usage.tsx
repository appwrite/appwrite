import { createFileRoute, redirect, useParams } from '@tanstack/react-router'
import {
  ResourceUsage,
  type ResourceUsageCategory,
} from '@/components/pages/projects/$projectId/shared/ResourceUsage'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/usage',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().usageStats) {
      throw redirect({
        to: '/projects/$projectId/sites/$siteId',
        params,
        replace: true,
      })
    }
  },
  component: SiteUsagePage,
})

// The shell stays mounted across categories; the child route only names one.
function SiteUsagePage() {
  const { projectId, siteId } = Route.useParams()
  const { categoryId } = useParams({ strict: false }) as {
    categoryId?: ResourceUsageCategory
  }
  return (
    <ResourceUsage
      projectId={projectId}
      resourceType="site"
      resourceId={siteId}
      category={categoryId}
    />
  )
}
