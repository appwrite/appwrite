import { useCallback, useState } from 'react'
import { useNavigate, useParams, useSearch } from '@tanstack/react-router'
import {
  ApiExplorer,
  ApiExplorerDownloadSpecButton,
  ApiExplorerPlatformToggle,
} from '@/components/global/api-explorer'
import { ServiceHeader } from '../shared/ServiceHeader'
import { useProject } from '@/lib/react-query/hooks'
import { getProjectApiEndpoint } from '@/lib/appwrite/sdk'
import type { ApiExplorerProjectPlatform } from '@/lib/api-explorer'

export function View() {
  const { projectId } = useParams({ strict: false })
  const search = useSearch({ strict: false }) as {
    service?: string
    operation?: string
  }
  const navigate = useNavigate()
  useProject(projectId)
  const [searchValue, setSearchValue] = useState('')
  const [platform, setPlatform] = useState<ApiExplorerProjectPlatform>('server')

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
      <ServiceHeader
        title="Explorer"
        fullWidthBorder
        fullWidth
        searchPlaceholder="Search services and methods…"
        searchValue={searchValue}
        onSearchChange={setSearchValue}
        beforeRefreshButtons={
          <>
            <ApiExplorerDownloadSpecButton />
            <ApiExplorerPlatformToggle value={platform} onChange={setPlatform} />
          </>
        }
        showToolbarBottomBorder
      />
      <ApiExplorer
        config={{
          endpoint,
          projectId,
          platform: 'server',
        }}
        initialServiceId={search.service}
        initialOperationId={search.operation}
        onSelectionChange={handleSelectionChange}
        searchValue={searchValue}
        onSearchChange={setSearchValue}
        platform={platform}
        onPlatformChange={setPlatform}
        hideToolbar
        className="min-h-0 flex-1"
      />
    </div>
  )
}
