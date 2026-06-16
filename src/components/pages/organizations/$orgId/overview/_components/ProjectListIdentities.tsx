import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  getProjectListItemEndpoint,
  type ProjectListItem,
} from '@/lib/react-query/hooks/projects'

export function ProjectListIdentities({ project }: { project: ProjectListItem }) {
  const endpoint = getProjectListItemEndpoint(project)

  return (
    <div className="flex min-w-0 flex-wrap items-center gap-1.5">
      <CopyableId
        id={endpoint}
        copyLabel="Copy endpoint"
        size="md"
        copyToastLabel="Endpoint"
        className="w-fit"
      />
      <CopyableId
        id={project.$id}
        copyLabel="Copy ID"
        size="md"
        copyToastLabel="ID"
        className="w-fit"
      />
    </div>
  )
}
