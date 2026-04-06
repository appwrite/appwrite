import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { pageTitle } from '@/lib/utils/page-title'
import { dbNavLink, type DatabaseRouteKind } from '@/lib/database-routes'

const tableSearchSchema = z.object({
  tab: z
    .enum(['rows', 'columns', 'indexes', 'security', 'settings'])
    .default('rows'),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/$tableId',
)({
  head: () => ({ meta: [{ title: pageTitle('Database', 'Databases') }] }),
  validateSearch: tableSearchSchema,
  loader: ({ params, search }) => {
    const { projectId, dbKind, databaseId, tableId } = params
    const kind = dbKind as DatabaseRouteKind
    const nav = dbNavLink(kind)
    const p = {
      projectId,
      dbKind: kind,
      databaseId,
      resourceId: tableId,
    }
    const tab = search.tab
    if (tab === 'rows') {
      throw redirect({ ...nav.dataGrid(p) })
    }
    if (tab === 'columns') {
      throw redirect({ ...nav.columns(p) })
    }
    if (tab === 'indexes') {
      throw redirect({ ...nav.indexes(p) })
    }
    if (tab === 'security') {
      throw redirect({ ...nav.security(p) })
    }
    throw redirect({ ...nav.settings(p) })
  },
})
