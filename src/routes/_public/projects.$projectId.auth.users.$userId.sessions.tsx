import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/users/View'
import {
  fetchUser,
  fetchUserSessions,
  fetchUserMFAFactors,
} from '@/lib/react-query/hooks/users'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/users/$userId/sessions',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          loaderData?.user?.name ?? loaderData?.user?.email ?? 'User',
          'Auth',
        ),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, userId } = params
    const { queryClient } = context

    if (projectId && userId) {
      // Fetch critical data before rendering to prevent layout shifts
      // fetchQuery blocks navigation until ready
      await Promise.all([
        queryClient.fetchQuery({
          queryKey: ['user', 'project', projectId, userId],
          queryFn: () => fetchUser(projectId, userId),
          staleTime: 30 * 1000,
        }),
        queryClient.fetchQuery({
          queryKey: ['user', 'sessions', 'project', projectId, userId],
          queryFn: () => fetchUserSessions(projectId, userId),
          staleTime: 30 * 1000,
        }),
        queryClient.fetchQuery({
          queryKey: ['user', 'mfa-factors', 'project', projectId, userId],
          queryFn: () => fetchUserMFAFactors(projectId, userId),
          staleTime: 30 * 1000,
        }),
      ])
      const user = queryClient.getQueryData<{ name?: string; email?: string }>([
        'user',
        'project',
        projectId,
        userId,
      ])
      return { user }
    }
  },
  component: UserSessionsPage,
})

function UserSessionsPage() {
  return <View />
}
