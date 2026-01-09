import { createFileRoute } from '@tanstack/react-router'
import { UserDetailView } from '@/components/pages/projects/$projectId/auth/users/View'
import { fetchUser, fetchUserMFAFactors } from '@/lib/react-query/hooks/users'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/users/$userId',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, userId } = params
    const { queryClient } = context

    if (projectId && userId) {
      try {
        await Promise.all([
          queryClient.ensureQueryData({
            queryKey: ['user', 'project', projectId, userId],
            queryFn: () => fetchUser(projectId, userId),
            staleTime: 30 * 1000,
          }),
          queryClient.ensureQueryData({
            queryKey: ['user', 'mfa-factors', 'project', projectId, userId],
            queryFn: () => fetchUserMFAFactors(projectId, userId),
            staleTime: 30 * 1000,
          }),
        ])
      } catch (error) {
        // Log error but don't block rendering - let component handle error state
        console.error('Error prefetching user data:', error)
      }
    }
  },
  component: UserDetailPage,
})

function UserDetailPage() {
  const params = Route.useParams()
  return <UserDetailView key={`user-${params.projectId}-${params.userId}`} />
}

