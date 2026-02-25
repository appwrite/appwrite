import { useState } from 'react'
import {
  useFunctionVariables,
  useCreateFunctionVariable,
  useUpdateFunctionVariable,
  useDeleteFunctionVariable,
} from '@/lib/react-query/hooks'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'
import { SMALL_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

interface FunctionVariablesCardProps {
  projectId: string | null | undefined
  functionId: string | null | undefined
}

export function FunctionVariablesCard({
  projectId,
  functionId,
}: FunctionVariablesCardProps) {
  const [page, setPage] = useState(0)
  const limit = SMALL_PAGE_SIZE

  const { variables, total, isLoading } = useFunctionVariables(
    projectId,
    functionId,
    page,
    limit,
  )

  const createMutation = useCreateFunctionVariable(projectId, functionId)
  const updateMutation = useUpdateFunctionVariable(projectId, functionId)
  const deleteMutation = useDeleteFunctionVariable(projectId, functionId)

  return (
    <VariablesSettingsCard
      title="Variables"
      description="Set the environment variables or secret keys that will be passed to this function."
      variables={variables}
      total={total}
      isLoading={isLoading}
      createMutation={createMutation}
      updateMutation={updateMutation}
      deleteMutation={deleteMutation}
      scopeLabel="Function"
      page={page}
      limit={limit}
      onPageChange={setPage}
      itemLabel="variables"
    />
  )
}
