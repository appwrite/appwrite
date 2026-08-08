import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { buildFunctionUpdateParams } from '@/lib/react-query/hooks'
import { getDeploymentRetention } from '@/lib/deployment-retention'
import { DeploymentRetentionCard } from '../../shared/DeploymentRetentionCard'
import { useT } from '@/lib/i18n/translate'

interface FunctionDeploymentRetentionCardProps {
  projectId: string | null | undefined
  functionId: string | null | undefined
  func: Models.Function | null | undefined
}

export function FunctionDeploymentRetentionCard({
  projectId,
  functionId,
  func,
}: FunctionDeploymentRetentionCardProps) {
  const t = useT()
  const queryClient = useQueryClient()

  const updateFunctionMutation = useMutation({
    mutationFn: async (deploymentRetention: number) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, { deploymentRetention }),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Retention has been updated'))
      queryClient.setQueryData(
        ['function', 'project', projectId, functionId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update retention')))
    },
  })

  if (!func) return null

  return (
    <DeploymentRetentionCard
      deploymentRetention={getDeploymentRetention(func)}
      onUpdate={(deploymentRetention) =>
        updateFunctionMutation.mutate(deploymentRetention)
      }
      isPending={updateFunctionMutation.isPending}
    />
  )
}
