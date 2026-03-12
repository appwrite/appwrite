import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/View'
import {
  functionsQueryOptions,
  fetchProject,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
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
  FUNCTIONS_DEFAULT_SORT_BY,
  FUNCTIONS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const DEFAULT_PAGE = 1

export const Route = createFileRoute('/_public/projects/$projectId/functions/')(
  {
    head: () => ({ meta: [{ title: pageTitle('Functions') }] }),
    validateSearch: listSearchSchema,
    loader: async ({ params, context, location }) => {
      if (typeof window === 'undefined') return

      const { projectId } = params
      const { queryClient } = context
      if (!projectId) return

      const url = new URL(
        location.pathname + location.search,
        'http://localhost',
      )
      const search = getSearch(url)
      const page = getPage(url, DEFAULT_PAGE)
      const limit = getLimit(url, DEFAULT_PAGE_SIZE)
      const queryParam = getQueryParam(url)
      const filterMap = queryParamToMap(queryParam)
      const filterQueries =
        filterMap.size > 0 ? Array.from(filterMap.values()) : undefined
      const sort = getSort(url)
      const sortBy = sort?.sortBy ?? FUNCTIONS_DEFAULT_SORT_BY
      const sortOrder = sort?.sortOrder ?? FUNCTIONS_DEFAULT_SORT_ORDER

      const projectData = await queryClient.ensureQueryData({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000,
      })

      await Promise.all([
        queryClient.ensureQueryData(
          functionsQueryOptions(
            projectId,
            page - 1,
            limit,
            search ?? undefined,
            filterQueries,
            sortBy,
            sortOrder,
          ),
        ),
        projectData?.teamId
          ? queryClient.ensureQueryData(
              organizationPlanQueryOptions(projectData.teamId),
            )
          : Promise.resolve(),
      ])
    },
    component: FunctionsIndexPage,
  },
)

function FunctionsIndexPage() {
  const { projectId } = Route.useParams()
  return <View key={`functions-${projectId}-index`} />
}
