import { useState, useMemo } from 'react'
import {
  useFunctionVariables,
  useCreateFunctionVariable,
  useUpdateFunctionVariable,
  useDeleteFunctionVariable,
  useProjectVariables,
} from '@/lib/react-query/hooks'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'
import { SMALL_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'

interface FunctionVariablesCardProps {
  projectId: string | null | undefined
  functionId: string | null | undefined
}

export function FunctionVariablesCard({
  projectId,
  functionId,
}: FunctionVariablesCardProps) {
  const t = useT()
  const [page, setPage] = useState(0)
  const [limit, setLimit] = useState(SMALL_PAGE_SIZE)

  const { variables, total, isLoading } = useFunctionVariables(
    projectId,
    functionId,
    page,
    limit,
  )

  const { variables: projectVariablesList } = useProjectVariables(projectId)
  const projectVariableKeysForWarning = useMemo(
    () => new Set(projectVariablesList.map((v) => v.key)),
    [projectVariablesList],
  )

  const createMutation = useCreateFunctionVariable(projectId, functionId)
  const updateMutation = useUpdateFunctionVariable(projectId, functionId)
  const deleteMutation = useDeleteFunctionVariable(projectId, functionId)

  return (
    <VariablesSettingsCard
      title={t('Variables')}
      description={t(
        'Configure environment variables for your function. Function-specific variables override global project variables. Set the environment variables or secret keys that will be passed to this function.',
      )}
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
      onPageSizeChange={(newLimit) => {
        setLimit(newLimit)
        setPage(0)
      }}
      itemLabel="variables"
      projectVariableKeysForWarning={projectVariableKeysForWarning}
      projectVariablesProjectId={projectId}
    />
  )
}
