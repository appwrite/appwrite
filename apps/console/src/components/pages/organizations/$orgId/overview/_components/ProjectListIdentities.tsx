import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  getProjectListItemEndpoint,
  type ProjectListItem,
} from '@/lib/react-query/hooks/projects'
import { useT } from '@/lib/i18n/translate'

export function ProjectListIdentities({ project }: { project: ProjectListItem }) {
  const t = useT()
  const endpoint = getProjectListItemEndpoint(project)

  return (
    <div className="flex min-w-0 flex-wrap items-center gap-1.5">
      <CopyableId
        id={endpoint}
        copyLabel={t('Copy endpoint')}
        size="md"
        copyToastLabel="Endpoint"
        className="w-fit"
      />
      <CopyableId
        id={project.$id}
        copyLabel={t('Copy ID')}
        size="md"
        copyToastLabel="ID"
        className="w-fit"
      />
    </div>
  )
}
