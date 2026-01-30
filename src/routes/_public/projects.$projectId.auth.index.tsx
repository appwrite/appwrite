import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/View'
import { usersQueryOptions } from '@/lib/react-query/hooks'

const USERS_PER_PAGE = 25

export const Route = createFileRoute('/_public/projects/$projectId/auth/')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    // Fetch users for the project (initial page, no search)
    if (projectId) {
      // Fetch first page of users - blocks navigation until ready
      // ensureQueryData uses cache if fresh, fetches if stale/missing
      await queryClient.ensureQueryData(
        usersQueryOptions(projectId, 0, USERS_PER_PAGE, ''),
      )
    }
  },
  component: AuthIndexPage,
})

function AuthIndexPage() {
  const { projectId } = Route.useParams()
  return <View key={`auth-${projectId}-users-index`} />
}
