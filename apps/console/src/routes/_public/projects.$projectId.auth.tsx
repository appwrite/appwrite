import { useMemo } from 'react'
import {
  createFileRoute,
  Outlet,
  useLocation,
  useMatches,
} from '@tanstack/react-router'
import {
  View,
  type UsersListSearch,
} from '@/components/pages/projects/$projectId/auth/View'
import {
  projectQueryOptions,
  projectSmtpStatusQueryOptions,
} from '@/lib/react-query/hooks'
import type { AuthOAuth2SettingsInitialData } from '@/lib/react-query/hooks/oauth2-providers'
import {
  isAuthTeamDetailPath,
  isAuthUserDetailPath,
  isAuthUsersIndexPath,
} from '@/lib/auth-routes'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/auth')({
  head: () => ({ meta: [{ title: pageTitle('Auth') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      await Promise.all([
        queryClient.ensureQueryData(projectQueryOptions(projectId)),
        queryClient.ensureQueryData(projectSmtpStatusQueryOptions(projectId)),
      ])
    }
  },
  component: AuthPage,
})

function AuthPage() {
  const { projectId } = Route.useParams()
  const location = useLocation()
  const matches = useMatches()

  const isUserDetailRoute = isAuthUserDetailPath(location.pathname)
  const isTeamDetailRoute = isAuthTeamDetailPath(location.pathname)

  const usersListSearch = useMemo((): UsersListSearch | undefined => {
    if (!isAuthUsersIndexPath(location.pathname, projectId)) return undefined
    const indexMatch = matches.find(
      (match) => match.routeId === '/_public/projects/$projectId/auth/',
    )
    const search = indexMatch?.search
    if (!search || typeof search !== 'object') return undefined
    return {
      search: 'search' in search ? search.search : undefined,
      query: 'query' in search ? search.query : undefined,
      page: 'page' in search ? search.page : undefined,
      limit: 'limit' in search ? search.limit : undefined,
      sort: 'sort' in search ? search.sort : undefined,
    }
  }, [location.pathname, projectId, matches])

  const authSocialProvidersInitialData = useMemo(():
    | AuthOAuth2SettingsInitialData
    | undefined => {
    const match = matches.find((m) =>
      m.routeId.includes('/auth/social-providers'),
    )
    return match?.loaderData as AuthOAuth2SettingsInitialData | undefined
  }, [matches])

  if (isUserDetailRoute || isTeamDetailRoute) {
    return <Outlet />
  }

  return (
    <View
      key={`auth-${projectId}`}
      usersListSearch={usersListSearch}
      authSocialProvidersInitialData={authSocialProvidersInitialData}
    />
  )
}
