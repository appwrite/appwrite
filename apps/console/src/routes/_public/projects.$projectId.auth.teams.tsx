import { createFileRoute, Outlet } from '@tanstack/react-router'
import { z } from 'zod'
import { projectQueryOptions, teamsQueryOptions } from '@/lib/react-query/hooks'
import {
  listSearchSchema,
  queryParamToMap,
  searchParamsFromRouterLocation,
} from '@/lib/table-filters'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

const DEFAULT_PAGE = 1

const authTeamsSearchSchema = listSearchSchema.extend({
  teamsSearch: z.string().optional().catch(undefined),
  teamsQuery: z.string().optional().catch(undefined),
  teamsPage: z.coerce.number().int().min(1).optional().catch(undefined),
  teamsLimit: z.coerce
    .number()
    .int()
    .min(1)
    .max(100)
    .optional()
    .catch(undefined),
})

export const Route = createFileRoute('/_public/projects/$projectId/auth/teams')(
  {
    head: () => ({ meta: [{ title: pageTitle('Teams', 'Auth') }] }),
    validateSearch: authTeamsSearchSchema,
    loader: async ({ params, context, search: routeSearch, location }) => {
      if (typeof window === 'undefined') return

      const { projectId } = params
      const { queryClient } = context
      if (!projectId) return

      const validatedSearch =
        routeSearch ??
        authTeamsSearchSchema.parse(
          location.search && typeof location.search === 'object'
            ? location.search
            : Object.fromEntries(searchParamsFromRouterLocation(location)),
        )

      const teamsSearch = validatedSearch.teamsSearch?.trim() || undefined
      const teamsPage = validatedSearch.teamsPage ?? DEFAULT_PAGE
      const teamsLimit = validatedSearch.teamsLimit ?? GRID_DEFAULT_PAGE_SIZE
      const teamsFilterMap = queryParamToMap(validatedSearch.teamsQuery ?? null)
      const teamsFilterQueries =
        teamsFilterMap.size > 0
          ? Array.from(teamsFilterMap.values())
          : undefined

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
    component: AuthTeamsLayout,
  },
)

function AuthTeamsLayout() {
  return <Outlet />
}
