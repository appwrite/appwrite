import { createFileRoute, redirect } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { View } from '@/components/pages/projects/$projectId/auth/users/View'
import { projectQueryOptions } from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { fetchUser, fetchUserMFAFactors } from '@/lib/react-query/hooks/users'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/users/$userId/analytics',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().analytics) {
      throw redirect({
        to: '/projects/$projectId/auth/users/$userId',
        params: { projectId: params.projectId, userId: params.userId },
        replace: true,
      })
    }
  },
  head: ({ loaderData }) => {
    // Router types `head`'s loaderData as never for this layout; the loader
    // below returns `{ user }` on the client.
    const user = (loaderData as { user?: Models.User } | undefined)?.user
    return {
      meta: [
        {
          title: pageTitle(user?.name || user?.email || 'User', 'Auth'),
        },
      ],
    }
  },
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
      // Fetch critical data before rendering to prevent layout shifts
      await Promise.all([
        queryClient.fetchQuery({
          queryKey: ['user', 'project', projectId, userId],
          queryFn: () => fetchUser(projectId, userId),
          staleTime: 30 * 1000,
        }),
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
  component: UserAnalyticsPage,
})

function UserAnalyticsPage() {
  const params = Route.useParams()
  return <View key={`user-${params.projectId}-${params.userId}`} />
}
