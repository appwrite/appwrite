import { useCallback } from 'react'
import { useNavigate, useParams, useSearch } from '@tanstack/react-router'
import { ApiExplorer } from '@/components/global/api-explorer'
import { ServiceHeader } from '../shared/ServiceHeader'
import { useProject } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getProjectApiEndpoint } from '@/lib/appwrite/sdk'

export function View() {
  const t = useT()
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
      if (
        search.service === serviceId &&
        search.operation === operationId
      ) {
        return
      }
      if (!projectId) return
      navigate({
        to: '/projects/$projectId/explorer',
        params: { projectId },
        search: (prev) => ({
          ...prev,
          service: serviceId,
          operation: operationId,
        }),
        replace: true,
      })
    },
    [navigate, projectId, search.operation, search.service],
  )

  if (!projectId) return null

  const endpoint = getProjectApiEndpoint(projectId)

  return (
    <div className="flex h-full min-h-0 flex-col">
      <ServiceHeader title={t('Explorer')} fullWidthBorder fullWidth />
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
    </div>
  )
}
