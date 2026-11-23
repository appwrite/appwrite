import { useParams } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { buildFunctionUpdateParams } from '@/lib/react-query/hooks'
import { describeTriggerBehavior, normalizeTriggerPatterns } from '@/lib/git-build-triggers'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { BuildTriggersCard } from '../shared/BuildTriggersCard'
import { useT } from '@/lib/i18n/translate'

const FUNCTIONS_BUILD_TRIGGERS_DOCS =
  '/docs/products/functions/deploy-from-git#build-triggers'

interface FunctionBuildTriggersCardProps {
  func: Models.Function
}

export function FunctionBuildTriggersCard({
  func,
}: FunctionBuildTriggersCardProps) {
  const t = useT()
  const { projectId, functionId } = useParams({ strict: false })
  const queryClient = useQueryClient()

  const updateFunctionMutation = useMutation({
    mutationFn: async (updates: {
      providerBranches: string[]
      providerPaths: string[]
    }) => {
      if (!projectId || !func.$id)
        throw new Error('Project ID and Function ID are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, updates),
      )
    },
    onSuccess: (updated, variables) => {
      const summary = describeTriggerBehavior(
        variables.providerBranches,
        variables.providerPaths,
      )
      toast.success(`${t('Triggers updated.')} ${summary}`)
      queryClient.setQueryData(
        ['function', 'project', projectId, func.$id],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update triggers')))
    },
  })

  if (!projectId || !functionId) return null

  return (
    <BuildTriggersCard
      kind="function"
      resource={func}
      projectId={projectId}
      resourceId={functionId}
      docsLink={FUNCTIONS_BUILD_TRIGGERS_DOCS}
      isSaving={updateFunctionMutation.isPending}
      onSave={(updates) =>
        updateFunctionMutation.mutate({
          providerBranches: normalizeTriggerPatterns(updates.providerBranches),
          providerPaths: normalizeTriggerPatterns(updates.providerPaths),
        })
      }
    />
  )
}
