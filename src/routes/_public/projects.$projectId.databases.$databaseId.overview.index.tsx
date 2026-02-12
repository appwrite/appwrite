import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { useEffect } from 'react'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/overview/',
)({
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
