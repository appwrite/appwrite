import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { projectQueryOptions, usersQueryOptions } from '@/lib/react-query/hooks'
import {
  USERS_DEFAULT_SORT_BY,
  USERS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks/users'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'
import { listSearchSchema, parseListSearch } from '@/lib/table-filters'

const DEFAULT_PAGE = 1

const authSearchSchema = listSearchSchema.extend({
  create: z.string().optional().catch(undefined),
  // Teams list params (when on auth/teams tab)
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

export const Route = createFileRoute('/_public/projects/$projectId/auth/')({
  head: () => ({ meta: [{ title: pageTitle('Users', 'Auth') }] }),
  validateSearch: authSearchSchema,
  loader: async ({ params, context, search: routeSearch }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    if (!projectId) return

    const { search, page, limit, filterQueries, sort } = parseListSearch(
      routeSearch,
      {
        page: DEFAULT_PAGE,
        limit: GRID_DEFAULT_PAGE_SIZE,
      },
    )
    const sortBy = sort?.sortBy ?? USERS_DEFAULT_SORT_BY
    const sortOrder = sort?.sortOrder ?? USERS_DEFAULT_SORT_ORDER

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      usersQueryOptions(
        projectId,
        page - 1,
        limit,
        search ?? undefined,
        filterQueries,
        sortBy,
        sortOrder,
      ),
    )
  },
})
