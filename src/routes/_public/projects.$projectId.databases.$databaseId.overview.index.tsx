import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { useEffect } from 'react'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/overview/',
)({
  head: () => ({ meta: [{ title: pageTitle('Database', 'Databases') }] }),
  component: DatabaseOverviewIndexRedirect,
})

// Redirect to tables tab when accessing overview without specific tab
function DatabaseOverviewIndexRedirect() {
  const { projectId, databaseId } = Route.useParams()
  const navigate = useNavigate()

  useEffect(() => {
    navigate({
      to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
      params: { projectId, databaseId, tableId: '-' },
      replace: true,
    })
  }, [navigate, projectId, databaseId])

  return null
}
