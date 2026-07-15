import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/databases/View'
import {
  consoleDatabasesQueryOptions,
  databasesQueryOptions,
  dedicatedDatabasesQueryOptions,
  productDatabasesQueryOptions,
  projectQueryOptions,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { projectSupportsDedicatedDatabaseCompute } from '@/lib/databases/dedicated-database-regions'
import { DatabaseType } from '@appwrite.io/console'
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

      const profileFeatures = getActiveProfileFeatures()
      const supportsDedicatedDatabaseCompute =
        projectSupportsDedicatedDatabaseCompute(projectData?.region)
      const shouldPrefetchNativeDatabases =
        supportsDedicatedDatabaseCompute &&
        (profileFeatures.nativeDbsPostgres ||
          profileFeatures.nativeDbsMySQL ||
          profileFeatures.nativeDbsMongo)

      await Promise.all([
        queryClient.ensureQueryData(
          productDatabasesQueryOptions(
            projectId,
            DatabaseType.Tablesdb,
            page - 1,
            limit,
            search ?? undefined,
            filterQueries,
          ),
        ),
        // Merged total across product APIs for plan limit check
        queryClient.ensureQueryData(
          databasesQueryOptions(
            projectId,
            0,
            ROWS_DEFAULT_PAGE_SIZE,
            undefined,
            undefined,
          ),
        ),
        profileFeatures.dedicatedDbsDocumentsDB &&
        supportsDedicatedDatabaseCompute
          ? queryClient.ensureQueryData(
              productDatabasesQueryOptions(
                projectId,
                DatabaseType.Documentsdb,
                0,
                GRID_DEFAULT_PAGE_SIZE,
              ),
            )
          : Promise.resolve(),
        profileFeatures.dedicatedDbsVectorsDB &&
        supportsDedicatedDatabaseCompute
          ? queryClient.ensureQueryData(
              productDatabasesQueryOptions(
                projectId,
                DatabaseType.Vectorsdb,
                0,
                GRID_DEFAULT_PAGE_SIZE,
              ),
            )
          : Promise.resolve(),
        shouldPrefetchNativeDatabases
          ? queryClient.ensureQueryData(
              dedicatedDatabasesQueryOptions(projectId),
            )
          : Promise.resolve(),
        // Unified All Databases list (console.listDatabases)
        profileFeatures.dedicatedDbsSupport
          ? queryClient.ensureQueryData(
              consoleDatabasesQueryOptions(
                projectId,
                0,
                GRID_DEFAULT_PAGE_SIZE,
              ),
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
