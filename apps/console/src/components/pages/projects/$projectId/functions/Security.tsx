import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import {
  useProjectFunction,
  buildFunctionUpdateParams,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { PermissionsEditor } from '@/components/pages/projects/$projectId/auth/PermissionsEditor'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, functionId } = useParams({ strict: false })
  const queryClient = useQueryClient()

  const { data: func, isLoading: funcLoading } = useProjectFunction(
    projectId,
    functionId,
  )

  // Execute permissions live on the function's `execute` attribute (array of role names, e.g. ["any"], ["users"])
  const [execute, setExecute] = useSyncStateFromServer(func?.execute || [])

  // Update function mutation
  const updateFunctionMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, updates),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Function updated successfully'))
      queryClient.setQueryData(
        ['function', 'project', projectId, functionId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update function')))
    },
  })

  const handleSaveExecute = () => {
    updateFunctionMutation.mutate({ execute })
  }

  // Check if arrays are equal
  const arraysEqual = (a: string[], b: string[]) => {
    if (a.length !== b.length) return false
    return a.every((val, idx) => val === b[idx])
  }

  if (funcLoading && !func) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading security settings...')}
        </p>
      </div>
    )
  }

  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          {/* Permissions Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Permissions')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                {t('Choose who can execute this function')}
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <PermissionsEditor
                permissions={execute}
                onPermissionsChange={setExecute}
                projectId={projectId}
                executeOnly
              />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  arraysEqual(execute, func?.execute || []) ||
                  updateFunctionMutation.isPending
                }
                onClick={handleSaveExecute}
              >
                {t('Update')}
              </Button>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
