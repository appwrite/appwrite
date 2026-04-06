import { createFileRoute, redirect } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  tablesQueryOptions,
  databaseQueryOptions,
  tableRowsQueryOptions,
  tableQueryOptions,
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
import { throwRedirectTablesDbFromCollectionsChild } from '@/lib/database-route-redirects'

const TABLES_PER_PAGE = 100
const ROWS_PER_PAGE = 25
const DEFAULT_PAGE = 1

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/json',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.database?.name ?? 'Database', 'Databases'),
      },
    ],
  }),
  pendingComponent: () => (
    <div className="flex h-full items-center justify-center">
      <div className="text-muted-foreground">Loading documents...</div>
    </div>
  ),
  validateSearch: listSearchSchema,
  loader: async ({ params, context, location }) => {
    if (typeof window === 'undefined') return

    const { projectId, dbKind, databaseId, collectionId } = params

    if (dbKind === 'vectorsdb') {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
        params: { projectId, dbKind, databaseId, collectionId },
        replace: true,
      })
    }

    throwRedirectTablesDbFromCollectionsChild(dbKind, 'dataJson', {
      projectId,
      dbKind,
      databaseId,
      collectionId,
    })
    const { queryClient } = context

    if (!projectId || !databaseId) return

    const projectData = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )

    const tablesPromise = queryClient.ensureQueryData(
      tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE, undefined),
    )

    if (collectionId === '-') {
      const tablesData = await tablesPromise
      const sortedTables = [...(tablesData.tables || [])].sort((a, b) => {
        const nameA = a.name?.toLowerCase() || ''
        const nameB = b.name?.toLowerCase() || ''
        return nameA.localeCompare(nameB)
      })
      const firstTable = sortedTables[0]

      if (firstTable?.$id) {
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/json',
          params: { projectId, dbKind, databaseId, collectionId: firstTable.$id },
          replace: true,
        })
      }
      await queryClient.ensureQueryData(
        databaseQueryOptions(projectId, databaseId),
      )
      const database = queryClient.getQueryData<{ name?: string }>(
        databaseQueryOptions(projectId, databaseId).queryKey,
      )
      return { database }
    }

    if (collectionId) {
      const tablesData = await tablesPromise

      if (!tablesData.tables || tablesData.tables.length === 0) {
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
          params: { projectId, dbKind, databaseId, collectionId: '-' },
          replace: true,
        })
      }

      const tableExists = tablesData.tables.some(
        (table: unknown) => (table as { $id?: string }).$id === collectionId,
      )
      if (!tableExists) {
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
          params: { projectId, dbKind, databaseId, collectionId: '-' },
          replace: true,
        })
      }
      const url = new URL(
        location.pathname + location.search,
        'http://localhost',
      )
      const search = getSearch(url)
      const page = getPage(url, DEFAULT_PAGE)
      const limit = getLimit(url, ROWS_PER_PAGE)
      const queryParam = getQueryParam(url)
      const filterMap = queryParamToMap(queryParam)
      const filterQueries =
        filterMap.size > 0 ? Array.from(filterMap.values()) : undefined

      await Promise.all([
        tablesPromise,
        queryClient.ensureQueryData(
          tableRowsQueryOptions(
            projectId,
            databaseId,
            collectionId,
            page - 1,
            limit,
            search ?? undefined,
            'desc',
            '$createdAt',
            filterQueries,
          ),
        ),
        queryClient.ensureQueryData(
          databaseQueryOptions(projectId, databaseId),
        ),
        queryClient.ensureQueryData(
          tableQueryOptions(projectId, databaseId, collectionId),
        ),
        projectData?.teamId
          ? queryClient.ensureQueryData(
              organizationPlanQueryOptions(projectData.teamId),
            )
          : Promise.resolve(),
      ])
      const database = queryClient.getQueryData<{ name?: string }>(
        databaseQueryOptions(projectId, databaseId).queryKey,
      )
      return { database }
    } else {
      await tablesPromise
    }
  },
  component: DocumentsPage,
})

function DocumentsPage() {
  const { databaseId, collectionId } = Route.useParams()

  return (
    <TableView databaseId={databaseId} tableId={collectionId} activeTab="documents" />
  )
}
