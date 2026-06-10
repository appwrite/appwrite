import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/databases/View'
import {
  databasesQueryOptions,
  dedicatedDatabasesQueryOptions,
  projectQueryOptions,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  GRID_DEFAULT_PAGE_SIZE,
  ROWS_DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks/constants'
import { listSearchSchema, parseListSearch } from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

const DEFAULT_PAGE = 1

const databasesSearchSchema = listSearchSchema.extend({
  create: z.string().optional().catch(undefined),
})

export const Route = createFileRoute('/_public/projects/$projectId/databases/')(
  {
    head: () => ({ meta: [{ title: pageTitle('Databases') }] }),
    validateSearch: databasesSearchSchema,
    loader: async ({ params, context, search: routeSearch }) => {
      if (typeof window === 'undefined') return

      const { projectId } = params
      const { queryClient } = context
      if (!projectId) return

      const {
        search,
        page,
        limit,
        filterQueries,
      } = parseListSearch(routeSearch, {
        page: DEFAULT_PAGE,
        limit: GRID_DEFAULT_PAGE_SIZE,
      })

      const projectData = await queryClient.ensureQueryData(
        projectQueryOptions(projectId),
      )

      await Promise.all([
        queryClient.ensureQueryData(
          databasesQueryOptions(
            projectId,
            page - 1,
            limit,
            search ?? undefined,
            filterQueries,
          ),
        ),
        // Prefetch total count (no search/filters) for plan limit check so PlanLimitWarning has data on first paint and avoids layout shift
        queryClient.ensureQueryData(
          databasesQueryOptions(
            projectId,
            0,
            ROWS_DEFAULT_PAGE_SIZE,
            undefined,
            undefined,
          ),
        ),
        getActiveProfileFeatures().dedicatedDbsSupport
          ? queryClient.ensureQueryData(
              dedicatedDatabasesQueryOptions(projectId),
            )
          : Promise.resolve(),
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
