import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/auth/View'
import { projectQueryOptions, usersQueryOptions } from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'
import {
  listSearchSchema,
  getSearch,
  getPage,
  getLimit,
  getQueryParam,
  getSort,
  queryParamToMap,
} from '@/lib/table-filters'
import {
  USERS_DEFAULT_SORT_BY,
  USERS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks/users'

const DEFAULT_PAGE = 1

const authSearchSchema = listSearchSchema.extend({
  create: z.string().optional().catch(undefined),
  // Teams list params (when on auth/teams tab)
  teamsSearch: z.string().optional().catch(undefined),
  teamsQuery: z.string().optional().catch(undefined),
  teamsPage: z.coerce.number().int().min(1).optional().catch(undefined),
  teamsLimit: z.coerce.number().int().min(1).max(100).optional().catch(undefined),
})

export const Route = createFileRoute('/_public/projects/$projectId/auth/')({
  head: () => ({ meta: [{ title: pageTitle('Users', 'Auth') }] }),
  validateSearch: authSearchSchema,
  loader: async ({ params, context, location }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    if (!projectId) return

    const url = new URL(location.pathname + location.search, 'http://localhost')
    const search = getSearch(url)
    const page = getPage(url, DEFAULT_PAGE)
    const limit = getLimit(url, DEFAULT_PAGE_SIZE)
    const queryParam = getQueryParam(url)
    const filterMap = queryParamToMap(queryParam)
    const filterQueries = filterMap.size > 0 ? Array.from(filterMap.values()) : undefined
    const sort = getSort(url)
    const sortBy = sort?.sortBy ?? USERS_DEFAULT_SORT_BY
    const sortOrder = sort?.sortOrder ?? USERS_DEFAULT_SORT_ORDER

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    // Prefetch users with exact URL params so the View reads from cache (no loading flash)
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
  component: AuthIndexPage,
})

function AuthIndexPage() {
  const { projectId } = Route.useParams()
  const search = Route.useSearch()
  return (
    <View
      key={`auth-${projectId}-users-index`}
      usersListSearch={
        search && typeof search === 'object'
          ? (search as { search?: string; query?: string; page?: number; limit?: number; sort?: string })
          : undefined
      }
    />
  )
}
