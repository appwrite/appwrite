import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import { Button } from '@/components/ui/button'
import {
  buildFunctionUpdateParams,
  useFunctionFrameworks,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cacheUpdatedFunctionOrSite } from '@/lib/utils/settings-redeploy-alert'
import { useT } from '@/lib/i18n/translate'
import {
  FrameworkSelect,
  NO_FRAMEWORK,
  frameworkParam,
} from '../create/_components/FrameworkSelect'

interface FunctionFrameworkCardProps {
  projectId: string | null | undefined
  functionId: string | null | undefined
  func: Models.Function
}

/**
 * The web framework the function runs (Express, …), or none. Takes effect on the
 * next deployment, like the other build settings.
 */
export function FunctionFrameworkCard({
  projectId,
  functionId,
  func,
}: FunctionFrameworkCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { frameworks } = useFunctionFrameworks(projectId)
  const current = func.framework || NO_FRAMEWORK
  const [framework, setFramework] = useSyncStateFromServer(current)

  // Only frameworks that run on the function's runtime, plus the one it has.
  const choices = frameworks.filter(
    (f) => f.runtimes.includes(func.runtime) || f.key === func.framework,
  )

  const updateMutation = useMutation({
    mutationFn: async (value: string) => {
      if (!projectId || !functionId)
        throw new Error('Project ID and Function ID are required')
      // Update is a PUT: send the function's current settings with the framework,
      // or a Git connection and other settings would be reset.
      return await sdk
        .forProject(projectId)
        .functions.update(
          buildFunctionUpdateParams(func, { framework: frameworkParam(value) }),
        )
    },
    onSuccess: (updated) => {
      toast.success(t('Framework updated. Redeploy to apply it.'))
      cacheUpdatedFunctionOrSite(
        queryClient,
        ['function', 'project', projectId, functionId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update framework')))
    },
  })

  if (frameworks.length === 0) return null

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Framework')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Run an existing web app, such as an Express server, as this function. Changes apply on the next deployment.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 max-w-sm">
        {choices.length > 0 ? (
          <FrameworkSelect
            frameworks={choices}
            value={framework}
            onChange={setFramework}
          />
        ) : (
          <p className="text-[13px] text-muted-foreground">
            {t('No framework runs on this runtime yet.')}
          </p>
        )}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={framework === current || updateMutation.isPending}
          onClick={() => updateMutation.mutate(framework)}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
