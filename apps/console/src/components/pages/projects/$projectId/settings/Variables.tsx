import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'
import { useT } from '@/lib/i18n/translate'
import {
  useProject,
  useProjectVariables,
  useCreateProjectVariable,
  useUpdateProjectVariable,
  useDeleteProjectVariable,
  SMALL_PAGE_SIZE,
} from '@/lib/react-query/hooks'

export function Variables() {
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const [page, setPage] = useState(0)
  const [limit, setLimit] = useState(SMALL_PAGE_SIZE)

  const { project } = useProject(projectId)
  const { variables, total, isLoading } = useProjectVariables(
    projectId,
    page,
    limit,
  )
  const createMutation = useCreateProjectVariable(projectId)
  const updateMutation = useUpdateProjectVariable(projectId)
  const deleteMutation = useDeleteProjectVariable(projectId)

  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6 sm:py-6">
      <div className="space-y-6">
        <VariablesSettingsCard
          title={t('Global variables')}
          description={t('Set the environment variables or secret keys that will be passed to all Functions and Sites within your project.')}
          variables={variables}
          total={total}
          isLoading={isLoading}
          createMutation={createMutation}
          updateMutation={updateMutation}
          deleteMutation={deleteMutation}
          scopeLabel={`${project?.name || t('Project')} ${t('global')}`}
          page={page}
          limit={limit}
          onPageChange={setPage}
          onPageSizeChange={(newLimit) => {
            setLimit(newLimit)
            setPage(0)
          }}
          itemLabel={t('variables')}
        />
      </div>
    </div>
  )
}
