/**
 * Site Variables Card Component
 *
 * Manages environment variables for a site, showing both site-specific and global project variables.
 * Uses shared VariablesSettingsCard with site-specific configuration.
 */

import { useMemo, useState } from 'react'
import {
  useSiteVariables,
  useCreateSiteVariable,
  useUpdateSiteVariable,
  useDeleteSiteVariable,
  useProjectVariables,
} from '@/lib/react-query/hooks'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'
import { SMALL_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'

interface SiteVariablesCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
}

export function SiteVariablesCard({
  projectId,
  siteId,
}: SiteVariablesCardProps) {
  const t = useT()
  const [page, setPage] = useState(0)
  const [limit, setLimit] = useState(SMALL_PAGE_SIZE)
  const {
    variables: siteVariables,
    total,
    isLoading,
  } = useSiteVariables(projectId, siteId, page, limit)

  const { variables: projectVariablesList } = useProjectVariables(projectId)

  const createMutation = useCreateSiteVariable(projectId, siteId)
  const updateMutation = useUpdateSiteVariable(projectId, siteId)
  const deleteMutation = useDeleteSiteVariable(projectId, siteId)

  const globalVariableKeys = useMemo(
    () => new Set(projectVariablesList.map((v) => v.key)),
    [projectVariablesList],
  )

  return (
    <VariablesSettingsCard
      title={t('Environment variables')}
      description={t(
        'Configure environment variables for your site deployments. Site-specific variables override global project variables. Set the environment variables or secret keys that will be passed to this site during deployment.',
      )}
      variables={siteVariables}
      total={total}
      isLoading={isLoading}
      createMutation={createMutation}
      updateMutation={updateMutation}
      deleteMutation={deleteMutation}
      scopeLabel={t('Site')}
      page={page}
      limit={limit}
      onPageChange={setPage}
      onPageSizeChange={(newLimit) => {
        setLimit(newLimit)
        setPage(0)
      }}
      itemLabel={t('variables')}
      isVariableEditable={(v) => !globalVariableKeys.has(v.key)}
      getVariableBadge={(v) =>
        globalVariableKeys.has(v.key) ? t('Global') : undefined
      }
      projectVariableKeysForWarning={globalVariableKeys}
      projectVariablesProjectId={projectId}
    />
  )
}
