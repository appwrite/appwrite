import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/databases/View'
import {
  databasesQueryOptions,
  projectQueryOptions,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import {
  listSearchSchema,
  getSearch,
  getPage,
  getLimit,
  getQueryParam,
  queryParamToMap,
} from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

const DATABASES_PER_PAGE = 25
const DEFAULT_PAGE = 1

const databasesSearchSchema = listSearchSchema.extend({
  create: z.string().optional().catch(undefined),
})

export const Route = createFileRoute('/_public/projects/$projectId/databases/')(
  {
    head: () => ({ meta: [{ title: pageTitle('Databases') }] }),
    validateSearch: databasesSearchSchema,
    pendingComponent: () => (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">Loading databases...</div>
      </div>
    ),
    loader: async ({ params, context, location }) => {
      if (typeof window === 'undefined') return

      const { projectId } = params
      const { queryClient } = context
      if (!projectId) return

      const url = new URL(location.pathname + location.search, 'http://localhost')
      const search = getSearch(url)
      const page = getPage(url, DEFAULT_PAGE)
      const limit = getLimit(url, DATABASES_PER_PAGE)
      const queryParam = getQueryParam(url)
      const filterMap = queryParamToMap(queryParam)
      const filterQueries =
        filterMap.size > 0 ? Array.from(filterMap.values()) : undefined

      const projectData = await queryClient.ensureQueryData(
        projectQueryOptions(projectId),
      )

      await Promise.all([
        queryClient.ensureQueryData(
          databasesQueryOptions(projectId, page - 1, limit, search ?? undefined, filterQueries),
        ),
        projectData?.teamId
          ? queryClient.ensureQueryData(
              organizationPlanQueryOptions(projectData.teamId),
            )
          : Promise.resolve(),
      ])
    },
    component: DatabasesIndexPage,
  },
)

function DatabasesIndexPage() {
  return <View />
}
