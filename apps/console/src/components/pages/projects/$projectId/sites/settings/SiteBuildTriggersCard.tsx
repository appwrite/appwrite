import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { buildSiteUpdateParams } from '@/lib/react-query/hooks'
import { describeTriggerBehavior, normalizeTriggerPatterns } from '@/lib/git-build-triggers'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { BuildTriggersCard } from '../../shared/BuildTriggersCard'
import { useT } from '@/lib/i18n/translate'

const SITES_BUILD_TRIGGERS_DOCS =
  '/docs/products/sites/deploy-from-git#build-triggers'

interface SiteBuildTriggersCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site
}

export function SiteBuildTriggersCard({
  projectId,
  siteId,
  site,
}: SiteBuildTriggersCardProps) {
  const t = useT()
  const queryClient = useQueryClient()

  const updateSiteMutation = useMutation({
    mutationFn: async (updates: {
      providerBranches: string[]
      providerPaths: string[]
    }) => {
      if (!projectId || !siteId)
        throw new Error('Project ID and Site ID are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: (updated, variables) => {
      const summary = describeTriggerBehavior(
        variables.providerBranches,
        variables.providerPaths,
      )
      toast.success(`${t('Triggers updated.')} ${summary}`)
      queryClient.setQueryData(
        ['site', 'project', projectId, siteId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update triggers')))
    },
  })

  if (!projectId || !siteId) return null

  return (
    <BuildTriggersCard
      kind="site"
      resource={site}
      projectId={projectId}
      resourceId={siteId}
      docsLink={SITES_BUILD_TRIGGERS_DOCS}
      isSaving={updateSiteMutation.isPending}
      onSave={(updates) =>
        updateSiteMutation.mutate({
          providerBranches: normalizeTriggerPatterns(updates.providerBranches),
          providerPaths: normalizeTriggerPatterns(updates.providerPaths),
        })
      }
    />
  )
}
