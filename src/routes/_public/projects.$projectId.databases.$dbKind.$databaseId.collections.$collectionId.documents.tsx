import { type DatabaseRouteKind } from '@/lib/database-routes'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { Workspace } from '@/components/pages/projects/$projectId/databases/View'
import {
  tablesQueryOptions,
  databaseQueryOptions,
  tableColumnsQueryOptions,
  tableIndexesQueryOptions,
  tableRowsQueryOptions,
  tableQueryOptions,
  projectQueryOptions,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  listSearchSchema,
  parseListSearch,
} from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'
import { throwRedirectTablesDbFromCollectionsChild } from '@/lib/database-route-redirects'

const TABLES_PER_PAGE = 100
const DEFAULT_PAGE = 1

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          (
            loaderData as
              | {
                  database?: { name?: string }
                  collection?: { name?: string }
                }
              | undefined
          )?.collection?.name ??
            (loaderData as { database?: { name?: string } } | undefined)
              ?.database?.name ??
            'Database',
          'Databases',
        ),
      },
    ],
  }),
  // No pendingComponent: keep the previous page visible until the loader's
  // prefetch is done, then transition with data already in cache. See
  // AGENTS.md → "Loading & Navigation" / "List & tab pages: never use pendingComponent".
  validateSearch: listSearchSchema,
  loader: async ({ params, context, search: routeSearch }) => {
    if (typeof window === 'undefined') return

    const { projectId, dbKind, databaseId, collectionId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    throwRedirectTablesDbFromCollectionsChild(dbKind, 'dataGrid', {
      projectId,
      dbKind,
      databaseId,
      collectionId,
    })

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    const projectData = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )

    // Fetch tables list (needed for redirect logic) - blocks navigation
    const tablesPromise = queryClient.ensureQueryData(
      tablesQueryOptions(
        projectId,
        databaseId,
        dbKind as DatabaseRouteKind,
        0,
        TABLES_PER_PAGE,
        undefined,
      ),
    )

    // If collectionId is '-', fetch first table and redirect to it if one exists
    if (collectionId === '-') {
      const tablesData = await queryClient.ensureQueryData(
        tablesQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
          0,
          ROWS_DEFAULT_PAGE_SIZE,
          undefined,
          'asc',
          '$createdAt',
        ),
      )
      const firstTable = (tablesData.tables || [])[0] as
        | { $id?: string }
        | undefined

      if (firstTable?.$id) {
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
          params: { projectId, dbKind, databaseId, collectionId: firstTable.$id },
          replace: true,
        })
      }
      // No tables: stay on tables/-/rows and render Workspace with database-level content
      await queryClient.ensureQueryData(
        databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind),
      )
      const database = queryClient.getQueryData<{ name?: string }>(
        databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind).queryKey,
      )
      return { database, collection: undefined }
    }

    if (collectionId) {
      // Check if table exists and if there are any tables
      const tablesData = await tablesPromise

      // If no tables exist, redirect to tables/-/rows (database main view)
      if (!tablesData.tables || tablesData.tables.length === 0) {
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
          params: { projectId, dbKind, databaseId, collectionId: '-' },
          replace: true,
        })
      }

      // Check if the requested table exists in the tables list
      const tableExists = tablesData.tables.some(
        (table: unknown) => table.$id === collectionId,
      )
      if (!tableExists) {
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
          params: { projectId, dbKind, databaseId, collectionId: '-' },
          replace: true,
        })
      }
      const { search, page, limit, filterQueries } = parseListSearch(
        routeSearch,
        {
          page: DEFAULT_PAGE,
          limit: ROWS_DEFAULT_PAGE_SIZE,
        },
      )

      await Promise.all([
        tablesPromise,

        queryClient.ensureQueryData(
          tableRowsQueryOptions(
            projectId,
            databaseId,
            collectionId,
            dbKind as DatabaseRouteKind,
            page - 1,
            limit,
            search ?? undefined,
            'desc',
            '$createdAt',
            filterQueries,
            undefined,
          ),
        ),

        // Database details - blocks navigation until ready
        queryClient.ensureQueryData(
          databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind),
        ),

        // Columns - blocks navigation until ready
        queryClient.ensureQueryData(
          tableColumnsQueryOptions(
            projectId,
            databaseId,
            dbKind as DatabaseRouteKind,
            collectionId,
          ),
        ),

        // Table details - blocks navigation until ready
        queryClient.ensureQueryData(
          tableQueryOptions(
            projectId,
            databaseId,
            dbKind as DatabaseRouteKind,
            collectionId,
          ),
        ),

        // Organization plan - CRITICAL for limit checking
        projectData?.teamId
          ? queryClient.ensureQueryData(
              organizationPlanQueryOptions(projectData.teamId),
            )
          : Promise.resolve(),

        // Prefetch indexes (optional data, not critical for rows tab) - doesn't block
        queryClient
          .prefetchQuery(
            tableIndexesQueryOptions(
              projectId,
              databaseId,
              dbKind as DatabaseRouteKind,
              collectionId,
            ),
          )
          .catch(() => {
            // Don't block on optional data errors
          }),
      ])
      const database = queryClient.getQueryData<{ name?: string }>(
        databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind).queryKey,
      )
      const collection = queryClient.getQueryData<{ name?: string }>(
        tableQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
          collectionId,
        ).queryKey,
      )
      return { database, collection }
    } else {
      await tablesPromise
    }
  },
  component: RowsPage,
})

function RowsPage() {
  const { databaseId, collectionId } = Route.useParams()

  return (
    <Workspace databaseId={databaseId} tableId={collectionId} activeTab="rows" />
  )
}
