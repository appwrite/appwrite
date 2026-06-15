import { CopyableId } from '@/components/global/shared/CopyableId'
import { truncateMiddle } from '@/lib/utils'
import {
  getProjectListItemEndpoint,
  type ProjectListItem,
} from '@/lib/react-query/hooks/projects'

export const PROJECT_LIST_ENDPOINT_DISPLAY_MAX = 28

export function getProjectListEndpointDisplay(endpoint: string): string {
  const withoutProtocol = endpoint.replace(/^https?:\/\//, '')
  return truncateMiddle(withoutProtocol, PROJECT_LIST_ENDPOINT_DISPLAY_MAX)
}

export function ProjectListIdentities({ project }: { project: ProjectListItem }) {
  const endpoint = getProjectListItemEndpoint(project)

  return (
    <div className="flex min-w-0 flex-wrap items-center gap-1.5">
      <CopyableId
        id={endpoint}
        displayText={getProjectListEndpointDisplay(endpoint)}
        size="md"
        copyToastLabel="Endpoint"
        className="w-fit max-w-full"
      />
      <CopyableId
        id={project.$id}
        size="md"
        maxWidth={140}
        copyToastLabel="Project ID"
        className="w-fit max-w-full"
      />
    </div>
  )
}
