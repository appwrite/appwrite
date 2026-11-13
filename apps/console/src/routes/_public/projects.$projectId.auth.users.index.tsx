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

const usersSearchSchema = listSearchSchema.extend({
  create: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/users/',
)({
  head: () => ({ meta: [{ title: pageTitle('Users', 'Auth') }] }),
  validateSearch: usersSearchSchema,
  loaderDeps: ({ search }) => search,
  loader: async ({ params, context, deps: routeSearch }) => {
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
