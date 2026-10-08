import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { buildSiteUpdateParams } from '@/lib/react-query/hooks'
import { getDeploymentRetention } from '@/lib/deployment-retention'
import { DeploymentRetentionCard } from '../../shared/DeploymentRetentionCard'
import { useT } from '@/lib/i18n/translate'

interface SiteDeploymentRetentionCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function SiteDeploymentRetentionCard({
  projectId,
  siteId,
  site,
}: SiteDeploymentRetentionCardProps) {
  const t = useT()
  const queryClient = useQueryClient()

  const updateSiteMutation = useMutation({
    mutationFn: async (deploymentRetention: number) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(
        buildSiteUpdateParams(site, { deploymentRetention }),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Retention has been updated'))
      queryClient.setQueryData(
        ['site', 'project', projectId, siteId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update retention')))
    },
  })

  if (!site) return null

  return (
    <DeploymentRetentionCard
      deploymentRetention={getDeploymentRetention(site)}
      onUpdate={(deploymentRetention) =>
        updateSiteMutation.mutate(deploymentRetention)
      }
      isPending={updateSiteMutation.isPending}
    />
  )
}
