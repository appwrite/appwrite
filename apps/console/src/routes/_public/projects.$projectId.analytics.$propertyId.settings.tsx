import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/analytics/$propertyId/settings/View'
import { pageTitle } from '@/lib/utils/page-title'
import { analyticsPropertyQueryOptions } from '@/lib/react-query/hooks'

/**
 * The property's Settings tab, its own page like Sites and Functions
 * settings. Needs only the property (already loaded by the parent layout),
 * none of the analytics metrics.
 */
export const Route = createFileRoute(
  '/_public/projects/$projectId/analytics/$propertyId/settings',
)({
  head: () => ({ meta: [{ title: pageTitle('Settings', 'Analytics') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { projectId, propertyId } = params
    const property = await context.queryClient
      .ensureQueryData(analyticsPropertyQueryOptions(projectId, propertyId))
      .catch(() => undefined)
    return property ? { property } : undefined
  },
  component: PropertySettingsPage,
})

function PropertySettingsPage() {
  const { projectId, propertyId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  const navigate = useNavigate()

  return (
    <View
      key={`analytics-property-settings-${propertyId}`}
      projectId={projectId}
      propertyId={propertyId}
      onBack={() =>
        navigate({ to: '/projects/$projectId/analytics', params: { projectId } })
      }
      initialProperty={loaderData?.property}
    />
  )
}
