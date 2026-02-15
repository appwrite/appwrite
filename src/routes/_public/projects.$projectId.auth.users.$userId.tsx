import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/users/View'
import { fetchUser, fetchUserMFAFactors } from '@/lib/react-query/hooks/users'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/users/$userId',
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
      await Promise.all([
        // Fetch user - blocks navigation until ready
        queryClient.fetchQuery({
          queryKey: ['user', 'project', projectId, userId],
          queryFn: () => fetchUser(projectId, userId),
          staleTime: 30 * 1000,
        }),
        // Fetch MFA factors - blocks navigation until ready
        queryClient.fetchQuery({
          queryKey: ['user', 'mfa-factors', 'project', projectId, userId],
          queryFn: () => fetchUserMFAFactors(projectId, userId),
          staleTime: 30 * 1000,
        }),
      ])
      const user = queryClient.getQueryData<
        Awaited<ReturnType<typeof fetchUser>>
      >(['user', 'project', projectId, userId])
      return { user }
    }
  },
  component: UserDetailPage,
})

function UserDetailPage() {
  const params = Route.useParams()
  return <View key={`user-${params.projectId}-${params.userId}`} />
}
