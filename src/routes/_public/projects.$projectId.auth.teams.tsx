import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/auth/View'
import {
  projectQueryOptions,
  teamsQueryOptions,
} from '@/lib/react-query/hooks'
import {
  listSearchSchema,
  queryParamToMap,
} from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

const TEAMS_PER_PAGE = 25
const DEFAULT_PAGE = 1

const authTeamsSearchSchema = listSearchSchema.extend({
  teamsSearch: z.string().optional().catch(undefined),
  teamsQuery: z.string().optional().catch(undefined),
  teamsPage: z.coerce.number().int().min(1).optional().catch(undefined),
  teamsLimit: z.coerce.number().int().min(1).max(100).optional().catch(undefined),
})

export const Route = createFileRoute('/_public/projects/$projectId/auth/teams')(
  {
    head: () => ({ meta: [{ title: pageTitle('Teams', 'Auth') }] }),
    validateSearch: authTeamsSearchSchema,
    loader: async ({ params, context, location }) => {
      if (typeof window === 'undefined') return

      const { projectId } = params
      const { queryClient } = context
      if (!projectId) return

      const url = new URL(location.pathname + location.search, 'http://localhost')
      const teamsSearch = url.searchParams.get('teamsSearch')?.trim() || undefined
      const teamsPage = (() => {
        const p = url.searchParams.get('teamsPage')
        if (p == null || p === '') return DEFAULT_PAGE
        const n = Number(p)
        return Number.isInteger(n) && n >= 1 ? n : DEFAULT_PAGE
      })()
      const teamsLimit = (() => {
        const p = url.searchParams.get('teamsLimit')
        if (p == null || p === '') return TEAMS_PER_PAGE
        const n = Number(p)
        return Number.isInteger(n) && n >= 1 ? n : TEAMS_PER_PAGE
      })()
      const teamsQueryParam = url.searchParams.get('teamsQuery')
      const teamsFilterMap = queryParamToMap(teamsQueryParam)
      const teamsFilterQueries =
        teamsFilterMap.size > 0 ? Array.from(teamsFilterMap.values()) : undefined

      await queryClient.ensureQueryData(projectQueryOptions(projectId))
      await queryClient.ensureQueryData(
        teamsQueryOptions(
          projectId,
          teamsPage - 1,
          teamsLimit,
          teamsSearch,
          teamsFilterQueries,
        ),
      )
    },
    component: AuthTeamsPage,
  },
)

function AuthTeamsPage() {
  const { projectId } = Route.useParams()
  const matches = useMatches()

  // Check if we're on a child route (team detail, etc.)
  const isChildRoute = matches.some(
    (match) =>
      match.routeId.includes('/auth/teams/$teamId') ||
      match.routeId.startsWith(
        '/_public/projects/$projectId/auth/teams/$teamId',
      ),
  )

  // If we're on a child route, render the outlet (child route component)
  if (isChildRoute) {
    return <Outlet />
  }

  // Otherwise, show the teams list view
  return <View key={`auth-${projectId}-teams`} />
}
