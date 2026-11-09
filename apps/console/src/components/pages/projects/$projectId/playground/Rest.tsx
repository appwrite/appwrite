import { useCallback } from 'react'
import { useNavigate, useParams, useSearch } from '@tanstack/react-router'
import { ApiExplorer } from '@/components/global/api-explorer'
import { useProject } from '@/lib/react-query/hooks'
import { getProjectApiEndpoint } from '@/lib/appwrite/sdk'

export function Rest() {
  const { projectId } = useParams({ strict: false })
  const search = useSearch({ strict: false }) as {
    service?: string
    operation?: string
  }
  const navigate = useNavigate()
  useProject(projectId)

  const handleSelectionChange = useCallback(
    ({
      serviceId,
      operationId,
    }: {
      serviceId: string
      operationId: string
    }) => {
      if (search.service === serviceId && search.operation === operationId) {
        return
      }
      if (!projectId) return
      navigate({
        to: '/projects/$projectId/playground',
        params: { projectId },
        search: { service: serviceId, operation: operationId },
        replace: true,
      })
    },
    [navigate, projectId, search.operation, search.service],
  )

  if (!projectId) return null

  const endpoint = getProjectApiEndpoint(projectId)

  return (
    <ApiExplorer
      config={{
        endpoint,
        projectId,
        platform: 'server',
      }}
      initialServiceId={search.service}
      initialOperationId={search.operation}
      onSelectionChange={handleSelectionChange}
      className="min-h-0 flex-1"
    />
  )
}
