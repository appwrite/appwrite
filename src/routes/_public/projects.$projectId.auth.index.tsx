import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/View'
import { projectQueryOptions, usersQueryOptions } from '@/lib/react-query/hooks'

const USERS_PER_PAGE = 25

export const Route = createFileRoute('/_public/projects/$projectId/auth/')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      // Ensure project is loaded first so SDK has the project's region for the correct endpoint
      await queryClient.ensureQueryData(projectQueryOptions(projectId))
      // Fetch first page of users - blocks navigation until ready
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
