/**
 * Site Variables Card Component
 *
 * Manages environment variables for a site, showing both site-specific and global project variables.
 * Uses shared VariablesSettingsCard with site-specific configuration.
 */

import {
  useSiteVariables,
  useCreateSiteVariable,
  useUpdateSiteVariable,
  useDeleteSiteVariable,
  useProjectVariables,
} from '@/lib/react-query/hooks'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'

interface SiteVariablesCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
}

export function SiteVariablesCard({
  projectId,
  siteId,
}: SiteVariablesCardProps) {
  const {
    variables: siteVariables,
    total,
    isLoading,
  } = useSiteVariables(projectId, siteId)

  const { data: projectVariablesData } = useProjectVariables(
    projectId,
    0,
    100,
  )

  const createMutation = useCreateSiteVariable(projectId, siteId)
  const updateMutation = useUpdateSiteVariable(projectId, siteId)
  const deleteMutation = useDeleteSiteVariable(projectId, siteId)

  const globalVariableKeys = new Set(
    (projectVariablesData?.variables || []).map((v) => v.key),
  )

  return (
    <VariablesSettingsCard
      title="Environment Variables"
      description="Configure environment variables for your site deployments. Site-specific variables override global project variables. Set the environment variables or secret keys that will be passed to this site during deployment."
          variables={siteVariables}
          total={total}
          isLoading={isLoading}
          createMutation={createMutation}
          updateMutation={updateMutation}
          deleteMutation={deleteMutation}
          scopeLabel="Site"
          isVariableEditable={(v) => !globalVariableKeys.has(v.key)}
      getVariableBadge={(v) =>
        globalVariableKeys.has(v.key) ? 'Global' : undefined
      }
    />
  )
}
