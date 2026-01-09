import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { AuthView } from '@/components/pages/projects/$projectId/auth/View'
import { fetchProjectUsers } from '@/lib/react-query/hooks'

const USERS_PER_PAGE = 25

export const Route = createFileRoute('/_public/projects/$projectId/auth')({
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
  component: AuthPage,
})

function AuthPage() {
  const { projectId } = Route.useParams()
  const matches = useMatches()
  
  // Check if we're on a child route (user detail, team detail, etc.)
  const isChildRoute = matches.some(
    (match) =>
      match.routeId.includes('/auth/users/') ||
      match.routeId.includes('/auth/teams/') ||
      match.routeId === '/_public/projects/$projectId/auth/users/$userId' ||
      match.routeId === '/_public/projects/$projectId/auth/teams/$teamId' ||
      match.routeId.startsWith('/_public/projects/$projectId/auth/users/$userId') ||
      match.routeId.startsWith('/_public/projects/$projectId/auth/teams/$teamId')
  )

  // If we're on a child route, render the outlet (child route component)
  if (isChildRoute) {
    return <Outlet />
  }

  // Otherwise, show the main auth view
  return <AuthView key={`auth-${projectId}-users`} />
}

