import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { useEffect } from 'react'
import { pageTitle } from '@/lib/utils/page-title'
import { dbNavLink, type DatabaseRouteKind } from '@/lib/database-routes'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/overview/',
)({
  head: () => ({ meta: [{ title: pageTitle('Database', 'Databases') }] }),
  component: OverviewIndexRedirect,
})

// Redirect to tables tab when accessing overview without specific tab
function OverviewIndexRedirect() {
  const { projectId, dbKind, databaseId } = Route.useParams()
  const navigate = useNavigate()

  useEffect(() => {
    const nav = dbNavLink(dbKind as DatabaseRouteKind)
    navigate({
      ...nav.dataGrid({
        projectId,
        dbKind: dbKind as DatabaseRouteKind,
        databaseId,
        resourceId: '-',
      }),
      replace: true,
    })
  }, [navigate, projectId, dbKind, databaseId])

  return null
}
