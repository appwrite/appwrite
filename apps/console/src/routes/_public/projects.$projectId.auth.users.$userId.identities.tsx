import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/users/View'
import { projectQueryOptions } from '@/lib/react-query/hooks'
import {
  fetchUser,
  fetchUserIdentities,
  fetchUserMFAFactors,
} from '@/lib/react-query/hooks/users'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/users/$userId/identities',
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
      // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
      await queryClient.ensureQueryData(projectQueryOptions(projectId))
      await Promise.all([
        queryClient.fetchQuery({
          queryKey: ['user', 'project', projectId, userId],
          queryFn: () => fetchUser(projectId, userId),
          staleTime: 30 * 1000,
        }),
        queryClient.fetchQuery({
          queryKey: [
            'user',
            'identities',
            'project',
            projectId,
            userId,
            0,
            25,
            '',
          ],
          queryFn: () => fetchUserIdentities(projectId, userId, 0, 10, ''),
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
  component: UserIdentitiesPage,
})

function UserIdentitiesPage() {
  return <View />
}
