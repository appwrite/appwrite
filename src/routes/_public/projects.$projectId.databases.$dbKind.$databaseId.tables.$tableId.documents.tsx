import { type DatabaseRouteKind } from '@/lib/database-routes'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { Workspace } from '@/components/pages/projects/$projectId/databases/View'
import {
  tablesQueryOptions,
  databaseQueryOptions,
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
import { throwRedirectCollectionsDbFromTablesChild } from '@/lib/database-route-redirects'

const TABLES_PER_PAGE = 100
const DEFAULT_PAGE = 1

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/documents',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          (
            loaderData as
              | {
                  database?: { name?: string }
                  table?: { name?: string }
                }
              | undefined
          )?.table?.name ??
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

    const { projectId, dbKind, databaseId, tableId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    throwRedirectCollectionsDbFromTablesChild(dbKind, 'dataJson', {
      projectId,
      dbKind,
      databaseId,
      tableId,
    })

    const projectData = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )

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

    if (tableId === '-') {
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
          to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/documents',
          params: { projectId, dbKind, databaseId, tableId: firstTable.$id },
          replace: true,
        })
      }
      await queryClient.ensureQueryData(
        databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind),
      )
      const database = queryClient.getQueryData<{ name?: string }>(
        databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind).queryKey,
      )
      return { database, table: undefined }
    }

    if (tableId) {
      const tablesData = await tablesPromise

      if (!tablesData.tables || tablesData.tables.length === 0) {
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/documents',
          params: { projectId, dbKind, databaseId, tableId: '-' },
          replace: true,
        })
      }

      const tableExists = tablesData.tables.some(
        (table: unknown) => (table as { $id?: string }).$id === tableId,
      )
      if (!tableExists) {
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/documents',
          params: { projectId, dbKind, databaseId, tableId: '-' },
          replace: true,
        })
      }
      const { search, page, limit, filterQueries, sort } = parseListSearch(
        routeSearch,
        {
          page: DEFAULT_PAGE,
          limit: ROWS_DEFAULT_PAGE_SIZE,
        },
      )
      const sortBy = sort?.sortBy ?? '$createdAt'
      const sortOrder = sort?.sortOrder ?? 'desc'

      await Promise.all([
        tablesPromise,
        queryClient
          .ensureQueryData(
            tableRowsQueryOptions(
              projectId,
              databaseId,
              tableId,
              dbKind as DatabaseRouteKind,
              page - 1,
              limit,
              search ?? undefined,
              sortOrder,
              sortBy,
              filterQueries,
              undefined,
            ),
          )
          .catch(() => {
            // Keep the error in cache for the spreadsheet; don't blank the route.
          }),
        queryClient.ensureQueryData(
          databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind),
        ),
        queryClient.ensureQueryData(
          tableQueryOptions(
            projectId,
            databaseId,
            dbKind as DatabaseRouteKind,
            tableId,
          ),
        ),
        projectData?.teamId
          ? queryClient.ensureQueryData(
              organizationPlanQueryOptions(projectData.teamId),
            )
          : Promise.resolve(),
      ])
      const database = queryClient.getQueryData<{ name?: string }>(
        databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind).queryKey,
      )
      const table = queryClient.getQueryData<{ name?: string }>(
        tableQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
          tableId,
        ).queryKey,
      )
      return { database, table }
    } else {
      await tablesPromise
    }
  },
  component: DocumentsPage,
})

function DocumentsPage() {
  const { databaseId, tableId } = Route.useParams()

  return (
    <Workspace databaseId={databaseId} tableId={tableId} activeTab="documents" />
  )
}
