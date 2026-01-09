import { createFileRoute } from '@tanstack/react-router'
import { AuthView } from '@/components/pages/projects/$projectId/auth/View'
import { fetchProjectUsers } from '@/lib/react-query/hooks'

const USERS_PER_PAGE = 25

export const Route = createFileRoute('/_public/projects/$projectId/auth/')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    // Prefetch users for the project (initial page, no search)
    if (projectId) {
      try {
        await queryClient.ensureQueryData({
          queryKey: ['users', 'project', projectId, 0, USERS_PER_PAGE, ''],
          queryFn: () => fetchProjectUsers(projectId, 0, USERS_PER_PAGE, ''),
          staleTime: 30 * 1000, // 30 seconds
        })
      } catch (error) {
        // Log error but don't block rendering - let component handle error state
        console.error('Error prefetching users data:', error)
      }
    }
  },
  component: AuthIndexPage,
})

function AuthIndexPage() {
  const { projectId } = Route.useParams()
  return <AuthView key={`auth-${projectId}-users-index`} />
}
