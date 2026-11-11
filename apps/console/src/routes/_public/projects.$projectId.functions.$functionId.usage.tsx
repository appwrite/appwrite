import { createFileRoute, redirect, useParams } from '@tanstack/react-router'
import {
  ResourceUsage,
  type ResourceUsageCategory,
} from '@/components/pages/projects/$projectId/shared/ResourceUsage'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/usage',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().usageStats) {
      throw redirect({
        to: '/projects/$projectId/functions/$functionId',
        params,
        replace: true,
      })
    }
  },
  component: FunctionUsagePage,
})

// The shell stays mounted across categories; the child route only names one.
function FunctionUsagePage() {
  const { projectId, functionId } = Route.useParams()
  const { categoryId } = useParams({ strict: false }) as {
    categoryId?: ResourceUsageCategory
  }
  return (
    <ResourceUsage
      projectId={projectId}
      resourceType="function"
      resourceId={functionId}
      category={categoryId}
    />
  )
}
