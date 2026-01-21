import { createFileRoute } from '@tanstack/react-router'
import { UserDetailView } from '@/components/pages/projects/$projectId/auth/users/View'
import {
  fetchUser,
  fetchUserSessions,
  fetchUserMFAFactors,
} from '@/lib/react-query/hooks/users'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/users/$userId/sessions',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, userId } = params
    const { queryClient } = context

    if (projectId && userId) {
      await Promise.all([
        queryClient.prefetchQuery({
          queryKey: ['user', 'project', projectId, userId],
          queryFn: () => fetchUser(projectId, userId),
          staleTime: 30 * 1000,
        }),
        queryClient.prefetchQuery({
          queryKey: ['user', 'sessions', 'project', projectId, userId],
          queryFn: () => fetchUserSessions(projectId, userId),
          staleTime: 30 * 1000,
        }),
        queryClient.prefetchQuery({
          queryKey: ['user', 'mfa-factors', 'project', projectId, userId],
          queryFn: () => fetchUserMFAFactors(projectId, userId),
          staleTime: 30 * 1000,
        }),
      ])
    }
  },
  component: UserSessionsPage,
})

function UserSessionsPage() {
  return <UserDetailView />
}
