import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'
import {
  useProject,
  useProjectVariables,
  useCreateProjectVariable,
  useUpdateProjectVariable,
  useDeleteProjectVariable,
} from '@/lib/react-query/hooks'

export function Variables() {
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const [page, setPage] = useState(0)
  const limit = 10

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
          title="Global variables"
          description="Set the environment variables or secret keys that will be passed to all Functions and Sites within your project."
          variables={variables}
          total={total}
          isLoading={isLoading}
          createMutation={createMutation}
          updateMutation={updateMutation}
          deleteMutation={deleteMutation}
          scopeLabel={`${project?.name || 'Project'} global`}
          page={page}
          limit={limit}
          onPageChange={setPage}
          itemLabel="variables"
        />
      </div>
    </div>
  )
}
