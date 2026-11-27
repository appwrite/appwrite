import { useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'
import {
  useCreateDistributionVariable,
  useDeleteDistributionVariable,
  useDistributionVariables,
  useProjectVariables,
  useUpdateDistributionVariable,
} from '@/lib/react-query/hooks'
import { SMALL_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, appId } = useParams({ strict: false })
  const [page, setPage] = useState(0)
  const [limit, setLimit] = useState(SMALL_PAGE_SIZE)
  const { variables, total, isLoading } = useDistributionVariables(
    projectId,
    appId,
    page,
    limit,
  )
  const { variables: projectVariables } = useProjectVariables(projectId)
  const createMutation = useCreateDistributionVariable(projectId, appId)
  const updateMutation = useUpdateDistributionVariable(projectId, appId)
  const deleteMutation = useDeleteDistributionVariable(projectId, appId)

  const globalVariableKeys = useMemo(
    () => new Set(projectVariables.map((variable) => variable.key)),
    [projectVariables],
  )

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 px-4 py-4 sm:px-6 sm:py-6">
      <VariablesSettingsCard
        title={t('Environment variables')}
        description={t(
          'Variables are passed to every build of this app. App variables override project variables with the same key. Keys cannot start with DISTRIBUTION_ or APPWRITE_.',
        )}
        variables={variables}
        total={total}
        isLoading={isLoading}
        createMutation={createMutation}
        updateMutation={updateMutation}
        deleteMutation={deleteMutation}
        scopeLabel={t('App')}
        page={page}
        limit={limit}
        onPageChange={setPage}
        onPageSizeChange={(nextLimit) => {
          setLimit(nextLimit)
          setPage(0)
        }}
        itemLabel={t('variables')}
        projectVariableKeysForWarning={globalVariableKeys}
        projectVariablesProjectId={projectId}
      />
    </div>
  )
}
