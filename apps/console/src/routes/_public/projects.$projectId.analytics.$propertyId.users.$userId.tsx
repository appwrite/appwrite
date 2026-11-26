import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/analytics/$propertyId/users/$userId/View'
import { pageTitle } from '@/lib/utils/page-title'

/**
 * One identified user's activity in a property, opened from the Users card.
 * The parent layout has loaded the project and property.
 */
export const Route = createFileRoute(
  '/_public/projects/$projectId/analytics/$propertyId/users/$userId',
)({
  head: ({ params }) => ({
    meta: [{ title: pageTitle(params.userId, 'Analytics') }],
  }),
  component: UserAnalyticsPage,
})

function UserAnalyticsPage() {
  const { projectId, propertyId, userId } = Route.useParams()
  return (
    <View
      key={`analytics-user-${propertyId}-${userId}`}
      projectId={projectId}
      propertyId={propertyId}
      userId={userId}
    />
  )
}
