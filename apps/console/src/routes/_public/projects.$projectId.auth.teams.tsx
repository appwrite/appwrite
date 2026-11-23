import { createFileRoute, Outlet } from '@tanstack/react-router'
import { z } from 'zod'
import { projectQueryOptions, teamsQueryOptions } from '@/lib/react-query/hooks'
import { listSearchSchema, queryParamToMap } from '@/lib/table-filters'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

const DEFAULT_PAGE = 1

const authTeamsSearchSchema = listSearchSchema.extend({
  create: z.string().optional().catch(undefined),
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
    loaderDeps: ({ search }) => ({
      teamsSearch: search.teamsSearch,
      teamsPage: search.teamsPage,
      teamsLimit: search.teamsLimit,
      teamsQuery: search.teamsQuery,
    }),
    loader: async ({ params, context, deps }) => {
      if (typeof window === 'undefined') return

      const { projectId } = params
      const { queryClient } = context
      if (!projectId) return

      const teamsSearch = deps.teamsSearch?.trim() || undefined
      const teamsPage = deps.teamsPage ?? DEFAULT_PAGE
      const teamsLimit = deps.teamsLimit ?? GRID_DEFAULT_PAGE_SIZE
      const teamsFilterMap = queryParamToMap(deps.teamsQuery ?? null)
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
